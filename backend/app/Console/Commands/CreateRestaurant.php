<?php

namespace App\Console\Commands;

use App\Enums\RestaurantRole;
use App\Mail\OwnerInvitation;
use App\Models\Restaurant;
use App\Models\User;
use DateTimeZone;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Adds a restaurant and makes someone its owner, ready to set it up in the back office:
 * address, opening hours, menu and Stripe keys. It asks for each answer, or takes them as
 * options. The rest of the set-up is in docs/ADDING_A_RESTAURANT.md.
 */
class CreateRestaurant extends Command
{
    protected $signature = 'restaurant:create
        {--name= : The restaurant’s name, as customers see it}
        {--slug= : Its link name, e.g. himalayan-momo-house (from the name if left out)}
        {--timezone= : Its timezone (Australia/Melbourne if left out)}
        {--phone= : Its phone number, shown to customers}
        {--email= : Its email, where customers’ replies to receipts go}
        {--brand-color= : Its brand colour, e.g. #7A1F2B (the same as in its app’s brand.json)}
        {--owner-email= : The owner’s email, to sign in to the back office}
        {--owner-name= : The owner’s name, for a new account}
        {--no-invite : Print the owner’s link instead of emailing it}';

    protected $description = 'Add a restaurant and invite its owner to the back office';

    private const DEFAULT_BRAND_COLOR = '#7A1F2B';

    public function handle(): int
    {
        $interactive = $this->input->isInteractive();
        $name = $this->answer('name', fn (): string => text('Restaurant name', placeholder: 'Himalayan Momo House', required: true));
        $slug = $this->answer('slug', fn (): string => text(
            'Link name',
            default: Str::slug($name),
            required: true,
            hint: 'In the restaurant’s web links and back office address. It can’t be changed later.',
        ), $interactive ? null : Str::slug($name));
        $timezone = $this->answer('timezone', fn (): string => (string) select('Timezone', self::timezones(), default: 'Australia/Melbourne'), 'Australia/Melbourne');
        $phone = $this->answer('phone', fn (): string => text('Phone (optional)'), '');
        $email = $this->answer('email', fn (): string => text('Restaurant email (optional)', hint: 'Customers’ replies to their receipts go here.'), '');
        $brandColor = $this->answer('brand-color', fn (): string => text(
            'Brand colour',
            default: self::DEFAULT_BRAND_COLOR,
            required: true,
            hint: 'A hex colour like #7A1F2B, for buttons and the top of the menu. The same as in the app’s brand.json.',
        ), self::DEFAULT_BRAND_COLOR);
        $ownerEmail = $this->answer('owner-email', fn (): string => text('Owner’s email', required: true, hint: 'They sign in to the back office with it.'));
        $owner = User::query()->where('email', $ownerEmail)->first();
        $ownerName = $owner->name ?? $this->answer('owner-name', fn (): string => text('Owner’s name', required: true));

        $validator = Validator::make([
            'name' => $name,
            'slug' => $slug,
            'timezone' => $timezone,
            'phone' => $phone,
            'email' => $email,
            'brand_color' => $brandColor,
            'owner_email' => $ownerEmail,
            'owner_name' => $ownerName,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('restaurants', 'slug')],
            'timezone' => ['required', Rule::in(self::timezones())],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
        ], [
            'slug.regex' => 'The link name can only have lower-case letters, numbers and dashes, like himalayan-momo-house.',
            'slug.unique' => 'Another restaurant already has that link name.',
            'brand_color.regex' => 'The brand colour is a hex colour like #7A1F2B.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        [$restaurant, $owner, $newAccount] = DB::transaction(function () use ($name, $slug, $timezone, $phone, $email, $brandColor, $ownerEmail, $ownerName): array {
            $restaurant = Restaurant::query()->create([
                'name' => $name,
                'slug' => $slug,
                'timezone' => $timezone,
                'currency' => 'AUD',
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'brand_color' => strtoupper($brandColor),
                // Pickup to start with. Delivery needs its zones and dine in its tables first;
                // without opening hours it shows as closed, and without Stripe keys nobody
                // can check out.
                'is_accepting_orders' => true,
                'pickup_enabled' => true,
                'delivery_enabled' => false,
                'dine_in_enabled' => false,
            ]);

            $owner = User::query()->where('email', $ownerEmail)->first();
            $newAccount = $owner === null;
            // A random password nobody knows: the owner chooses theirs from the invitation.
            $owner ??= User::query()->create(['name' => $ownerName, 'email' => $ownerEmail, 'password' => Str::password(32)]);
            $restaurant->memberships()->create(['user_id' => $owner->id, 'role' => RestaurantRole::Owner]);

            return [$restaurant, $owner, $newAccount];
        });

        $panel = Filament::getPanel('admin');
        $url = $newAccount
            ? $panel->getResetPasswordUrl(Password::broker($panel->getAuthPasswordBroker())->createToken($owner), $owner)
            : (string) $panel->getUrl($restaurant);

        $this->components->info("Added {$restaurant->name} ({$restaurant->slug}), owned by {$owner->name} <{$owner->email}>.");

        if ($this->option('no-invite')) {
            $this->components->twoColumnDetail($newAccount ? 'Owner’s link to choose a password' : 'Owner’s link to the back office', $url);
        } else {
            Mail::to($owner)->send(new OwnerInvitation($restaurant, $owner, $url, $newAccount));
            $this->components->twoColumnDetail('Invitation emailed to', $owner->email);
        }

        $this->components->twoColumnDetail('Back office', (string) $panel->getUrl($restaurant));
        $this->components->twoColumnDetail('Stripe webhook URL', route('stripe.webhook', ['restaurant' => $restaurant]));
        $this->newLine();
        $this->line('  Next, from docs/ADDING_A_RESTAURANT.md:');
        $this->line('  1. The owner fills in the address, opening hours, menu and Stripe keys in the back office.');
        $this->line("  2. Add the app's brand folder, app/brands/{$restaurant->slug}/, and build its app and website.");
        $this->line('  3. Enter the website’s domain in the restaurant’s settings, then place a test order.');

        return self::SUCCESS;
    }

    /**
     * The option's value if given; otherwise the prompt's answer, or (without a terminal to
     * ask in) the default.
     *
     * @param  callable(): string  $prompt
     */
    private function answer(string $option, callable $prompt, ?string $default = null): string
    {
        $value = $this->option($option);

        if (is_string($value)) {
            return trim($value);
        }

        if (! $this->input->isInteractive()) {
            return $default ?? '';
        }

        return trim($prompt());
    }

    /**
     * @return list<string>
     */
    private static function timezones(): array
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::AUSTRALIA);
    }
}
