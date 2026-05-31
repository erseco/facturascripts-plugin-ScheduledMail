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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\ScheduledMail\Lib\ScheduleValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the schedule date/time validation rules.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class ScheduleValidatorTest extends TestCase
{
    private const NOW = 1780000000; // fixed reference timestamp

    public function testRejectsInvalidDate(): void
    {
        $result = ScheduleValidator::parse('not-a-date', self::NOW);
        $this->assertNull($result['timestamp']);
        $this->assertSame(ScheduleValidator::ERR_INVALID, $result['error']);
    }

    public function testRejectsEmptyString(): void
    {
        $result = ScheduleValidator::parse('   ', self::NOW);
        $this->assertNull($result['timestamp']);
        $this->assertSame(ScheduleValidator::ERR_INVALID, $result['error']);
    }

    public function testRejectsPastDate(): void
    {
        $result = ScheduleValidator::parse('@' . (self::NOW - 60), self::NOW);
        $this->assertNull($result['timestamp']);
        $this->assertSame(ScheduleValidator::ERR_PAST, $result['error']);
    }

    public function testRejectsExactlyNow(): void
    {
        $result = ScheduleValidator::parse('@' . self::NOW, self::NOW);
        $this->assertNull($result['timestamp']);
        $this->assertSame(ScheduleValidator::ERR_PAST, $result['error']);
    }

    public function testAcceptsNearFuture(): void
    {
        $when = self::NOW + 300;
        $result = ScheduleValidator::parse('@' . $when, self::NOW);
        $this->assertSame($when, $result['timestamp']);
        $this->assertSame(ScheduleValidator::OK, $result['error']);
    }

    public function testAcceptsExactlyThirtyDays(): void
    {
        $when = self::NOW + ScheduleValidator::MAX_SCHEDULE_SECONDS;
        $result = ScheduleValidator::parse('@' . $when, self::NOW);
        $this->assertSame($when, $result['timestamp']);
        $this->assertSame(ScheduleValidator::OK, $result['error']);
    }

    public function testRejectsBeyondThirtyDays(): void
    {
        $when = self::NOW + ScheduleValidator::MAX_SCHEDULE_SECONDS + 1;
        $result = ScheduleValidator::parse('@' . $when, self::NOW);
        $this->assertNull($result['timestamp']);
        $this->assertSame(ScheduleValidator::ERR_TOO_FAR, $result['error']);
    }

    public function testParsesIsoDatetimeLocalString(): void
    {
        // strtotime understands the datetime-local "Y-m-d\TH:i" format.
        $when = strtotime('2026-06-15T10:30');
        $result = ScheduleValidator::parse('2026-06-15T10:30', $when - 600);
        $this->assertSame($when, $result['timestamp']);
        $this->assertSame(ScheduleValidator::OK, $result['error']);
    }
}
