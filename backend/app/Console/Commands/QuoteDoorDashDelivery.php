<?php

namespace App\Console\Commands;

use App\Delivery\CourierUnavailable;
use App\Delivery\DoorDash\DoorDashGateway;
use App\Delivery\DoorDash\DoorDashRefused;
use App\Models\Restaurant;
use App\Services\CourierService;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Asks DoorDash to price a delivery from a restaurant to an address, as checkout does, and
 * shows what was asked and what DoorDash answered: for finding out why DoorDash refuses
 * addresses (a sandbox not yet set up for Australia, say). Nothing is booked.
 */
class QuoteDoorDashDelivery extends Command
{
    protected $signature = 'doordash:quote
        {restaurant : The restaurant’s link name, e.g. himalayan-momo-house}
        {address : The address on one line, e.g. "12 Southbank Boulevard, Southbank VIC 3006, Australia"}
        {--value=3000 : The food’s value, in cents}';

    protected $description = 'Ask DoorDash to price a delivery to an address, and show its answer';

    public function handle(CourierService $couriers, DoorDashGateway $doordash): int
    {
        $restaurant = Restaurant::query()->where('slug', (string) $this->argument('restaurant'))->first();

        if ($restaurant === null) {
            $this->error('There’s no restaurant with that link name.');

            return self::FAILURE;
        }

        if (! $restaurant->doordashConfigured()) {
            $this->error('This restaurant has no DoorDash access key yet. Enter it in Restaurant settings → DoorDash.');

            return self::FAILURE;
        }

        $request = $couriers->quoteRequest($restaurant, (string) $this->argument('address'), (int) $this->option('value'));
        $this->line('Asked DoorDash to price:');
        $this->line((string) json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            $quote = $doordash->quote($restaurant, $request);
        } catch (DoorDashRefused $refused) {
            $this->error("DoorDash refused it ({$refused->status}): {$refused->getMessage()}");

            return self::FAILURE;
        } catch (CourierUnavailable $exception) {
            // The reason, for a key DoorDash doesn't accept, is DoorDash's answer to it.
            $this->error(trim($exception->getMessage().' '.$exception->getPrevious()?->getMessage()));

            return self::FAILURE;
        }

        $arrives = $quote->dropoffEstimate?->setTimezone($restaurant->timezone)->format('g:i a');
        $this->info('DoorDash would deliver it for '.Money::format((int) $quote->feeCents).($arrives === null ? '' : ", arriving about {$arrives}").'.');

        return self::SUCCESS;
    }
}
