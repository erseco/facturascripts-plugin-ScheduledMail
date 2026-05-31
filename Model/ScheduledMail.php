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

namespace FacturaScripts\Plugins\ScheduledMail\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;

/**
 * A scheduled email waiting to be delivered by the work queue.
 *
 * The table structure is defined in Table/scheduled_mails.xml.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
class ScheduledMail extends ModelClass
{
    use ModelTrait;

    /** Email waiting to be sent. */
    public const STATUS_PENDING = 'pending';

    /** Email already delivered. */
    public const STATUS_SENT = 'sent';

    /** Delivery failed (see the error column). */
    public const STATUS_FAILED = 'failed';

    /** Folder, relative to FS_FOLDER, where scheduled attachments are stored. */
    public const FILES_PATH = 'MyFiles/ScheduledMail';

    /** @var string|null */
    public $attachments_json;

    /** @var string|null */
    public $body;

    /** @var string|null */
    public $creation_date;

    /** @var string|null */
    public $email_bcc;

    /** @var string|null */
    public $email_cc;

    /** @var string|null */
    public $email_from;

    /** @var string */
    public $email_to;

    /** @var string|null */
    public $error;

    /** @var int */
    public $id;

    /** @var int|null */
    public $idworkevent;

    /** @var string|null */
    public $model_class_name;

    /** @var string|null */
    public $model_code;

    /** @var string|null */
    public $model_codes;

    /** @var string|null */
    public $nick;

    /** @var bool */
    public $reply_to;

    /** @var string */
    public $scheduled_at;

    /** @var string|null */
    public $sent_at;

    /** @var string */
    public $status;

    /** @var string|null */
    public $subject;

    public function clear(): void
    {
        parent::clear();
        $this->status = self::STATUS_PENDING;
        $this->creation_date = Tools::dateTime();
        $this->reply_to = false;
    }

    /**
     * Deleting a scheduled email also removes its stored attachment files.
     * This is how a pending email is cancelled: once the record is gone, the
     * worker simply skips the (now missing) record when the event fires.
     *
     * @return bool
     */
    public function delete(): bool
    {
        $folder = $this->getFilesFolder();
        if (false === parent::delete()) {
            return false;
        }

        if (is_dir($folder)) {
            Tools::folderDelete($folder);
        }

        return true;
    }

    /**
     * Returns the decoded list of attachments as an array of
     * ['path' => ..., 'name' => ...] entries.
     *
     * @return array
     */
    public function getAttachments(): array
    {
        if (empty($this->attachments_json)) {
            return [];
        }

        $decoded = json_decode($this->attachments_json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Absolute path to the folder that stores this record's attachments.
     *
     * @return string
     */
    public function getFilesFolder(): string
    {
        return Tools::folder(self::FILES_PATH, (string)$this->id);
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'scheduled_mails';
    }

    public function test(): bool
    {
        $this->email_to = trim((string)$this->email_to);
        if (empty($this->email_to)) {
            Tools::log()->warning('scheduled-mail-no-recipients');
            return false;
        }

        if (empty($this->scheduled_at)) {
            Tools::log()->warning('scheduled-mail-no-date');
            return false;
        }

        $this->subject = Tools::noHtml($this->subject);

        return parent::test();
    }

    /**
     * Stores the given attachments list as JSON.
     *
     * @param array $attachments
     */
    public function setAttachments(array $attachments): void
    {
        $this->attachments_json = empty($attachments) ? null : json_encode(array_values($attachments));
    }
}
