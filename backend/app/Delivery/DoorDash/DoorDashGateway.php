<?php

namespace App\Delivery\DoorDash;

use App\Delivery\CourierUnavailable;
use App\Models\Restaurant;

/**
 * DoorDash Drive, behind an interface so tests can swap in a fake. Every call acts for one
 * restaurant, signed with the access key from its own DoorDash developer account; whether it
 * reaches DoorDash's sandbox or real drivers depends on that key. Deliveries are addressed by
 * the platform's own ID for them (external_delivery_id).
 */
interface DoorDashGateway
{
    /**
     * Prices a delivery without booking it. The quote can be accepted for 5 minutes.
     *
     * @param  array<string, mixed>  $delivery  the Drive API's delivery fields
     *
     * @throws DoorDashRefused when DoorDash won't take it (an address it can't reach, an ID it already has)
     * @throws CourierUnavailable
     */
    public function quote(Restaurant $restaurant, array $delivery): DoorDashDelivery;

    /**
     * Books a quoted delivery.
     *
     * @throws DoorDashRefused when the quote has expired or is unknown
     * @throws CourierUnavailable
     */
    public function acceptQuote(Restaurant $restaurant, string $externalDeliveryId): DoorDashDelivery;

    /**
     * The delivery as DoorDash has it now.
     *
     * @throws DoorDashRefused when DoorDash doesn't know it
     * @throws CourierUnavailable
     */
    public function delivery(Restaurant $restaurant, string $externalDeliveryId): DoorDashDelivery;

    /**
     * Calls off a delivery, which DoorDash allows until a driver is assigned.
     *
     * @throws DoorDashRefused when it can't be cancelled any more, or is unknown
     * @throws CourierUnavailable
     */
    public function cancel(Restaurant $restaurant, string $externalDeliveryId): DoorDashDelivery;

    /**
     * Checks the restaurant's access key with DoorDash, for the back office.
     *
     * @throws DoorDashKeyRejected when DoorDash doesn't accept it
     * @throws CourierUnavailable
     */
    public function checkCredentials(Restaurant $restaurant): void;
}
