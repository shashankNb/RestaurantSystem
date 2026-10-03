<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Payments\PaymentsUnavailable;
use App\Services\SquareAccountService;
use Illuminate\Console\Command;

/**
 * Renews the Square access tokens that are more than a week old, as Square recommends (they
 * last 30 days). Scheduled daily. A connection Square no longer accepts is forgotten, and
 * that restaurant goes back to Stripe if it can.
 */
class RefreshSquareTokens extends Command
{
    protected $signature = 'square:refresh-tokens';

    protected $description = 'Renew restaurants’ Square access tokens that are more than a week old';

    public function handle(SquareAccountService $accounts): int
    {
        $renewed = 0;
        $lost = 0;

        Restaurant::query()
            ->whereNotNull('square_refresh_token')
            ->where(fn ($query) => $query
                ->whereNull('square_token_expires_at')
                ->orWhere('square_token_expires_at', '<=', now()->addDays(23)))
            ->lazyById()
            ->each(function (Restaurant $restaurant) use ($accounts, &$renewed, &$lost): void {
                try {
                    $accounts->renew($restaurant) ? $renewed++ : $lost++;
                } catch (PaymentsUnavailable $exception) {
                    // Square is unreachable: the token has weeks left, so tomorrow will do.
                    report($exception);
                }
            });

        $this->info("Renewed {$renewed} Square ".str('connection')->plural($renewed).($lost > 0 ? "; {$lost} no longer accepted." : '.'));

        return self::SUCCESS;
    }
}
