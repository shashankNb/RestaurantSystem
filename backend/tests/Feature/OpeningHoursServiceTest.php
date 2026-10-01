<?php

use App\Data\OpeningStatus;
use App\Models\Restaurant;
use App\Services\OpeningHoursService;
use Carbon\CarbonImmutable;

/*
 * Dates used below, all in 2026. Melbourne moves to daylight time (UTC+11) on Sunday
 * 4 October, so from then 5 pm local is 06:00 UTC; before it (UTC+10), 07:00 UTC.
 *   Mon 5 Oct, Tue 6, Wed 7, Thu 8, Fri 9, Sat 10, Sun 11
 *   Thu 24 Dec, Fri 25 Dec (Christmas Day), Sat 26 Dec
 */

/**
 * @param  list<array{0: int, 1: string, 2: string}>  $shifts  [day of week, opens, closes]
 */
function restaurantOpen(array $shifts, string $timezone = 'Australia/Melbourne'): Restaurant
{
    $restaurant = Restaurant::factory()->create(['timezone' => $timezone]);

    foreach ($shifts as [$day, $opens, $closes]) {
        $restaurant->openingHours()->create(['day_of_week' => $day, 'opens_at' => $opens, 'closes_at' => $closes]);
    }

    return $restaurant->fresh();
}

/**
 * @return list<array{0: int, 1: string, 2: string}>
 */
function everyDay(string $opens, string $closes): array
{
    return array_map(fn (int $day): array => [$day, $opens, $closes], range(0, 6));
}

function melbourne(string $localTime): CarbonImmutable
{
    return CarbonImmutable::parse($localTime, 'Australia/Melbourne');
}

function statusAt(Restaurant $restaurant, CarbonImmutable $at): OpeningStatus
{
    return app(OpeningHoursService::class)->status($restaurant, $at);
}

it('is open during a shift and says when it closes', function () {
    $status = statusAt(restaurantOpen(everyDay('17:00:00', '22:00:00')), melbourne('2026-10-05 18:30'));

    expect($status->isOpen)->toBeTrue()
        ->and($status->closesAt?->toIso8601ZuluString())->toBe('2026-10-05T11:00:00Z')
        ->and($status->nextOpeningAt)->toBeNull();
});

it('is closed before opening and says when it opens', function () {
    $status = statusAt(restaurantOpen(everyDay('17:00:00', '22:00:00')), melbourne('2026-10-05 12:00'));

    expect($status->isOpen)->toBeFalse()
        ->and($status->closesAt)->toBeNull()
        ->and($status->nextOpeningAt?->toIso8601ZuluString())->toBe('2026-10-05T06:00:00Z');
});

it('treats the closing time as closed', function () {
    $restaurant = restaurantOpen(everyDay('17:00:00', '22:00:00'));

    expect(app(OpeningHoursService::class)->isOpenAt($restaurant, melbourne('2026-10-05 21:59:59')))->toBeTrue()
        ->and(app(OpeningHoursService::class)->isOpenAt($restaurant, melbourne('2026-10-05 22:00')))->toBeFalse();
});

it('skips days without hours when finding the next opening', function () {
    // Monday and Wednesday only.
    $restaurant = restaurantOpen([[1, '17:00:00', '22:00:00'], [3, '17:00:00', '22:00:00']]);

    expect(statusAt($restaurant, melbourne('2026-10-05 22:30'))->nextOpeningAt?->toIso8601ZuluString())
        ->toBe('2026-10-07T06:00:00Z');
});

it('keeps a shift that closes after midnight open into the next morning', function () {
    // Saturday 5 pm until 1 am Sunday.
    $restaurant = restaurantOpen([[6, '17:00:00', '01:00:00']]);

    $lateNight = statusAt($restaurant, melbourne('2026-10-11 00:30'));

    expect($lateNight->isOpen)->toBeTrue()
        ->and($lateNight->closesAt?->toIso8601ZuluString())->toBe('2026-10-10T14:00:00Z')
        ->and(statusAt($restaurant, melbourne('2026-10-11 01:30'))->isOpen)->toBeFalse();
});

it('reads a closing time of midnight as the end of the day', function () {
    $status = statusAt(restaurantOpen([[1, '17:00:00', '00:00:00']]), melbourne('2026-10-05 23:59'));

    expect($status->isOpen)->toBeTrue()
        ->and($status->closesAt?->toIso8601ZuluString())->toBe('2026-10-05T13:00:00Z');
});

it('lets a special closure replace that day’s hours', function () {
    $restaurant = restaurantOpen(everyDay('17:00:00', '22:00:00'));
    $restaurant->specialHours()->create(['date' => '2026-10-07', 'is_closed' => true, 'note' => 'Staff training']);

    $status = statusAt($restaurant, melbourne('2026-10-07 18:00'));

    expect($status->isOpen)->toBeFalse()
        ->and($status->nextOpeningAt?->toIso8601ZuluString())->toBe('2026-10-08T06:00:00Z');
});

it('lets special hours change a day’s times', function () {
    $restaurant = restaurantOpen(everyDay('17:00:00', '22:00:00'));
    $restaurant->specialHours()->create(['date' => '2026-10-07', 'is_closed' => false, 'opens_at' => '12:00:00', 'closes_at' => '15:00:00']);

    $lunch = statusAt($restaurant, melbourne('2026-10-07 13:00'));
    $evening = statusAt($restaurant, melbourne('2026-10-07 18:00'));

    expect($lunch->isOpen)->toBeTrue()
        ->and($lunch->closesAt?->toIso8601ZuluString())->toBe('2026-10-07T04:00:00Z')
        ->and($evening->isOpen)->toBeFalse()
        ->and($evening->nextOpeningAt?->toIso8601ZuluString())->toBe('2026-10-08T06:00:00Z');
});

it('does not cut short the previous night’s late shift on a closed day', function () {
    // Christmas Eve (Thursday) runs until 1 am; Christmas Day is closed.
    $restaurant = restaurantOpen([
        [4, '17:00:00', '01:00:00'],
        [5, '17:00:00', '22:00:00'],
        [6, '17:00:00', '22:00:00'],
    ]);
    $restaurant->specialHours()->create(['date' => '2026-12-25', 'is_closed' => true, 'note' => 'Christmas Day']);

    $afterMidnight = statusAt($restaurant, melbourne('2026-12-25 00:30'));
    $christmasEvening = statusAt($restaurant, melbourne('2026-12-25 18:00'));

    expect($afterMidnight->isOpen)->toBeTrue()
        ->and($afterMidnight->closesAt?->toIso8601ZuluString())->toBe('2026-12-24T14:00:00Z')
        ->and($christmasEvening->isOpen)->toBeFalse()
        ->and($christmasEvening->nextOpeningAt?->toIso8601ZuluString())->toBe('2026-12-26T06:00:00Z');
});

it('merges back-to-back shifts so “open until” is the end of the block', function () {
    $restaurant = restaurantOpen([[1, '11:30:00', '15:00:00'], [1, '15:00:00', '22:00:00']]);

    expect(statusAt($restaurant, melbourne('2026-10-05 14:00'))->closesAt?->toIso8601ZuluString())
        ->toBe('2026-10-05T11:00:00Z');
});

it('closes between lunch and dinner shifts', function () {
    $restaurant = restaurantOpen([[1, '11:30:00', '14:30:00'], [1, '17:00:00', '22:00:00']]);

    $status = statusAt($restaurant, melbourne('2026-10-05 15:00'));

    expect($status->isOpen)->toBeFalse()
        ->and($status->nextOpeningAt?->toIso8601ZuluString())->toBe('2026-10-05T06:00:00Z');
});

it('is closed with no next opening when no hours are set', function () {
    $status = statusAt(restaurantOpen([]), melbourne('2026-10-05 18:00'));

    expect($status->isOpen)->toBeFalse()
        ->and($status->nextOpeningAt)->toBeNull();
});

it('follows daylight saving in the restaurant’s timezone', function () {
    $restaurant = restaurantOpen(everyDay('17:00:00', '22:00:00'));

    // Standard time (UTC+10) in June, daylight time (UTC+11) in October.
    expect(statusAt($restaurant, melbourne('2026-06-15 12:00'))->nextOpeningAt?->toIso8601ZuluString())
        ->toBe('2026-06-15T07:00:00Z')
        ->and(statusAt($restaurant, melbourne('2026-10-05 12:00'))->nextOpeningAt?->toIso8601ZuluString())
        ->toBe('2026-10-05T06:00:00Z');
});

it('uses each restaurant’s own timezone', function () {
    $perth = restaurantOpen(everyDay('17:00:00', '22:00:00'), 'Australia/Perth');
    $melbourne = restaurantOpen(everyDay('17:00:00', '22:00:00'));

    // 10:30 pm in Melbourne is 7:30 pm in Perth.
    $moment = melbourne('2026-10-05 22:30');

    expect(statusAt($perth, $moment)->isOpen)->toBeTrue()
        ->and(statusAt($melbourne, $moment)->isOpen)->toBeFalse();
});

describe('slots for scheduled orders', function () {
    /**
     * @return list<string>
     */
    function localSlots(Restaurant $restaurant, string $now, int $leadMinutes): array
    {
        return array_map(
            fn (CarbonImmutable $slot): string => $slot->setTimezone('Australia/Melbourne')->format('D j H:i'),
            app(OpeningHoursService::class)->slots($restaurant, melbourne($now), $leadMinutes),
        );
    }

    it('offers every quarter-hour from the lead time until closing', function () {
        $slots = localSlots(restaurantOpen([[1, '17:00:00', '22:00:00']]), '2026-10-05 21:07', 20);

        // 21:07 + 20 minutes is 21:27, so the first slot is 21:30; 22:00 is closing time. The
        // week ends at 21:07 next Monday.
        expect(array_slice($slots, 0, 3))->toBe(['Mon 5 21:30', 'Mon 5 21:45', 'Mon 12 17:00'])
            ->and(end($slots))->toBe('Mon 12 21:00');
    });

    it('starts at opening time when the lead time ends before it', function () {
        $slots = localSlots(restaurantOpen([[1, '17:00:00', '18:00:00']]), '2026-10-05 12:00', 20);

        expect($slots)->toBe(['Mon 5 17:00', 'Mon 5 17:15', 'Mon 5 17:30', 'Mon 5 17:45']);
    });

    it('offers times up to a week ahead', function () {
        $slots = localSlots(restaurantOpen(everyDay('17:00:00', '18:00:00')), '2026-10-05 18:00', 20);

        expect($slots)->toHaveCount(4 * 7)
            ->and($slots[0])->toBe('Tue 6 17:00')
            ->and(end($slots))->toBe('Mon 12 17:45');
    });

    it('offers times after midnight on a late shift', function () {
        $slots = localSlots(restaurantOpen([[6, '23:00:00', '00:30:00']]), '2026-10-10 22:00', 20);

        expect($slots)->toBe(['Sat 10 23:00', 'Sat 10 23:15', 'Sat 10 23:30', 'Sat 10 23:45', 'Sun 11 00:00', 'Sun 11 00:15']);
    });

    it('offers nothing on a day closed by special hours', function () {
        $restaurant = restaurantOpen([[1, '17:00:00', '18:00:00'], [2, '17:00:00', '17:30:00']]);
        $restaurant->specialHours()->create(['date' => '2026-10-05', 'is_closed' => true]);

        expect(localSlots($restaurant, '2026-10-05 09:00', 20))->toBe(['Tue 6 17:00', 'Tue 6 17:15']);
    });

    it('recognises exactly the offered times', function () {
        $restaurant = restaurantOpen(everyDay('17:00:00', '22:00:00'));
        $hours = app(OpeningHoursService::class);
        $now = melbourne('2026-10-05 18:00');

        expect($hours->isSlot($restaurant, melbourne('2026-10-05 18:30'), $now, 20))->toBeTrue()
            ->and($hours->isSlot($restaurant, melbourne('2026-10-05 18:15'), $now, 20))->toBeFalse()
            ->and($hours->isSlot($restaurant, melbourne('2026-10-05 18:35'), $now, 20))->toBeFalse();
    });
});
