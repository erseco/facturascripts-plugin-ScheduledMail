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

namespace FacturaScripts\Plugins\ScheduledMail\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Plugins\ScheduledMail\Init;
use FacturaScripts\Plugins\ScheduledMail\Model\ScheduledMail;

/**
 * Detail view of a scheduled email. Reached by clicking a row in the
 * ListScheduledMail page. Records are created from the SendMail form, so the
 * "new" button is disabled; the record can still be deleted to cancel it.
 *
 * A still-pending email can be rescheduled (date) or have its recipient
 * corrected. Once it is sent/failed/cancelled the view stays read-only. The
 * date is validated by the model (ScheduleValidator, via test()) and a fresh
 * delayed work queue event is registered on save so delivery happens at the new
 * time; superseded events become harmless no-ops thanks to the worker guards.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class EditScheduledMail extends EditController
{
    public function getModelClassName(): string
    {
        return 'ScheduledMail';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'scheduled-mail';
        $data['icon'] = 'fa-regular fa-clock';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function createViews(): void
    {
        parent::createViews();

        // Scheduled emails are created from the SendMail form, not here.
        $this->setSettings($this->getMainViewName(), 'btnNew', false);

        // A scheduled email is an internal queue record, not a printable
        // document. Hide the print/email dropdown: its "Email" option would
        // reopen SendMail with modelClassName=ScheduledMail and crash, because
        // the core controller calls $model->load() which this model does not
        // implement.
        $this->setSettings($this->getMainViewName(), 'btnPrint', false);
    }

    /**
     * Re-registers the delayed work queue event when a pending email's date
     * changes, then delegates to the parent save flow.
     *
     * @return bool
     */
    protected function editAction()
    {
        // The persisted date before the form overwrites the model, used to
        // detect an actual reschedule.
        $previousScheduledAt = null;
        $code = $this->request->input('code', '');
        if ('' !== $code) {
            $persisted = new ScheduledMail();
            if ($persisted->loadFromCode($code) && $persisted->status === ScheduledMail::STATUS_PENDING) {
                $previousScheduledAt = $persisted->scheduled_at;
            }
        }

        if (false === parent::editAction()) {
            return false;
        }

        $mail = $this->getModel();
        if (
            null !== $previousScheduledAt
            && $mail->status === ScheduledMail::STATUS_PENDING
            && $mail->scheduled_at !== $previousScheduledAt
        ) {
            $this->rescheduleWorkEvent($mail);
        }

        return true;
    }

    /**
     * Registers a delayed work queue event for the email's current scheduled
     * time so the worker delivers it at the new date.
     *
     * @param ScheduledMail $mail
     */
    protected function rescheduleWorkEvent(ScheduledMail $mail): void
    {
        $delay = strtotime($mail->scheduled_at) - time();
        if ($delay < 0) {
            $delay = 0;
        }

        if (false === WorkQueue::sendFuture($delay, Init::WORK_EVENT, (string)$mail->id, ['id' => $mail->id])) {
            Tools::log()->error('scheduled-mail-no-worker');
        }
    }

    /**
     * Keeps the form read-only once the email is no longer pending.
     *
     * @param string $viewName
     * @param mixed  $view
     */
    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);

        if (
            $viewName !== $this->getMainViewName()
            || $view->model->status === ScheduledMail::STATUS_PENDING
        ) {
            return;
        }

        foreach (['scheduled-at', 'email-to', 'email-cc', 'email-bcc', 'subject', 'body'] as $column) {
            $view->disableColumn($column, false, 'true');
        }
        $this->setSettings($viewName, 'btnSave', false);
        $this->setSettings($viewName, 'btnUndo', false);
    }
}
