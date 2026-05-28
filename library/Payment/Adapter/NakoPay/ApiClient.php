<?php
/**
 * NakoPay API Client for FOSSBilling
 *
 * Centralised HTTP client with dual base URL support.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace NakoPay;

class ApiClient
{
    /** Branded primary - api.nakopay.com proxy to edge functions */
    private const PRIMARY_BASE = 'https://api.nakopay.com/v1/';

    /** Origin fallback - Supabase edge functions */
    private const FALLBACK_BASE = 'https://daslrxpkbkqrbnjwouiq.supabase.co/functions/v1/';

    private string $apiKey;
    private string $baseUrl;

    public function __construct(string $apiKey, string $baseUrl = '')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = $this->resolveBase($baseUrl);
    }

    /**
     * Create a NakoPay invoice and return the API response.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createInvoice(array $payload): array
    {
        return $this->request('POST', 'invoices-create', $payload);
    }

    // --- Internal -----------------------------------------------------------

    private function resolveBase(string $adminSetting): string
    {
        // 1. Admin override (advanced field)
        if ($adminSetting !== '') {
            return rtrim($adminSetting, '/') . '/';
        }

        // 2. Host-defined constant
        if (defined('NAKOPAY_API_BASE')) {
            return rtrim((string) NAKOPAY_API_BASE, '/') . '/';
        }

        // 3. Primary (api.nakopay.com)
        return self::PRIMARY_BASE;
    }

    /**
     * @param  array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $endpoint, array $body = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('NakoPay: failed to init curl.');
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: NakoPay-FOSSBilling/' . \Payment_Adapter_NakoPay::VERSION,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('NakoPay API error: ' . $error);
        }

        $decoded = json_decode((string) $response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $decoded['error'] ?? $decoded['message'] ?? 'Unknown error';
            throw new \RuntimeException("NakoPay API ({$httpCode}): {$msg}");
        }

        return $decoded ?? [];
    }
}
