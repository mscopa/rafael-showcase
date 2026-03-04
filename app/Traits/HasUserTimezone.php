<?php

namespace App\Traits;

use Carbon\Carbon;

/**
 * SHOWCASE: Scalability & DRY Principle
 *
 * @challenge Timezones are notoriously difficult in global SaaS applications, often leading to duplicated logic across models.
 * @solution Created a reusable Trait to standardize timezone conversions (UTC in the database, local time for the client) across all relevant Eloquent models.
 * @highlight Demonstrates attention to UX, scalability, and strict adherence to the DRY (Don't Repeat Yourself) principle.
 */
trait HasUserTimezone
{
    /**
     * Converts a UTC datetime (from the database) to the user's localized timezone.
     */
    public function formatToUserTz($dateTime, $format = 'Y-m-d H:i:s')
    {
        if (!$dateTime) {
            return null;
        }

        $tz = config('request.user_timezone', 'UTC');

        return Carbon::parse($dateTime, 'UTC')
            ->setTimezone($tz)
            ->format($format);
    }

    /**
     * Converts a localized datetime (from user input) to UTC.
     * Essential for consistent and safe database storage.
     */
    public function parseToUtc($dateTime)
    {
        if (!$dateTime) {
            return null;
        }

        $tz = config('request.user_timezone', 'UTC');

        return Carbon::parse($dateTime, $tz)
            ->setTimezone('UTC');
    }

    /**
     * Retrieves the start of the current day ("Today") relative to the user's specific timezone.
     */
    public static function userToday()
    {
        $tz = config('request.user_timezone', 'UTC');
        return Carbon::today($tz);
    }

    /**
     * Retrieves the current exact time ("Now") relative to the user's specific timezone.
     */
    public static function userNow()
    {
        $tz = config('request.user_timezone', 'UTC');
        return Carbon::now($tz);
    }
}