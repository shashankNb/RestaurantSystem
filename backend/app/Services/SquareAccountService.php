<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Payments\PaymentsUnavailable;
use App\Payments\Square\SquareApp;
use App\Payments\Square\SquareConnectionLost;
use App\Payments\Square\SquareGateway;
use App\Payments\Square\SquareLocation;
use App\Payments\WalletSetup;
use Illuminate\Support\Facades\Log;

/**
 * A restaurant's connection to its Square account: connecting it ("Connect with Square"),
 * checking it, keeping its tokens fresh and disconnecting it.
 */
final class SquareAccountService
{
    public function __construct(private readonly SquareGateway $square) {}

    /**
     * Connects the restaurant's Square account with the code from Square's approval page,
     * then loads its name and locations. If Stripe isn't set up, Square becomes the processor.
     * Returns false when the account is connected but its details couldn't be loaded yet
     * ("Check with Square" tries again).
     *
     * @throws PaymentsUnavailable when Square doesn't accept the code
     */
    public function connect(Restaurant $restaurant, SquareApp $app, string $code): bool
    {
        $connection = $this->square->exchangeCode($app, $code);

        $restaurant->forceFill([
            'square_environment' => $app->environment,
            'square_merchant_id' => $connection->merchantId,
            'square_access_token' => $connection->accessToken,
            'square_refresh_token' => $connection->refreshToken,
            'square_token_expires_at' => $connection->expiresAt,
        ])->save();

        try {
            $this->check($restaurant);
        } catch (PaymentsUnavailable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * Asks Square for the account's name and locations, choosing the location when only one
     * can take the restaurant's currency, then registers the website for Apple Pay. The first
     * processor that's ready is the one used.
     *
     * @throws PaymentsUnavailable
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

    /**
     * Renews the access token (Square recommends weekly; it lasts 30 days). A connection
     * Square no longer accepts is forgotten, and the restaurant goes back to Stripe if it can.
     * Returns false then.
     *
     * @throws PaymentsUnavailable when Square can't be reached (the token still has weeks left)
     */
    public function renew(Restaurant $restaurant): bool
    {
        try {
            $connection = $this->square->refresh($restaurant);
        } catch (SquareConnectionLost $lost) {
            Log::warning('Square no longer accepts a restaurant’s connection; its owner has to connect Square again.', [
                'restaurant' => $restaurant->slug,
                'error' => $lost->getPrevious()?->getMessage(),
            ]);
            $restaurant->disconnectSquare();

            return false;
        }

        $restaurant->forceFill([
            'square_merchant_id' => $connection->merchantId,
            'square_access_token' => $connection->accessToken,
            'square_refresh_token' => $connection->refreshToken,
            'square_token_expires_at' => $connection->expiresAt,
        ])->save();

        return true;
    }

    /**
     * Gives up the platform's access to the Square account, and forgets it. If Square can't be
     * reached, it's forgotten here anyway (the owner can also remove it in Square's dashboard).
     */
    public function disconnect(Restaurant $restaurant): void
    {
        try {
            $this->square->revoke($restaurant);
        } catch (PaymentsUnavailable $exception) {
            report($exception);
        }

        $restaurant->disconnectSquare();
    }
}
