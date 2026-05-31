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

use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Read-only detail view of a scheduled email. Reached by clicking a row in the
 * ListScheduledMail page. Records are created from the SendMail form, so the
 * "new" button is disabled; the record can still be deleted to cancel it.
 *
 * @author Ernesto Serrano <erseco@gmail.com>
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
    }
}
