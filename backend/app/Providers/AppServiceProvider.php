<?php

namespace App\Providers;

use App\Payments\PaymentGateway;
use App\Payments\StripePaymentGateway;
use App\Payments\StripeWebhook;
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
        $this->app->singleton(PaymentGateway::class, fn (): PaymentGateway => new StripePaymentGateway(
            self::stringConfig('services.stripe.secret'),
        ));

        $this->app->singleton(StripeWebhook::class, fn (): StripeWebhook => new StripeWebhook(
            self::stringConfig('services.stripe.webhook_secret'),
        ));
    }

    private static function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
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

        RateLimiter::for('push-tokens', fn (Request $request): Limit => Limit::perMinute(20)->by('push-tokens:'.$request->ip()));
    }
}
