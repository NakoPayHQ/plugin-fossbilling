# NakoPay for FOSSBilling

Accept Bitcoin and other crypto on your FOSSBilling install through [NakoPay](https://nakopay.com).

- developer-first REST API: invoices created server-side, polled and webhook-notified.
- Signed webhooks (HMAC-SHA256, 5-minute replay window).
- Sandbox/test mode for safe development.
- Direct-to-wallet settlement - 1% flat fee.

## Requirements

- FOSSBilling v0.6+
- PHP 8.1+
- PHP `curl` extension
- HTTPS in production
- A NakoPay account (free) - <https://nakopay.com/dashboard/api-keys>

## Download

| # | Source | When to use |
|---|--------|-------------|
| 1 | **GitHub Releases zip** - <https://github.com/NakoPayHQ/plugin-fossbilling/releases/latest/download/nakopay-fossbilling.zip> | Download `nakopay-fossbilling.zip`. |
| 2 | **Build from source** | Clone this repo and copy the files manually. |

## Install

1. Download `nakopay-fossbilling.zip` and unzip it on your computer.

2. Copy the contents into your FOSSBilling installation so the paths look like:

   ```
   library/Payment/Adapter/NakoPay.php
   library/Payment/Adapter/NakoPay/
       ApiClient.php
       WebhookVerifier.php
       StatusMapper.php
   ```

   The adapter extends FOSSBilling's built-in `Payment_AdapterAbstract` - no other dependencies are needed.

3. In the FOSSBilling admin, go to **System - Payment Gateways**, find **NakoPay**, and click **Install**.

4. Enter your credentials:
   - **API Key** - your `sk_test_*` or `sk_live_*` key from nakopay.com/dashboard/api-keys
   - **Webhook Secret** - the `whsec_*` value from nakopay.com/dashboard/webhooks

5. Set up the webhook in your NakoPay dashboard:
   - Go to nakopay.com/dashboard/webhooks
   - Add endpoint: `https://YOUR-DOMAIN/ipn/NakoPay`
   - Subscribe to: `invoice.paid`, `invoice.expired`, `invoice.refunded`

6. Run a test order with `sk_test_*` keys before switching to `sk_live_*`.

## Configuration

| Setting | Description |
|---------|-------------|
| API Key | Your NakoPay secret key (`sk_test_*` or `sk_live_*`) |
| Webhook Secret | Used to verify incoming webhook signatures (`whsec_*`) |
| Sandbox Mode | Enable to test without real funds |
| API Base URL | Advanced - leave blank unless instructed to change |

## Webhook Setup

Your webhook endpoint:

```
https://YOUR-DOMAIN/ipn/NakoPay
```

FOSSBilling routes IPN callbacks automatically. Make sure the URL is reachable over HTTPS.

### Supported events

- `invoice.paid` - marks FOSSBilling invoice as paid
- `invoice.expired` - marks invoice as cancelled
- `invoice.refunded` - triggers refund flow

## How it works

1. Customer clicks "Pay" on a FOSSBilling invoice.
2. The adapter calls the NakoPay `invoices-create` endpoint with the invoice amount, currency, description, and customer email.
3. NakoPay returns a `checkout_url` - the customer is redirected there to pay in Bitcoin.
4. After payment, NakoPay sends a signed webhook to `/ipn/NakoPay`.
5. The adapter verifies the HMAC-SHA256 signature, maps the event type (`invoice.paid`, `invoice.expired`, `invoice.refunded`) to a FOSSBilling transaction status, and updates the invoice.

## Testing

1. Use `sk_test_*` keys - test invoices are free and never settle on-chain.
2. Check the FOSSBilling admin Activity Log while paying to see the webhook fire.
3. Resend webhooks from nakopay.com/dashboard/webhooks if needed.

## Troubleshooting

**Invoice stays unpaid after customer pays:**
- Check that the webhook URL is correct and reachable over HTTPS.
- Verify the webhook secret matches what's in your NakoPay dashboard.
- Check FOSSBilling error logs for signature verification failures.

**"Gateway not found" error:**
- Ensure `NakoPay.php` is in `library/Payment/Adapter/` (case-sensitive).
- Clear FOSSBilling cache if needed.

**Webhook signature mismatch:**
- Re-copy the `whsec_*` value from nakopay.com/dashboard/webhooks.
- Make sure your server clock is accurate (NTP enabled) - signatures expire after 5 minutes.

**API returns 400 "description is required":**
- Update to the latest version of this plugin (v1.0.0+). Older pre-release versions did not send the required `description` field.

## Support

- Issues: <https://github.com/NakoPayHQ/plugin-fossbilling/issues>
- Email: [support@nakopay.com](mailto:support@nakopay.com)
- Website: <https://nakopay.com>

## About FOSSBilling

[FOSSBilling](https://fossbilling.org/) - free and open-source billing and client management software. Visit their website to learn more about the platform and its features.

## License

MIT - see [LICENSE](LICENSE).
