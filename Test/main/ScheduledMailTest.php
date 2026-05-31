<?php

/**
 * This file is part of ScheduledMail plugin for FacturaScripts.
 * Copyright (C) 2025 Ernesto Serrano <erseco@gmail.com>
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

use FacturaScripts\Plugins\ScheduledMail\Model\ScheduledMail;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ScheduledMail model.
 *
 * These are lightweight tests that do not require a database connection.
 *
 * @author Ernesto Serrano <erseco@gmail.com>
 */
final class ScheduledMailTest extends TestCase
{
    public function testCanInstantiate(): void
    {
        $model = new ScheduledMail();
        $this->assertInstanceOf(ScheduledMail::class, $model);
    }

    public function testDefaultStatusIsPending(): void
    {
        $model = new ScheduledMail();
        $this->assertSame(ScheduledMail::STATUS_PENDING, $model->status);
        $this->assertFalse($model->reply_to);
    }

    public function testTableName(): void
    {
        $this->assertSame('scheduled_mails', ScheduledMail::tableName());
        $this->assertSame('id', ScheduledMail::primaryColumn());
    }

    public function testAttachmentsRoundTrip(): void
    {
        $model = new ScheduledMail();
        $this->assertSame([], $model->getAttachments());

        $attachments = [
            ['file' => 'invoice.pdf', 'name' => 'invoice.pdf'],
            ['file' => 'terms.pdf', 'name' => 'Terms and conditions.pdf'],
        ];
        $model->setAttachments($attachments);
        $this->assertSame($attachments, $model->getAttachments());

        $model->setAttachments([]);
        $this->assertNull($model->attachments_json);
        $this->assertSame([], $model->getAttachments());
    }
}
