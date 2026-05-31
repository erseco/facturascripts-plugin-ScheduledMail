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

namespace FacturaScripts\Plugins\ScheduledMail\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ScheduledMail\Model\ScheduledMail;

/**
 * List and manage scheduled emails. Allows cancelling pending entries.
 *
 * @author Ernesto Serrano <erseco@gmail.com>
 */
class ListScheduledMail extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'scheduled-mails';
        $data['icon'] = 'fa-regular fa-clock';
        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewScheduledMail();
    }

    protected function createViewScheduledMail(string $viewName = 'ListScheduledMail'): void
    {
        $this->addView($viewName, 'ScheduledMail', 'scheduled-mails', 'fa-regular fa-clock');
        $this->addOrderBy($viewName, ['scheduled_at'], 'scheduled-at', 2);
        $this->addOrderBy($viewName, ['creation_date'], 'creation-date');
        $this->addOrderBy($viewName, ['status'], 'status');
        $this->addSearchFields($viewName, ['email_to', 'subject', 'error']);

        // Status filter.
        $statusValues = [['label' => Tools::lang()->trans('all'), 'where' => []]];
        $statuses = [
            ScheduledMail::STATUS_PENDING => 'pending',
            ScheduledMail::STATUS_SENT => 'sent',
            ScheduledMail::STATUS_FAILED => 'failed',
            ScheduledMail::STATUS_CANCELLED => 'cancelled',
        ];
        foreach ($statuses as $value => $label) {
            $statusValues[] = [
                'label' => Tools::lang()->trans($label),
                'where' => [new DataBaseWhere('status', $value)],
            ];
        }
        $this->addFilterSelectWhere($viewName, 'status', $statusValues);
        $this->addFilterPeriod($viewName, 'scheduled', 'scheduled-at', 'scheduled_at', true);

        // Cancel button (operates on the checked rows).
        $this->addButton($viewName, [
            'action' => 'cancel-scheduled',
            'color' => 'warning',
            'confirm' => 'true',
            'icon' => 'fa-solid fa-ban',
            'label' => 'cancel',
            'type' => 'action',
        ]);
    }

    /**
     * @param string $action
     *
     * @return bool
     */
    protected function execPreviousAction($action)
    {
        if ($action === 'cancel-scheduled') {
            $this->cancelScheduledAction();
        }

        return parent::execPreviousAction($action);
    }

    /**
     * Cancels the checked pending scheduled emails and removes their files.
     */
    protected function cancelScheduledAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        }
        if (false === $this->validateFormToken()) {
            return;
        }

        $codes = $this->request->request->getArray('codes');
        if (empty($codes)) {
            return;
        }

        $cancelled = 0;
        foreach ($codes as $code) {
            $mail = new ScheduledMail();
            if (false === $mail->load($code)) {
                continue;
            }

            // Only pending emails can be cancelled.
            if ($mail->status !== ScheduledMail::STATUS_PENDING) {
                continue;
            }

            $mail->status = ScheduledMail::STATUS_CANCELLED;
            if ($mail->save()) {
                $folder = $mail->getFilesFolder();
                if (is_dir($folder)) {
                    Tools::folderDelete($folder);
                }
                $cancelled++;
            }
        }

        if ($cancelled > 0) {
            Tools::log()->notice('scheduled-mail-cancelled', ['%count%' => $cancelled]);
        }
    }
}
