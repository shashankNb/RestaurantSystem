<?php

namespace App\Filament\Resources\Memberships\Pages;

use App\Filament\Resources\Memberships\MembershipResource;
use App\Models\Membership;
use App\Models\Restaurant;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageMemberships extends ManageRecords
{
    protected static string $resource = MembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add staff account')
                ->using(function (array $data): Membership {
                    /** @var Restaurant $restaurant */
                    $restaurant = Filament::getTenant();

                    // An existing account (e.g. a customer) is linked as-is: owners
                    // never set or see another account's password.
                    $user = User::query()->firstOrCreate(
                        ['email' => mb_strtolower(trim($data['email']))],
                        ['name' => $data['name'], 'password' => $data['password']],
                    );

                    return $restaurant->memberships()->create([
                        'user_id' => $user->id,
                        'role' => $data['role'],
                    ]);
                }),
        ];
    }
}
