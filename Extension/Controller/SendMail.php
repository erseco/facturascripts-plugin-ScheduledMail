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

namespace FacturaScripts\Plugins\ScheduledMail\Extension\Controller;

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ScheduledMail\Lib\ScheduleValidator;
use FacturaScripts\Plugins\ScheduledMail\Lib\ScheduledMailScheduler;

/**
 * Handles the custom schedule action after the core SendMail controller has
 * prepared the email and its attachments.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class SendMail
{
    /**
     * Schedules the prepared email when the form submits action=schedule.
     */
    public function execAction(): Closure
    {
        return function (string $action): void {
            if ($action !== ScheduledMailScheduler::ACTION) {
                return;
            }

            if (false === $this->validateFormToken()) {
                return;
            }

            $scheduledInput = trim($this->request->input(ScheduledMailScheduler::SCHEDULE_FIELD, ''));
            $result = ScheduleValidator::parse($scheduledInput, time());
            if ($result['error'] !== ScheduleValidator::OK) {
                Tools::log()->error($result['error']);
                return;
            }

            if (ScheduledMailScheduler::schedule(
                $this->newMail,
                $this->request,
                $this->user,
                $result['timestamp']
            )) {
                Tools::log()->notice('scheduled-mail-ok');
                $this->redirectAfter();
                return;
            }

            Tools::log()->error('scheduled-mail-error');
        };
    }
}
