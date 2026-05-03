<?php
/**
 * NakoPay Webhook Signature Verifier for FOSSBilling
 *
 * Constant-time HMAC-SHA256 verification with replay protection.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace NakoPay;

class WebhookVerifier
{
    /** Maximum age of a webhook signature in seconds */
    private const TOLERANCE_SECONDS = 300; // 5 minutes

    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    /**
     * Verify a webhook request signature.
     *
     * @param string $rawBody   Raw request body (JSON string)
     * @param string $signature Value of X-NakoPay-Signature header
     * @param string $timestamp Value of X-NakoPay-Timestamp header (unix seconds)
     */
    public function verify(string $rawBody, string $signature, string $timestamp): bool
    {
        if ($signature === '' || $timestamp === '' || $rawBody === '') {
            return false;
        }

        // Replay protection
        $ts = (int) $timestamp;
        if (abs(time() - $ts) > self::TOLERANCE_SECONDS) {
            return false;
        }

        // Build the signed payload: "timestamp.body"
        $signedPayload = $timestamp . '.' . $rawBody;
        $expected = hash_hmac('sha256', $signedPayload, $this->secret);

        // Constant-time comparison
        return hash_equals($expected, $signature);
    }
}
