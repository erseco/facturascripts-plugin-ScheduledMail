<?php

/**
 * This file is part of ScheduledMail plugin for FacturaScripts.
 * Copyright (C) 2025 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\ScheduledMail\Lib;

/**
 * Pure validation of the "Schedule send" date/time. Kept free of FacturaScripts
 * dependencies so the scheduling rules (must be a valid future date within the
 * 30-day window) can be unit-tested in isolation.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class ScheduleValidator
{
    /** Maximum scheduling window in seconds (30 days). */
    public const MAX_SCHEDULE_SECONDS = 2592000;

    /** No error: the date is acceptable. */
    public const OK = '';

    /** The value is not a parseable date/time (translation key). */
    public const ERR_INVALID = 'scheduled-date-invalid';

    /** The date/time is now or in the past (translation key). */
    public const ERR_PAST = 'scheduled-date-in-past';

    /** The date/time is more than 30 days ahead (translation key). */
    public const ERR_TOO_FAR = 'scheduled-date-too-far';

    /**
     * Validates a datetime-local string against the current time.
     *
     * The input is interpreted in the current PHP timezone (FacturaScripts sets
     * it to the application timezone), consistent with strtotime()/time().
     *
     * @param string $input datetime-local value (e.g. "2026-06-15T10:30").
     * @param int    $now   Reference "now" as a Unix timestamp.
     *
     * @return array{timestamp: int|null, error: string} The parsed timestamp
     *                                                   when valid (error === self::OK), otherwise null + an error
     *                                                   key from the ERR_* constants.
     */
    public static function parse(string $input, int $now): array
    {
        $timestamp = strtotime(trim($input));
        if (false === $timestamp) {
            return ['timestamp' => null, 'error' => self::ERR_INVALID];
        }

        if ($timestamp <= $now) {
            return ['timestamp' => null, 'error' => self::ERR_PAST];
        }

        if ($timestamp - $now > self::MAX_SCHEDULE_SECONDS) {
            return ['timestamp' => null, 'error' => self::ERR_TOO_FAR];
        }

        return ['timestamp' => $timestamp, 'error' => self::OK];
    }
}
