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

use FacturaScripts\Core\Controller\SendMail as ParentController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Plugins\ScheduledMail\Init;
use FacturaScripts\Plugins\ScheduledMail\Model\ScheduledMail;

/**
 * Overrides the core SendMail controller to add optional scheduled sending.
 *
 * FacturaScripts loads this class instead of the core one because plugin
 * controllers take precedence over core controllers with the same name in the
 * Dinamic class system. The core controller is NOT modified.
 *
 * When the "Schedule send" datetime field (email-scheduled-at) is empty, the
 * behaviour is delegated to the parent controller unchanged (immediate send).
 * When a valid future date/time is provided, the email is persisted as a
 * ScheduledMail record, its attachments are copied to a plugin-owned folder
 * and a delayed work queue event is registered. The email is delivered later
 * by SendScheduledMailWorker when the work queue runs (cron).
 *
 * @author Ernesto Serrano <erseco@gmail.com>
 */
class SendMail extends ParentController
{
    /** Name of the optional datetime field added to the SendMail form. */
    public const SCHEDULE_FIELD = 'email-scheduled-at';

    /** Maximum scheduling window in seconds (30 days). */
    public const MAX_SCHEDULE_SECONDS = 2592000;

    /**
     * Intercepts the send action only when a schedule date is provided.
     *
     * @param string $action
     */
    protected function execAction(string $action): void
    {
        if ($action === 'send') {
            $scheduledInput = trim($this->request->input(self::SCHEDULE_FIELD, ''));
            if ($scheduledInput !== '') {
                $this->execScheduleAction($scheduledInput);
                return;
            }
        }

        // No schedule date (or any other action): unchanged core behaviour.
        parent::execAction($action);
    }

    /**
     * Validates and schedules the email, or re-renders the form on error.
     *
     * @param string $scheduledInput
     */
    protected function execScheduleAction(string $scheduledInput): void
    {
        if (false === $this->validateFormToken()) {
            // Token already consumed/invalid: re-render with a fresh form.
            parent::execAction('');
            return;
        }

        $timestamp = $this->parseScheduleDate($scheduledInput);
        if (null === $timestamp) {
            // Validation error already logged. Re-render the form keeping the
            // user content (parent default action repopulates from the POST).
            parent::execAction('');
            return;
        }

        if ($this->scheduleMail($timestamp)) {
            Tools::log()->notice('scheduled-mail-ok');
            $this->redirectAfter();
            return;
        }

        Tools::log()->error('scheduled-mail-error');
        parent::execAction('');
    }

    /**
     * Parses the datetime-local value (interpreted in the application timezone)
     * and validates it against the allowed scheduling window.
     *
     * @param string $scheduledInput
     *
     * @return int|null Unix timestamp, or null if the value is not acceptable.
     */
    protected function parseScheduleDate(string $scheduledInput): ?int
    {
        $timestamp = strtotime($scheduledInput);
        if (false === $timestamp) {
            Tools::log()->error('scheduled-date-invalid');
            return null;
        }

        $now = time();
        if ($timestamp <= $now) {
            Tools::log()->error('scheduled-date-in-past');
            return null;
        }

        if ($timestamp - $now > self::MAX_SCHEDULE_SECONDS) {
            Tools::log()->error('scheduled-date-too-far');
            return null;
        }

        return $timestamp;
    }

    /**
     * Persists the scheduled email, copies its attachments and registers the
     * delayed work queue event.
     *
     * @param int $timestamp
     *
     * @return bool
     */
    protected function scheduleMail(int $timestamp): bool
    {
        $emailFrom = $this->request->input('email-from', '');
        if (false === Validator::email($emailFrom)) {
            Tools::log()->error('invalid-email-from', ['%email%' => htmlspecialchars($emailFrom)]);
            return false;
        }

        // Gather and validate the recipients using the same helpers the core uses.
        $to = $this->validEmails('email-to');
        $cc = $this->validEmails('email-cc');
        $bcc = $this->validEmails('email-bcc');
        if (null === $to || null === $cc || null === $bcc) {
            // validEmails() already logged the specific invalid address.
            return false;
        }
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
        $mail->subject = $this->request->input('email-subject', '');
        $mail->body = $this->request->input('email-body', '');
        $mail->reply_to = (bool)$this->request->input('replyto', '0');
        $mail->nick = $this->user->nick;
        $mail->model_class_name = $this->request->queryOrInput('modelClassName', '') ?: null;
        $mail->model_code = $this->request->queryOrInput('modelCode', '') ?: null;
        $mail->model_codes = $this->request->queryOrInput('modelCodes', '') ?: null;

        if (false === $mail->save()) {
            Tools::log()->error('scheduled-mail-error');
            return false;
        }

        // Copy attachments to a persistent folder so they survive until delivery.
        $mail->setAttachments($this->persistAttachments($mail));
        $mail->save();

        // Register the delayed work queue event. sendFuture() only stores the
        // event when a worker matches the event name (registered in Init).
        $delay = $timestamp - time();
        if (false === WorkQueue::sendFuture($delay, Init::WORK_EVENT, (string)$mail->id, ['id' => $mail->id])) {
            $mail->status = ScheduledMail::STATUS_FAILED;
            $mail->error = 'Could not register the work queue event. Is a worker available?';
            $mail->save();
            Tools::log()->error('scheduled-mail-no-worker');
            return false;
        }

        return true;
    }

    /**
     * Copies the generated document PDF and the uploaded files into the
     * record's persistent folder and returns the attachment descriptors.
     *
     * @param ScheduledMail $mail
     *
     * @return array Array of ['file' => storedName, 'name' => originalName].
     */
    protected function persistAttachments(ScheduledMail $mail): array
    {
        $folder = $mail->getFilesFolder();
        Tools::folderCheckOrCreate($folder);

        $attachments = [];

        // The document PDF generated by the source controller lives in the
        // shared temporary email folder, which core prunes after 30 days.
        $fileName = $this->request->queryOrInput('fileName', '');
        if (!empty($fileName)) {
            $source = Tools::folder(NewMail::ATTACHMENTS_TMP_PATH, $fileName);
            if (file_exists($source) && copy($source, $folder . DIRECTORY_SEPARATOR . $fileName)) {
                $attachments[] = ['file' => $fileName, 'name' => $fileName];
            }
        }

        // User uploaded files.
        foreach ($this->request->files->getArray('uploads') as $file) {
            $original = $file->getClientOriginalName();
            if ($file->move($folder, $original)) {
                $attachments[] = ['file' => $original, 'name' => $original];
            }
        }

        return $attachments;
    }

    /**
     * Returns the validated, non-empty emails of a field, or null if one of
     * them is invalid (the specific error is logged).
     *
     * @param string $field
     *
     * @return array|null
     */
    protected function validEmails(string $field): ?array
    {
        $result = [];
        foreach ($this->getEmails($field) as $email) {
            if (empty($email)) {
                continue;
            }
            if (false === Validator::email($email)) {
                Tools::log()->error('invalid-' . $field, ['%email%' => htmlspecialchars($email)]);
                return null;
            }
            $result[] = $email;
        }

        return $result;
    }
}
