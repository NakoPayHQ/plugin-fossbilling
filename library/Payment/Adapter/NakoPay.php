<?php
/**
 * NakoPay Payment Gateway Adapter for FOSSBilling
 *
 * Accepts Bitcoin and crypto payments via NakoPay's hosted checkout.
 * Webhooks reconcile invoice status automatically.
 *
 * @license MIT
 * @see     https://nakopay.com/docs/integrations/fossbilling
 */

declare(strict_types=1);

require_once __DIR__ . '/NakoPay/ApiClient.php';
require_once __DIR__ . '/NakoPay/WebhookVerifier.php';
require_once __DIR__ . '/NakoPay/StatusMapper.php';

class Payment_Adapter_NakoPay extends Payment_AdapterAbstract
{
    public const VERSION = '1.0.0';

    public function init(): void
    {
        if (empty($this->config['api_key'])) {
            throw new Payment_Exception('NakoPay API key is not configured.');
        }
        if (empty($this->config['webhook_secret'])) {
            throw new Payment_Exception('NakoPay webhook secret is not configured.');
        }
    }

    // --- Gateway metadata ---------------------------------------------------

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'can_load_in_iframe'         => false,
            'description'                => 'Accept Bitcoin and crypto payments through NakoPay. '
                . 'Customers are redirected to a secure NakoPay checkout page and '
                . 'invoices are automatically marked paid after on-chain confirmation.',
            'logo' => [
                'logo'   => 'NakoPay/logo.png',
                'height' => '46px',
                'width'  => '59px',
            ],
            'form' => [
                'api_key' => [
                    'password', [
                        'label'       => 'API Key (sk_test_* or sk_live_*)',
                        'description' => 'Found at nakopay.com/dashboard/api-keys.',
                        'required'    => true,
                    ],
                ],
                'webhook_secret' => [
                    'password', [
                        'label'       => 'Webhook Secret (whsec_*)',
                        'description' => 'Found at nakopay.com/dashboard/webhooks.',
                        'required'    => true,
                    ],
                ],
                'api_url' => [
                    'text', [
                        'label'       => 'API Base URL (advanced - leave blank)',
                        'required'    => false,
                    ],
                ],
                'sandbox' => [
                    'select', [
                        'label'       => 'Sandbox Mode',
                        'multiOptions' => [
                            'no'  => 'No (live)',
                            'yes' => 'Yes (sandbox/test)',
                        ],
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    // --- Create checkout (redirect to NakoPay hosted page) ------------------

    /**
     * Build the HTML that redirects the customer to NakoPay checkout.
     *
     * @param \Box_Mod_Api_Admin $api_admin  Admin API
     * @param int                $invoice_id FOSSBilling invoice ID
     * @param bool               $subscription Whether this is a subscription (not supported)
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $invoice  = $api_admin->invoice_get(['id' => $invoice_id]);
        $client   = $this->makeApiClient();

        // Build description from invoice lines
        $lines = $invoice['lines'] ?? [];
        $descParts = [];
        foreach ($lines as $line) {
            $descParts[] = $line['title'] ?? 'Item';
        }
        $description = !empty($descParts)
            ? implode(', ', $descParts)
            : 'FOSSBilling Invoice #' . $invoice_id;

        $payload = [
            'amount'       => (string) ($invoice['total'] ?? '0'),
            'currency'     => strtoupper($invoice['currency'] ?? 'USD'),
            'description'  => $description,
            'metadata'     => [
                'fossbilling_invoice_id' => (string) $invoice_id,
            ],
            'redirect_url' => $this->config['return_url'] ?? '',
        ];

        if (!empty($invoice['buyer']['email'])) {
            $payload['customer_email'] = $invoice['buyer']['email'];
        }

        $result = $client->createInvoice($payload);

        if (empty($result['checkout_url'])) {
            throw new Payment_Exception('NakoPay: no checkout_url returned from API.');
        }

        $url = htmlspecialchars($result['checkout_url'], ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <script type="text/javascript">window.location.href = "{$url}";</script>
        <a href="{$url}" class="btn btn-primary">Pay with Bitcoin (NakoPay)</a>
        HTML;
    }

    // --- IPN callback identifier --------------------------------------------

    /**
     * Determine the invoice ID from the IPN callback data.
     */
    public function getInvoiceId($data): ?int
    {
        $rawBody = $data['http_raw_post_data'] ?? '';
        $event = json_decode($rawBody, true);
        if (!$event || empty($event['data']['metadata']['fossbilling_invoice_id'])) {
            return null;
        }
        return (int) $event['data']['metadata']['fossbilling_invoice_id'];
    }

    // --- Process webhook (IPN) ----------------------------------------------

    /**
     * Called by FOSSBilling when /ipn/NakoPay receives a POST.
     *
     * @param \Box_Mod_Api_Admin $api_admin  Admin API
     * @param int                $id         Transaction ID
     * @param array              $data       IPN data (get, post, http_raw_post_data)
     * @param int                $gateway_id Payment gateway ID
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        // 1. Verify webhook signature
        $rawBody   = $data['http_raw_post_data'] ?? '';
        $signature = $_SERVER['HTTP_X_NAKOPAY_SIGNATURE'] ?? '';
        $timestamp = $_SERVER['HTTP_X_NAKOPAY_TIMESTAMP'] ?? '';
        $secret    = $this->config['webhook_secret'] ?? '';

        $verifier = new \NakoPay\WebhookVerifier($secret);

        if (!$verifier->verify($rawBody, $signature, $timestamp)) {
            error_log('NakoPay webhook: signature verification failed.');
            http_response_code(401);
            return;
        }

        // 2. Parse event payload
        $event = json_decode($rawBody, true);
        if (!$event || empty($event['type'])) {
            error_log('NakoPay webhook: invalid or empty payload.');
            http_response_code(400);
            return;
        }

        // 3. Map NakoPay event to FOSSBilling action
        $mapper = new \NakoPay\StatusMapper();
        $status = $mapper->mapEvent($event['type']);

        if ($status === null) {
            // Unrecognised event type - acknowledge but do nothing
            return;
        }

        $invoiceData   = $event['data'] ?? [];
        $nakoInvoiceId = $invoiceData['id'] ?? '';
        $amount        = $invoiceData['amount_paid'] ?? $invoiceData['amount'] ?? '0';

        // 4. Update transaction via admin API
        $txData = [
            'id'        => $id,
            'txn_id'    => $nakoInvoiceId,
            'txn_status' => $status,
            'amount'    => $amount,
            'currency'  => $invoiceData['currency'] ?? 'BTC',
            'type'      => 'payment',
            'gateway_id' => $gateway_id,
        ];

        if (($this->config['sandbox'] ?? 'no') === 'yes') {
            $txData['note'] = 'NakoPay sandbox/test transaction';
        }

        $api_admin->invoice_transaction_update($txData);

        // 5. If paid, process the invoice
        if ($status === 'processed') {
            $invoiceId = $invoiceData['metadata']['fossbilling_invoice_id'] ?? null;
            if ($invoiceId) {
                try {
                    $api_admin->invoice_batch_pay_with_credits(['ids' => [$invoiceId]]);
                } catch (\Exception $e) {
                    // Mark as paid even if credits method fails
                    $api_admin->invoice_mark_as_paid(['id' => $invoiceId]);
                }
            }
        }
    }

    // --- Helpers -------------------------------------------------------------

    private function makeApiClient(): \NakoPay\ApiClient
    {
        $apiKey  = $this->config['api_key'] ?? '';
        $baseUrl = $this->config['api_url'] ?? '';

        return new \NakoPay\ApiClient($apiKey, $baseUrl);
    }
}
