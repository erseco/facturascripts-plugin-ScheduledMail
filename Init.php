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

namespace FacturaScripts\Plugins\ScheduledMail;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\WorkQueue;

/**
 * Plugin initialization class.
 *
 * Registers the worker that delivers scheduled emails. The SendMail form is
 * extended in two ways that do NOT require registration here:
 *  - The datetime field and button JavaScript are injected through the core
 *    "include views" mechanism (Extension/View/SendMail_beforeEnd_100.html.twig).
 *  - The send flow is intercepted by overriding the core controller with a
 *    same-named class (Controller/SendMail.php), which FacturaScripts loads
 *    instead of the core one via the Dinamic class system.
 *
 * @author Ernesto Serrano <erseco@gmail.com>
 */
class Init extends InitClass
{
    /**
     * Event name used for the delayed work queue events.
     */
    public const WORK_EVENT = 'ScheduledMail.Send';

    /**
     * Called on every request while the plugin is enabled.
     *
     * @return void
     */
    public function init(): void
    {
        // Register the worker that picks up the delayed work queue events and
        // sends the scheduled emails. WorkQueue::sendFuture() only stores an
        // event when a worker matches the event name, so this is required.
        WorkQueue::addWorker('SendScheduledMailWorker', self::WORK_EVENT);
    }

    /**
     * Called when the plugin version changes.
     *
     * @return void
     */
    public function update(): void
    {
        // No data migrations needed; the scheduled_mails table is created
        // automatically from Table/scheduled_mails.xml.
    }

    /**
     * Called when the plugin is uninstalled.
     *
     * @return void
     */
    public function uninstall(): void
    {
        // Nothing to clean up here. Pending scheduled emails and their stored
        // attachments remain in MyFiles/ScheduledMail/ for manual review.
    }
}
