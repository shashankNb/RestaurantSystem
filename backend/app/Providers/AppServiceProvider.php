<?php

namespace App\Providers;

use App\Payments\PaymentGateway;
use App\Payments\Square\HttpSquareGateway;
use App\Payments\Square\SquareGateway;
use App\Payments\StripePaymentGateway;
use Filament\Resources\Resource;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Each order brings its restaurant's own Stripe keys (see StripePaymentGateway), or the
        // tokens of the Square account it connected (see HttpSquareGateway).
        $this->app->singleton(PaymentGateway::class, StripePaymentGateway::class);
        $this->app->singleton(SquareGateway::class, HttpSquareGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fail loudly in development and tests when mass assignment drops an
        // attribute that is missing from a model's #[Fillable] list.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Refuse migrate:fresh, db:wipe and the like against production.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Sentence-case labels in the back office: "Menu items", not "Menu Items".
        Resource::titleCaseModelLabel(false);

        $this->configureRateLimiting();
    }

    /**
     * Limits for the endpoints that can be abused. Over the limit, the API answers 429 with
     * a Retry-After header (see bootstrap/app.php for the message).
     */
    private function configureRateLimiting(): void
    {
        // Password guessing: a few tries per account from one address, and a ceiling per address.
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('login:'.$request->ip()),
        ]);

        RateLimiter::for('register', fn (Request $request): Limit => Limit::perHour(10)->by('register:'.$request->ip()));

        // The cart asks for a new quote as it changes; the limit also stops promo-code guessing.
        RateLimiter::for('quote', fn (Request $request): Limit => Limit::perMinute(60)->by('quote:'.$request->ip()));

        RateLimiter::for('delivery-check', fn (Request $request): Limit => Limit::perMinute(30)->by('delivery-check:'.$request->ip()));

        // Placing orders: generous for real customers (retries reuse the same order), tight
        // enough to stop anyone creating PaymentIntents in bulk.
        RateLimiter::for('orders', fn (Request $request): Limit => Limit::perMinute(10)->by('orders:'.$request->ip()));
        // Each try with a card is a payment attempt at the bank: a few a minute is plenty.
        RateLimiter::for('square-payments', fn (Request $request): Limit => Limit::perMinute(10)->by('square-payments:'.$request->ip()));

        RateLimiter::for('push-tokens', fn (Request $request): Limit => Limit::perMinute(20)->by('push-tokens:'.$request->ip()));
    }
}
