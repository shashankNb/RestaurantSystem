<?php

namespace App\Http\Controllers;

use App\Enums\SquareEnvironment;
use App\Models\Restaurant;
use App\Models\User;
use App\Payments\PaymentsUnavailable;
use App\Payments\Square\SquareApp;
use App\Services\SquareAccountService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Connect with Square", from Restaurant settings → Payments: sends the owner to Square to
 * sign in and approve, then takes Square's answer at the redirect URL registered with the
 * platform's Square application (/square/oauth/callback). A random state, kept in the
 * session, ties the answer to the owner and restaurant that asked.
 */
class SquareConnectController extends Controller
{
    private const SESSION_KEY = 'square_connect';

    public function connect(Request $request, Restaurant $restaurant): RedirectResponse
    {
        if (! self::isOwner($request, $restaurant)) {
            return self::signIn();
        }

        $environment = SquareEnvironment::tryFrom((string) $request->query('environment')) ?? SquareEnvironment::Production;
        $app = SquareApp::for($environment);

        abort_if($app === null, 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'restaurant' => $restaurant->id,
            'environment' => $environment->value,
        ]);

        return redirect()->away($app->authorizeUrl($state));
    }

    public function callback(Request $request, SquareAccountService $accounts): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $restaurant = is_array($pending) ? Restaurant::query()->find((int) ($pending['restaurant'] ?? 0)) : null;
        $app = is_array($pending) ? SquareApp::for(SquareEnvironment::from((string) $pending['environment'])) : null;

        // Not from this session (or it timed out): start again from the back office.
        if ($restaurant === null || $app === null || ! hash_equals((string) $pending['state'], (string) $request->query('state'))) {
            Notification::make()
                ->warning()
                ->title('Square wasn’t connected')
                ->body('That sign-in link from Square has expired. Press “Connect Square” again.')
                ->send();

            return redirect('/admin');
        }

        if (! self::isOwner($request, $restaurant)) {
            return self::signIn();
        }

        $settings = redirect()->route('filament.admin.tenant.profile', ['tenant' => $restaurant]);

        if (filled($request->query('error'))) {
            Notification::make()
                ->warning()
                ->title('Square wasn’t connected')
                ->body($request->query('error') === 'access_denied'
                    ? 'The connection wasn’t approved on Square’s page.'
                    : 'Square said: '.$request->string('error_description', $request->string('error')->toString())->toString())
                ->send();

            return $settings;
        }

        $processor = $restaurant->payment_processor;

        try {
            $checked = $accounts->connect($restaurant, $app, $request->string('code')->toString());
        } catch (PaymentsUnavailable) {
            Notification::make()
                ->danger()
                ->title('Square couldn’t be connected')
                ->body('Square didn’t accept the connection. Try “Connect Square” again in a moment.')
                ->send();

            return $settings;
        }

        $restaurant->refresh();

        Notification::make()
            ->success()
            ->title($app->environment === SquareEnvironment::Sandbox ? 'Your Square sandbox account is connected' : 'Your Square account is connected')
            ->body(match (true) {
                ! $checked => 'Its details couldn’t be loaded yet: press “Check with Square”.',
                $restaurant->square_location_id === null => 'Now choose the location payments go to, and save.',
                $restaurant->payment_processor !== $processor => 'Customers now pay with Square.',
                default => 'Customers keep paying with Stripe until you switch to Square.',
            })
            ->send();

        return $settings;
    }

    private static function isOwner(Request $request, Restaurant $restaurant): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->isOwnerOf($restaurant);
    }

    private static function signIn(): RedirectResponse
    {
        return redirect()->to(Filament::getPanel('admin')->getLoginUrl() ?? '/admin');
    }
}
