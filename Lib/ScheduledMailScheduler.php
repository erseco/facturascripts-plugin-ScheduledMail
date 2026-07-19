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

namespace FacturaScripts\Plugins\ScheduledMail\Lib;

use FacturaScripts\Core\Request;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\ScheduledMail\Init;
use FacturaScripts\Plugins\ScheduledMail\Model\ScheduledMail;

/**
 * Persists an email prepared by the core SendMail controller and queues its
 * delayed delivery.
 *
 * @author Ernesto Serrano <info@ernesto.es>
 */
final class ScheduledMailScheduler
{
    /** Custom form action used for scheduled delivery. */
    public const ACTION = 'schedule';

    /** Name of the optional datetime field added to the SendMail form. */
    public const SCHEDULE_FIELD = 'email-scheduled-at';

    /**
     * Persists the scheduled email and registers its delayed work queue event.
     */
    public static function schedule(NewMail $newMail, Request $request, User $user, int $timestamp): bool
    {
        $emailFrom = $request->input('email-from', '');
        if (false === Validator::email($emailFrom)) {
            Tools::log()->error('invalid-email-from', ['%email%' => htmlspecialchars($emailFrom)]);
            return false;
        }

        if (false === self::validateRequestEmails($request)) {
            return false;
        }

        $to = $newMail->getToAddresses();
        $cc = $newMail->getCCAddresses();
        $bcc = $newMail->getBCCAddresses();
        if (empty($to)) {
            Tools::log()->error('scheduled-mail-no-recipients');
            return false;
        }

        $mail = new ScheduledMail();
        $mail->scheduled_at = Tools::dateTime(date('Y-m-d H:i:s', $timestamp));
        $mail->email_from = $emailFrom;
        $mail->email_to = implode(', ', $to);
        $mail->email_cc = empty($cc) ? null : implode(', ', $cc);
        $mail->email_bcc = empty($bcc) ? null : implode(', ', $bcc);
        $mail->subject = $newMail->title;
        $mail->body = $newMail->text;
        $mail->reply_to = (bool) $request->input('replyto', '0');
        $mail->nick = $user->nick;
        $mail->model_class_name = $request->queryOrInput('modelClassName', '') ?: null;
        $mail->model_code = $request->queryOrInput('modelCode', '') ?: null;
        $mail->model_codes = $request->queryOrInput('modelCodes', '') ?: null;

        if (false === $mail->save()) {
            return false;
        }

        $mail->setAttachments(self::persistAttachments($mail, $newMail->getAttachmentNames()));
        if (false === $mail->save()) {
            return false;
        }

        $delay = $timestamp - time();
        if (false === WorkQueue::sendFuture($delay, Init::WORK_EVENT, (string) $mail->id, ['id' => $mail->id])) {
            $mail->status = ScheduledMail::STATUS_FAILED;
            $mail->error = 'Could not register the work queue event. Is a worker available?';
            $mail->save();
            Tools::log()->error('scheduled-mail-no-worker');
            return false;
        }

        return true;
    }

    /**
     * Copies the attachments prepared by the core controller to persistent
     * plugin-owned storage.
     */
    private static function persistAttachments(ScheduledMail $mail, array $attachmentNames): array
    {
        $folder = $mail->getFilesFolder();
        Tools::folderCheckOrCreate($folder);

        $attachments = [];
        foreach (array_unique($attachmentNames) as $attachmentName) {
            $storedName = basename($attachmentName);
            $source = Tools::folder(NewMail::ATTACHMENTS_TMP_PATH, $storedName);
            $destination = $folder . DIRECTORY_SEPARATOR . $storedName;
            if (is_file($source) && copy($source, $destination)) {
                $attachments[] = ['file' => $storedName, 'name' => $attachmentName];
            }
        }

        return $attachments;
    }

    /**
     * Validates the recipient fields before using the addresses prepared by the
     * core controller and any other controller extensions.
     */
    private static function validateRequestEmails(Request $request): bool
    {
        foreach (['email-to', 'email-cc', 'email-bcc'] as $field) {
            foreach (NewMail::splitEmails($request->input($field, '')) as $email) {
                if (!empty($email) && false === Validator::email($email)) {
                    Tools::log()->error('invalid-' . $field, ['%email%' => htmlspecialchars($email)]);
                    return false;
                }
            }
        }

        return true;
    }
}
