<?php

namespace App\Services;

use App\Data\OpeningStatus;
use App\Data\Shift;
use App\Models\OpeningHour;
use App\Models\Restaurant;
use App\Models\SpecialHour;
use Carbon\CarbonImmutable;

/**
 * Opening hours, interpreted in the restaurant's own timezone.
 *
 * A shift belongs to the local date it opens on. One that closes at or before its opening
 * time runs past midnight, so the previous evening's shift can still be open early today.
 * A special-hours entry replaces that date's regular shifts: closed all day, or one shift
 * with different times. Shifts that touch or overlap are merged, so "open until" is the end
 * of the whole block.
 */
final class OpeningHoursService
{
    /** How far ahead to look for the next opening. */
    private const LOOKAHEAD_DAYS = 14;

    /** Scheduled orders are for a local quarter-hour… */
    public const SLOT_MINUTES = 15;

    /** …up to a week ahead. */
    public const SCHEDULE_DAYS = 7;

    public function status(Restaurant $restaurant, CarbonImmutable $at): OpeningStatus
    {
        $shifts = $this->shiftsBetween($restaurant, $at, $at->addDays(self::LOOKAHEAD_DAYS));

        foreach ($shifts as $shift) {
            if ($shift->contains($at)) {
                return new OpeningStatus(isOpen: true, closesAt: $shift->closesAt, nextOpeningAt: null);
            }

            if ($shift->opensAt->greaterThan($at)) {
                return new OpeningStatus(isOpen: false, closesAt: null, nextOpeningAt: $shift->opensAt);
            }
        }

        return new OpeningStatus(isOpen: false, closesAt: null, nextOpeningAt: null);
    }

    public function isOpenAt(Restaurant $restaurant, CarbonImmutable $at): bool
    {
        return $this->status($restaurant, $at)->isOpen;
    }

    /**
     * The times a scheduled order can be placed for: every local quarter-hour while the
     * restaurant is open, from $leadMinutes after $now (the time the kitchen, or the kitchen
     * and the driver, need) until a week ahead.
     *
     * @return list<CarbonImmutable>
     */
    public function slots(Restaurant $restaurant, CarbonImmutable $now, int $leadMinutes): array
    {
        $earliest = $now->addMinutes($leadMinutes);
        $latest = $now->addDays(self::SCHEDULE_DAYS);
        $slots = [];

        foreach ($this->shiftsBetween($restaurant, $earliest, $latest) as $shift) {
            $first = $this->nextQuarterHour($shift->opensAt->greaterThan($earliest) ? $shift->opensAt : $earliest, $restaurant->timezone);

            for ($slot = $first; $slot->lessThan($shift->closesAt) && $slot->lessThanOrEqualTo($latest); $slot = $slot->addMinutes(self::SLOT_MINUTES)) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * Whether $at is one of the times offered by slots().
     */
    public function isSlot(Restaurant $restaurant, CarbonImmutable $at, CarbonImmutable $now, int $leadMinutes): bool
    {
        foreach ($this->slots($restaurant, $now, $leadMinutes) as $slot) {
            if ($slot->equalTo($at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The merged shifts that overlap the period from $from to $until, in time order.
     *
     * @return list<Shift>
     */
    public function shiftsBetween(Restaurant $restaurant, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $timezone = $restaurant->timezone;
        // Start a day early: the previous evening's shift may run past midnight into $from.
        $firstDate = $from->setTimezone($timezone)->startOfDay()->subDay();
        $lastDate = $until->setTimezone($timezone)->startOfDay();

        $regular = $restaurant->openingHours->groupBy('day_of_week');
        $special = $restaurant->specialHours()
            ->whereBetween('date', [$firstDate->toDateString(), $lastDate->toDateString()])
            ->get()
            ->keyBy(fn (SpecialHour $day): string => $day->date->toDateString());

        $shifts = [];

        for ($date = $firstDate; $date->lessThanOrEqualTo($lastDate); $date = $date->addDay()) {
            $override = $special->get($date->toDateString());

            if ($override instanceof SpecialHour) {
                if (! $override->is_closed && $override->opens_at !== null && $override->closes_at !== null) {
                    $shifts[] = $this->shiftOn($date, $override->opens_at, $override->closes_at, $timezone);
                }

                continue;
            }

            /** @var OpeningHour $hours */
            foreach ($regular->get($date->dayOfWeek, []) as $hours) {
                $shifts[] = $this->shiftOn($date, $hours->opens_at, $hours->closes_at, $timezone);
            }
        }

        return array_values(array_filter(
            $this->merge($shifts),
            fn (Shift $shift): bool => $shift->closesAt->greaterThan($from) && $shift->opensAt->lessThan($until),
        ));
    }

    /**
     * Builds a shift from local wall-clock times on a local date, then converts it to UTC.
     * Parsing each end from its own date string keeps daylight-saving changes correct.
     */
    private function shiftOn(CarbonImmutable $date, string $opensAt, string $closesAt, string $timezone): Shift
    {
        $opens = self::normaliseTime($opensAt);
        $closes = self::normaliseTime($closesAt);
        $closingDate = $closes <= $opens ? $date->addDay() : $date;

        return new Shift(
            CarbonImmutable::parse($date->toDateString().' '.$opens, $timezone)->utc(),
            CarbonImmutable::parse($closingDate->toDateString().' '.$closes, $timezone)->utc(),
        );
    }

    /**
     * @param  list<Shift>  $shifts
     * @return list<Shift>
     */
    private function merge(array $shifts): array
    {
        usort($shifts, fn (Shift $a, Shift $b): int => $a->opensAt <=> $b->opensAt);

        $merged = [];

        foreach ($shifts as $shift) {
            $last = array_pop($merged);

            if ($last === null) {
                $merged[] = $shift;
            } elseif ($shift->opensAt->lessThanOrEqualTo($last->closesAt)) {
                $merged[] = new Shift(
                    $last->opensAt,
                    $shift->closesAt->greaterThan($last->closesAt) ? $shift->closesAt : $last->closesAt,
                );
            } else {
                $merged[] = $last;
                $merged[] = $shift;
            }
        }

        return $merged;
    }

    /**
     * $at, or the next quarter-hour of local time after it (10:07 → 10:15). Local, because a
     * few timezones sit on :30 or :45 offsets from UTC.
     */
    private function nextQuarterHour(CarbonImmutable $at, string $timezone): CarbonImmutable
    {
        $local = $at->setTimezone($timezone);
        $past = $local->minute % self::SLOT_MINUTES;

        if ($past === 0 && $local->second === 0 && $local->microsecond === 0) {
            return $at->utc();
        }

        return $local->setTime($local->hour, $local->minute - $past)->addMinutes(self::SLOT_MINUTES)->utc();
    }

    /**
     * MySQL returns TIME columns as HH:MM:SS; hand-written values may be HH:MM.
     */
    private static function normaliseTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
