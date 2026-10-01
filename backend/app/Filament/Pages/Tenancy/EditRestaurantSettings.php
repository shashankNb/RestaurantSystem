<?php

namespace App\Filament\Pages\Tenancy;

use DateTimeZone;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                        ->helperText('Your own web address for the ordering site, e.g. order.example.com.au.')
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
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['address'] = [...(array) ($data['address'] ?? []), 'country' => 'AU'];

        return $data;
    }
}
