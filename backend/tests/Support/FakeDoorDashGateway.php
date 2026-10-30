<?php

namespace Tests\Support;

use App\Delivery\CourierUnavailable;
use App\Delivery\DoorDash\DoorDashDelivery;
use App\Delivery\DoorDash\DoorDashGateway;
use App\Delivery\DoorDash\DoorDashKeyRejected;
use App\Delivery\DoorDash\DoorDashRefused;
use App\Models\Restaurant;
use RuntimeException;

/**
 * Stands in for DoorDash in tests. Like DoorDash, it keeps every quote and delivery by its
 * external ID, refuses an ID it already has, and only cancels before a driver is assigned.
 */
final class FakeDoorDashGateway implements DoorDashGateway
{
    /** @var list<array<string, mixed>> every quote request, in order */
    public array $quotes = [];

    /** @var array<string, DoorDashDelivery> by external delivery ID */
    public array $deliveries = [];

    /** @var list<string> external IDs of accepted quotes, in order */
    public array $accepted = [];

    /** @var list<string> external IDs cancelled */
    public array $cancelled = [];

    /** What DoorDash charges, in cents. */
    public int $feeCents = 975;

    /** Makes the next call fail, as if DoorDash were down. */
    public bool $failNext = false;

    /** DoorDash doesn't accept the access key. */
    public bool $rejectKey = false;

    /**
     * DoorDash refusing the next quote: its reason, as for an address it can't reach (a 422
     * about the drop-off address), or the refusal itself.
     */
    public DoorDashRefused|string|null $refuseNextQuote = null;

    /** Accepting a quote fails, as if it had expired. */
    public bool $refuseAccept = false;

    public static function install(): self
    {
        $fake = new self;
        app()->instance(DoorDashGateway::class, $fake);

        return $fake;
    }

    public function quote(Restaurant $restaurant, array $delivery): DoorDashDelivery
    {
        $this->failIfAskedFor($restaurant);
        $this->quotes[] = $delivery;
        $id = (string) $delivery['external_delivery_id'];

        if ($this->refuseNextQuote !== null) {
            $refusal = $this->refuseNextQuote;
            $this->refuseNextQuote = null;

            throw $refusal instanceof DoorDashRefused ? $refusal : new DoorDashRefused(422, $refusal, 'dropoff_address', ['dropoff_address' => $refusal]);
        }

        if (isset($this->deliveries[$id])) {
            throw new DoorDashRefused(409, 'Duplicate delivery ID');
        }

        return $this->deliveries[$id] = new DoorDashDelivery($id, 'quote', $this->feeCents, 'AUD');
    }

    public function acceptQuote(Restaurant $restaurant, string $externalDeliveryId): DoorDashDelivery
    {
        $this->failIfAskedFor($restaurant);
        $quote = $this->deliveries[$externalDeliveryId] ?? null;

        if ($quote === null || $this->refuseAccept) {
            throw new DoorDashRefused(404, 'Unknown delivery ID');
        }

        $this->accepted[] = $externalDeliveryId;

        return $this->deliveries[$externalDeliveryId] = new DoorDashDelivery(
            externalDeliveryId: $externalDeliveryId,
            status: 'created',
            feeCents: $quote->feeCents,
            currency: 'AUD',
            trackingUrl: "https://track.doordash.test/{$externalDeliveryId}",
            supportReference: '1234'.count($this->accepted),
        );
    }

    public function delivery(Restaurant $restaurant, string $externalDeliveryId): DoorDashDelivery
    {
        $this->failIfAskedFor($restaurant);

        return $this->deliveries[$externalDeliveryId] ?? throw new DoorDashRefused(404, 'Unknown delivery ID');
    }

    public function cancel(Restaurant $restaurant, string $externalDeliveryId): DoorDashDelivery
    {
        $delivery = $this->delivery($restaurant, $externalDeliveryId);

        if (! in_array($delivery->status, ['quote', 'created'], true)) {
            throw new DoorDashRefused(409, 'Cancel is not allowed');
        }

        $this->cancelled[] = $externalDeliveryId;

        return $this->deliveries[$externalDeliveryId] = new DoorDashDelivery($externalDeliveryId, 'cancelled', $delivery->feeCents, 'AUD', cancellationReason: 'cancelled_by_creator');
    }

    public function checkCredentials(Restaurant $restaurant): void
    {
        $this->failIfAskedFor($restaurant);
    }

    /** Moves a delivery along, as DoorDash's Delivery Simulator does. */
    public function advance(string $externalDeliveryId, string $status, ?string $dasherName = 'Priya S.'): DoorDashDelivery
    {
        $delivery = $this->deliveries[$externalDeliveryId];

        return $this->deliveries[$externalDeliveryId] = new DoorDashDelivery(
            externalDeliveryId: $externalDeliveryId,
            status: $status,
            feeCents: $delivery->feeCents,
            currency: 'AUD',
            trackingUrl: $delivery->trackingUrl,
            supportReference: $delivery->supportReference,
            dasherName: $status === 'created' ? null : $dasherName,
        );
    }

    /** Like the real gateway: no access key, no DoorDash; and the call can be made to fail. */
    private function failIfAskedFor(Restaurant $restaurant): void
    {
        if (! $restaurant->doordashConfigured()) {
            throw CourierUnavailable::notConfigured();
        }

        if ($this->rejectKey) {
            throw DoorDashKeyRejected::from(new RuntimeException('401'));
        }

        if ($this->failNext) {
            $this->failNext = false;

            throw CourierUnavailable::because(new RuntimeException('DoorDash is down'));
        }
    }
}
