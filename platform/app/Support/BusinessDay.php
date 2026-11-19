<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Converts between stored UTC instants and Philippine business days.
 * Always store UTC; use this only to decide which business day an instant belongs to.
 */
final class BusinessDay
{
    public static function timezone(): string
    {
        return config('thryve.business_timezone');
    }

    /** Business date (Y-m-d) an instant falls on. */
    public static function dateOf(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::timezone())->toDateString();
    }

    public static function today(): string
    {
        return self::dateOf(CarbonImmutable::now());
    }

    /** UTC instant at which the given business date starts (inclusive). */
    public static function startUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->startOfDay()->utc();
    }

    /** UTC instant at which the next business date starts (exclusive end). */
    public static function endUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->addDay()->startOfDay()->utc();
    }
}
