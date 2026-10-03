<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Payments\PaymentsUnavailable;
use App\Payments\Square\SquareGateway;
use App\Payments\Square\SquareLocation;
use App\Payments\WalletSetup;

/**
 * A restaurant's own Square account, through the credentials its owner entered: checking
 * them and loading the account's name and locations, and registering the website for Apple
 * Pay.
 */
final class SquareAccountService
{
    public function __construct(private readonly SquareGateway $square) {}

    /**
     * Asks Square for the account's name and locations (which also checks the access token),
     * keeps the chosen location if it can still take the restaurant's currency or chooses the
     * only one that can, then registers the website for Apple Pay. If Square is now ready and
     * Stripe isn't, Square becomes the processor.
     *
     * @throws PaymentsUnavailable (SquareConnectionLost when Square doesn't accept the token)
     */
    public function check(Restaurant $restaurant): void
    {
        $name = $this->square->merchantName($restaurant);
        $locations = $this->square->locations($restaurant);
        $usable = array_values(array_filter(
            $locations,
            fn (SquareLocation $location): bool => $location->active && strcasecmp($location->currency, $restaurant->currency) === 0,
        ));
        $usableIds = array_map(fn (SquareLocation $location): string => $location->id, $usable);

        $restaurant->forceFill([
            'square_merchant_name' => $name,
            'square_locations' => array_map(fn (SquareLocation $location): array => $location->toArray(), $locations),
            'square_location_id' => match (true) {
                in_array($restaurant->square_location_id, $usableIds, true) => $restaurant->square_location_id,
                count($usable) === 1 => $usable[0]->id,
                default => null,
            },
        ])->save();

        $this->registerWebsite($restaurant);
        $restaurant->useReadyProcessor();
    }

    /**
     * Registers the restaurant's website with its Square account for Apple Pay, and keeps the
     * answer for the back office's checklist.
     *
     * @throws PaymentsUnavailable
     */
    public function registerWebsite(Restaurant $restaurant): WalletSetup
    {
        $setup = $this->square->registerApplePayDomain($restaurant, $restaurant->walletDomain());
        $restaurant->forceFill(['square_wallets' => $setup->toArray()])->save();

        return $setup;
    }
}
