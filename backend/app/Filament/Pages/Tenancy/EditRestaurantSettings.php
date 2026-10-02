<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Restaurant;
use App\Payments\PaymentGateway;
use App\Payments\PaymentsUnavailable;
use App\Payments\WalletSetup;
use Closure;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant settings and branding: the tenant profile page, opened from the
 * restaurant menu at the top of the sidebar or the "Settings" navigation item.
 */
class EditRestaurantSettings extends EditTenantProfile
{
    private const STATES = [
        'ACT' => 'Australian Capital Territory',
        'NSW' => 'New South Wales',
        'NT' => 'Northern Territory',
        'QLD' => 'Queensland',
        'SA' => 'South Australia',
        'TAS' => 'Tasmania',
        'VIC' => 'Victoria',
        'WA' => 'Western Australia',
    ];

    public static function getLabel(): string
    {
        return 'Restaurant settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ordering')
                ->description('Pausing ordering shows the restaurant as closed in the app until you turn it back on.')
                ->columns(3)
                ->schema([
                    Toggle::make('is_accepting_orders')->label('Accepting orders'),
                    Toggle::make('pickup_enabled')->label('Offer pickup'),
                    Toggle::make('delivery_enabled')->label('Offer delivery'),
                    Toggle::make('dine_in_enabled')
                        ->label('Offer dine in')
                        ->helperText('Customers order from their table. Add your tables under Restaurant → Tables.'),
                    TextInput::make('default_prep_minutes')
                        ->label('Usual prep time')
                        ->helperText('Used for ASAP estimates and the earliest scheduled time.')
                        ->required()
                        ->integer()
                        ->minValue(5)
                        ->maxValue(120)
                        ->suffix('min'),
                    TextInput::make('auto_reject_minutes')
                        ->label('Auto-reject after')
                        ->helperText('Paid orders nobody accepts in this time are rejected and refunded.')
                        ->required()
                        ->integer()
                        ->minValue(2)
                        ->maxValue(60)
                        ->suffix('min'),
                ]),

            Section::make('Payments')
                ->key('payments')
                ->description('Customers pay into your own Stripe account. Copy your keys from the Stripe dashboard (Developers → API keys), then add a webhook so paid orders reach the kitchen. Test keys (pk_test_, sk_test_) take test payments with Stripe’s test cards; live keys take real ones. Switch whenever you’re ready.')
                ->columns(2)
                ->afterHeader([
                    Action::make('turnOnWallets')
                        ->label('Turn on Apple Pay and Google Pay')
                        ->icon(Heroicon::OutlinedDevicePhoneMobile)
                        ->link()
                        ->visible(fn (): bool => $this->walletsOff())
                        ->requiresConfirmation()
                        ->modalHeading('Turn on Apple Pay and Google Pay?')
                        ->modalDescription('This switches them on in your Stripe account (Settings → Payment methods), for everything that uses that account.')
                        ->modalSubmitActionLabel('Turn them on')
                        ->action(fn () => $this->turnOnWallets()),
                    Action::make('checkStripeKeys')
                        ->label('Check with Stripe')
                        ->icon(Heroicon::OutlinedShieldCheck)
                        ->link()
                        ->visible(fn (): bool => filled($this->restaurant()->stripe_secret_key))
                        ->action(fn () => $this->checkStripeKeys()),
                ])
                ->schema([
                    TextEntry::make('payments_status')
                        ->label('Status')
                        ->state(fn (): string => $this->paymentsStatus())
                        ->columnSpanFull(),
                    TextEntry::make('wallets_status')
                        ->label('Apple Pay and Google Pay')
                        ->state(fn (): array => $this->walletsChecklist())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->columnSpanFull(),
                    TextInput::make('stripe_publishable_key')
                        ->label('Publishable key')
                        ->placeholder('pk_test_… or pk_live_…')
                        ->maxLength(255)
                        ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $this->checkPublishableKey($value, $get('stripe_secret_key'), $fail);
                        }]),
                    TextInput::make('stripe_secret_key')
                        ->label('Secret key')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn (): string => $this->savedPlaceholder('stripe_secret_key', 'sk_test_… or sk_live_…'))
                        ->helperText('Never shown again once saved.')
                        // Left empty, the saved key stays.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->maxLength(255)
                        ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            self::checkSecretKey($value, $fail);
                        }]),
                    TextEntry::make('stripe_webhook_url')
                        ->label('Webhook URL')
                        ->state(fn (): string => route('stripe.webhook', ['restaurant' => $this->restaurant()]))
                        ->helperText('In Stripe (Developers → Webhooks), add an endpoint with this URL and the events payment_intent.succeeded and payment_intent.payment_failed.')
                        ->copyable(),
                    TextInput::make('stripe_webhook_secret')
                        ->label('Webhook signing secret')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn (): string => $this->savedPlaceholder('stripe_webhook_secret', 'whsec_…'))
                        ->helperText('Shown on the endpoint’s page in Stripe, under “Signing secret”.')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->maxLength(255)
                        ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (filled($value) && preg_match('/^whsec_\S+$/', trim((string) $value)) !== 1) {
                                $fail('The signing secret starts with whsec_.');
                            }
                        }]),
                ]),

            Section::make('Details')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('slug')
                        ->label('Link name')
                        ->helperText('Part of your links, so it can’t be changed here.')
                        ->disabled()
                        ->dehydrated(false),
                    Textarea::make('description')
                        ->helperText('Shown under your name in the app and in search results.')
                        ->maxLength(500)
                        ->rows(2)
                        ->columnSpanFull(),
                    TextInput::make('phone')->tel()->maxLength(32),
                    TextInput::make('email')->email()->maxLength(255),
                    TextInput::make('abn')
                        ->label('ABN')
                        ->helperText('Printed on tax receipts.')
                        ->maxLength(20),
                    Select::make('timezone')
                        ->options(fn (): array => array_combine(
                            DateTimeZone::listIdentifiers(DateTimeZone::AUSTRALIA),
                            DateTimeZone::listIdentifiers(DateTimeZone::AUSTRALIA),
                        ))
                        ->required()
                        ->searchable(),
                    TextInput::make('custom_domain')
                        ->helperText('Your own web address for the ordering site, e.g. order.example.com.au. Table QR codes and links in emails use it.')
                        ->maxLength(255)
                        ->regex('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i')
                        ->unique(ignoreRecord: true)
                        ->columnSpanFull(),
                ]),

            Section::make('Address')
                ->columns(2)
                ->schema([
                    TextInput::make('address.line1')->label('Street address')->required()->maxLength(255),
                    TextInput::make('address.line2')->label('Address line 2')->maxLength(255),
                    TextInput::make('address.suburb')->label('Suburb')->required()->maxLength(100),
                    Select::make('address.state')->label('State')->options(self::STATES)->required(),
                    TextInput::make('address.postcode')->label('Postcode')->required()->regex('/^\d{4}$/'),
                ]),

            Section::make('Branding')
                ->columns(3)
                ->schema([
                    ColorPicker::make('brand_color')
                        ->label('Brand colour')
                        ->required()
                        ->regex('/^#[0-9a-fA-F]{6}$/'),
                    FileUpload::make('logo')
                        ->image()
                        ->disk(config('ordering.media_disk'))
                        ->directory('restaurants')
                        ->visibility('public')
                        ->maxSize(2048),
                    FileUpload::make('cover_image')
                        ->label('Header photo')
                        ->helperText('A wide food photo for the top of your menu.')
                        ->image()
                        ->imageEditor()
                        ->disk(config('ordering.media_disk'))
                        ->directory('restaurants')
                        ->visibility('public')
                        ->maxSize(5120),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Secrets never go to the browser; the fields start empty and only replace them.
        unset($data['stripe_secret_key'], $data['stripe_webhook_secret']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['address'] = [...(array) ($data['address'] ?? []), 'country' => 'AU'];

        foreach (['stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = filled($data[$key]) ? trim((string) $data[$key]) : null;
            }
        }

        return $data;
    }

    /**
     * Once the keys or the website's domain change, gets the Stripe account ready for Apple Pay
     * and Google Pay (registering the domain) and keeps the answer for the checklist. Stripe is
     * asked after the save is committed, so the restaurant isn't kept locked while it answers.
     */
    protected function afterSave(): void
    {
        $restaurant = $this->restaurant();
        $changed = $restaurant->wasChanged(['stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret', 'custom_domain']);

        if (! $restaurant->acceptsPayments() || (! $changed && $restaurant->walletSetup() !== null)) {
            return;
        }

        DB::afterCommit(function (): void {
            if ($this->refreshWallets() === null) {
                Notification::make()
                    ->warning()
                    ->title('Apple Pay and Google Pay couldn’t be set up')
                    ->body('Stripe didn’t answer. Press “Check with Stripe” under Payments to try again: it checks your keys too.')
                    ->send();
            }
        });
    }

    private function restaurant(): Restaurant
    {
        /** @var Restaurant $restaurant */
        $restaurant = $this->tenant;

        return $restaurant;
    }

    private function paymentsStatus(): string
    {
        $restaurant = $this->restaurant();

        if ($restaurant->acceptsPayments()) {
            return self::keyMode($restaurant->stripe_secret_key) === 'live'
                ? 'Taking payments into your Stripe account, in live mode.'
                : 'Taking test payments into your Stripe account, in test mode: pay with Stripe’s test cards, such as 4242 4242 4242 4242. Switch to live keys to take real payments.';
        }

        $missing = collect([
            // Anything else in its place (typed into the database, say) doesn't count.
            'the publishable key' => str_starts_with((string) $restaurant->stripe_publishable_key, 'pk_') ? 'set' : null,
            'the secret key' => $restaurant->stripe_secret_key,
            'the webhook signing secret' => $restaurant->stripe_webhook_secret,
        ])->filter(fn (?string $value): bool => blank($value))->keys();

        return 'Not taking payments yet: add '.$missing->join(', ', ' and ').'. Until then customers can see the menu but can’t check out.';
    }

    private function savedPlaceholder(string $attribute, string $example): string
    {
        return filled($this->restaurant()->getAttribute($attribute)) ? 'Saved. Paste a new one to replace it.' : $example;
    }

    private function checkStripeKeys(): void
    {
        $restaurant = $this->restaurant();
        $payments = app(PaymentGateway::class);

        try {
            $account = $payments->accountName($restaurant);
        } catch (PaymentsUnavailable) {
            Notification::make()
                ->danger()
                ->title('Stripe didn’t accept the secret key')
                ->body('Copy it again from the Stripe dashboard (Developers → API keys) and save.')
                ->send();

            return;
        }

        $wallets = $this->refreshWallets();

        Notification::make()
            ->success()
            ->title('Your Stripe keys work')
            ->body("Payments go to {$account}, in ".self::keyMode($restaurant->stripe_secret_key).' mode. '.match (true) {
                $wallets === null => 'Apple Pay and Google Pay couldn’t be checked just now.',
                $wallets->ready() => "Apple Pay and Google Pay are ready on {$wallets->domain}.",
                default => 'Apple Pay and Google Pay: see the checklist under Payments.',
            })
            ->send();
    }

    private function turnOnWallets(): void
    {
        try {
            app(PaymentGateway::class)->turnOnWallets($this->restaurant());
        } catch (PaymentsUnavailable) {
            Notification::make()
                ->danger()
                ->title('Stripe didn’t switch them on')
                ->body('Turn on Apple Pay and Google Pay in the Stripe dashboard (Settings → Payment methods), then press “Check with Stripe”.')
                ->send();

            return;
        }

        $this->refreshWallets();

        if ($this->walletsOff()) {
            Notification::make()
                ->warning()
                ->title('Stripe didn’t switch them both on')
                ->body('Check Apple Pay and Google Pay in the Stripe dashboard (Settings → Payment methods). Stripe may not offer one of them to your account yet.')
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Apple Pay and Google Pay are on in your Stripe account')
            ->send();
    }

    /** The last check found Apple Pay or Google Pay switched off in the Stripe account. */
    private function walletsOff(): bool
    {
        $setup = $this->restaurant()->walletSetup();

        return $setup !== null && filled($this->restaurant()->stripe_secret_key) && (! $setup->applePay || ! $setup->googlePay);
    }

    /**
     * Asks Stripe how ready the account is for Apple Pay and Google Pay, registering the
     * website's domain if need be, and keeps the answer. Null if Stripe couldn't be reached
     * (the last answer stays).
     */
    private function refreshWallets(): ?WalletSetup
    {
        $restaurant = $this->restaurant();

        try {
            $setup = app(PaymentGateway::class)->prepareWallets($restaurant, $restaurant->walletDomain());
        } catch (PaymentsUnavailable) {
            return null;
        }

        $restaurant->forceFill(['stripe_wallets' => $setup->toArray()])->save();

        return $setup;
    }

    /**
     * The checklist: each wallet switched on in the Stripe account, and the website's domain
     * registered for them. From the last check; nothing is asked of Stripe to show it.
     *
     * @return list<string>
     */
    private function walletsChecklist(): array
    {
        $restaurant = $this->restaurant();
        $setup = $restaurant->walletSetup();

        if ($setup === null) {
            return [$restaurant->acceptsPayments()
                ? 'Not checked yet: press “Check with Stripe”.'
                : 'Set up for you once your Stripe keys are in.'];
        }

        $checkedAt = $setup->checkedAt?->setTimezone($restaurant->timezone)->format('j M, g:i a');

        return array_values(array_filter([
            $setup->applePay ? 'Apple Pay: on in your Stripe account.' : 'Apple Pay: off in your Stripe account, so customers don’t see it.',
            $setup->googlePay ? 'Google Pay: on in your Stripe account.' : 'Google Pay: off in your Stripe account, so customers don’t see it.',
            match (true) {
                $setup->domain === null => 'Your website: Stripe only shows them on a public website, not on localhost. Enter your website’s domain under Details.',
                $setup->domainReady => "{$setup->domain}: registered with Stripe, ready for both.",
                default => trim("{$setup->domain}: registered with Stripe, but not ready yet. {$setup->domainProblem}"),
            },
            $checkedAt === null ? null : "Checked {$checkedAt}.",
        ]));
    }

    /**
     * A publishable key, from the same Stripe mode (test or live) as the secret key being
     * saved, or else the one already saved. Catches the keys being pasted the wrong way round.
     */
    private function checkPublishableKey(mixed $value, mixed $newSecretKey, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        // Pasted keys often bring a space with them; it's trimmed off when saved.
        $value = trim((string) $value);

        if (preg_match('/^(sk|rk)_/', $value) === 1) {
            $fail('That’s a secret key: paste it under “Secret key”. The publishable key starts with pk_.');

            return;
        }

        if (preg_match('/^pk_(test|live)_\S+$/', $value) !== 1) {
            $fail('The publishable key starts with pk_test_ (test mode) or pk_live_ (live mode).');

            return;
        }

        $secretMode = self::keyMode(filled($newSecretKey) ? trim((string) $newSecretKey) : $this->restaurant()->stripe_secret_key);

        if ($secretMode !== null && $secretMode !== self::keyMode($value)) {
            $fail('This key is for '.self::keyMode($value)." mode but the secret key is for {$secretMode} mode. Use both keys from the same mode.");
        }
    }

    private static function checkSecretKey(mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $value = trim((string) $value);

        if (str_starts_with($value, 'pk_')) {
            $fail('That’s the publishable key. The secret key starts with sk_ (or rk_ for a restricted key).');
        } elseif (preg_match('/^(sk|rk)_(test|live)_\S+$/', $value) !== 1) {
            $fail('The secret key starts with sk_test_ (test mode) or sk_live_ (live mode), or rk_ for a restricted key.');
        }
    }

    /** "test" or "live", from a Stripe key's prefix. */
    private static function keyMode(?string $key): ?string
    {
        return $key !== null && preg_match('/^(?:pk|sk|rk)_(test|live)_/', $key, $match) === 1 ? $match[1] : null;
    }
}
