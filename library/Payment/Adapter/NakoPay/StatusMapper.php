<?php
/**
 * NakoPay to FOSSBilling Status Mapper
 *
 * Maps NakoPay webhook event types to FOSSBilling transaction statuses.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace NakoPay;

class StatusMapper
{
    /**
     * Map of NakoPay event types to FOSSBilling Model_Transaction statuses.
     *
     * FOSSBilling statuses: processed, pending, error, refunded, void
     */
    private const EVENT_MAP = [
        'invoice.paid'     => 'processed',
        'invoice.expired'  => 'void',
        'invoice.refunded' => 'refunded',
    ];

    /**
     * Map a NakoPay webhook event type to a FOSSBilling transaction status.
     *
     * @return string|null  FOSSBilling status string, or null if the event is unrecognised
     */
    public function mapEvent(string $eventType): ?string
    {
        return self::EVENT_MAP[$eventType] ?? null;
    }
}
