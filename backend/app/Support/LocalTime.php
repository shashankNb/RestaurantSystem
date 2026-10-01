<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Moments in words, in the restaurant's timezone, for customer-facing messages.
 */
final class LocalTime
{
    /**
     * "today at 5 pm", "tomorrow at 11:30 am", "on Friday at 5 pm".
     */
    public static function describe(CarbonImmutable $at, string $timezone, CarbonImmutable $now): string
    {
        $local = $at->setTimezone($timezone);
        $day = $local->startOfDay();
        $today = $now->setTimezone($timezone)->startOfDay();

        $when = match (true) {
            $day->equalTo($today) => 'today',
            $day->equalTo($today->addDay()) => 'tomorrow',
            default => 'on '.$local->format('l j F'),
        };

        return $when.' at '.self::time($local);
    }

    /** "5 pm" or "5:30 pm". */
    public static function time(CarbonImmutable $local): string
    {
        return $local->minute === 0 ? $local->format('g a') : $local->format('g:i a');
    }
}
