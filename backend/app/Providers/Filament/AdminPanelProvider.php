<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\EditRestaurantSettings;
use App\Filament\Support\BrandPalette;
use App\Http\Middleware\ApplyRestaurantPreferences;
use App\Models\Restaurant;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The owner back office at /admin. Each restaurant is a Filament tenant
 * (/admin/{slug}/…): resources are scoped to it automatically and only its
 * owners can open it (User::canAccessTenant).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => BrandPalette::fromHex('#7A1F2B'),
            ])
            ->tenant(Restaurant::class, slugAttribute: 'slug')
            ->tenantProfile(EditRestaurantSettings::class)
            ->tenantMiddleware([
                ApplyRestaurantPreferences::class,
            ], isPersistent: true)
            ->navigationGroups([
                'Orders',
                'Menu',
                'Restaurant',
            ])
            ->navigationItems([
                NavigationItem::make('Settings')
                    ->group('Restaurant')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->sort(100)
                    ->url(fn (): string => EditRestaurantSettings::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs(EditRestaurantSettings::getRouteName())),
            ])
            ->databaseTransactions()
            ->unsavedChangesAlerts()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
