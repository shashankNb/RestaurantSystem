<?php

namespace App\Delivery\DoorDash;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A quote or a delivery as DoorDash describes it, from the Drive API or a webhook (which
 * carry the same fields). Money is in cents of `currency`.
 */
final readonly class DoorDashDelivery
{
    public function __construct(
        public string $externalDeliveryId,
        /** DoorDash's delivery_status (quote, created, confirmed, …); empty in a webhook. */
        public string $status,
        /** What DoorDash charges for it, with any tax it lists separately. */
        public ?int $feeCents = null,
        public ?string $currency = null,
        /** DoorDash's live map of the delivery. */
        public ?string $trackingUrl = null,
        /** DoorDash support's reference for the delivery. */
        public ?string $supportReference = null,
        /** The driver's first name and last initial. */
        public ?string $dasherName = null,
        public ?CarbonImmutable $pickupEstimate = null,
        public ?CarbonImmutable $dropoffEstimate = null,
        public ?string $cancellationReason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fee = is_numeric($data['fee'] ?? null)
            ? (int) $data['fee'] + (is_numeric($data['tax'] ?? null) ? (int) $data['tax'] : 0)
            : null;

        return new self(
            externalDeliveryId: self::text($data, 'external_delivery_id') ?? '',
            status: self::text($data, 'delivery_status') ?? '',
            feeCents: $fee,
            currency: self::text($data, 'currency'),
            trackingUrl: self::text($data, 'tracking_url'),
            supportReference: self::text($data, 'support_reference'),
            dasherName: self::text($data, 'dasher_name'),
            pickupEstimate: self::time($data, 'pickup_time_estimated'),
            dropoffEstimate: self::time($data, 'dropoff_time_estimated'),
            cancellationReason: self::text($data, 'cancellation_reason'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function time(array $data, string $key): ?CarbonImmutable
    {
        $value = self::text($data, $key);

        try {
            return $value === null ? null : CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
