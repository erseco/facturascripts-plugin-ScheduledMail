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

use Closure;
use FacturaScripts\Plugins\ScheduledMail\Extension\Controller\SendMail;
use FacturaScripts\Plugins\ScheduledMail\Lib\ScheduledMailScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SendMail controller extension.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class SendMailExtensionTest extends TestCase
{
    public function testExecActionReturnsClosure(): void
    {
        $extension = new SendMail();
        $this->assertInstanceOf(Closure::class, $extension->execAction());
    }

    public function testScheduleActionName(): void
    {
        $this->assertSame('schedule', ScheduledMailScheduler::ACTION);
        $this->assertSame('email-scheduled-at', ScheduledMailScheduler::SCHEDULE_FIELD);
    }
}
