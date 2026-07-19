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

namespace FacturaScripts\Plugins\ScheduledMail;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Plugins\ScheduledMail\Extension\Controller\SendMail;

/**
 * Plugin initialization class.
 *
 * Registers the SendMail controller extension and the worker that delivers
 * scheduled emails. The datetime field and button JavaScript are injected
 * through the core include views mechanism.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class Init extends InitClass
{
    /**
     * Event name used for the delayed work queue events.
     */
    public const WORK_EVENT = 'ScheduledMail.Send';

    /**
     * Called on every request while the plugin is enabled.
     */
    public function init(): void
    {
        $this->loadExtension(new SendMail());
        WorkQueue::addWorker('SendScheduledMailWorker', self::WORK_EVENT);
    }

    /**
     * Called when the plugin version changes.
     */
    public function update(): void
    {
        // No data migrations needed; the scheduled_mails table is created
        // automatically from Table/scheduled_mails.xml.
    }

    /**
     * Called when the plugin is uninstalled.
     */
    public function uninstall(): void
    {
        // Nothing to clean up here. Pending scheduled emails and their stored
        // attachments remain in MyFiles/ScheduledMail/ for manual review.
    }
}
