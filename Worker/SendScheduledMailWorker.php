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

namespace FacturaScripts\Plugins\ScheduledMail\Worker;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\ScheduledMail\Model\ScheduledMail;
use Throwable;

/**
 * Delivers scheduled emails when the work queue reaches their scheduled time.
 *
 * The matching work queue event only carries the ScheduledMail record id; the
 * actual email data is rebuilt from the persisted record so the work event
 * stays small.
 *
 * @author Ernesto Serrano <erseco@gmail.com>
 */
class SendScheduledMailWorker extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        $id = $event->param('id') ?? $event->value;

        $mail = new ScheduledMail();
        if (empty($id) || false === $mail->loadFromCode($id)) {
            // Nothing to do; the record was deleted (this is how a user cancels).
            return $this->done();
        }

        // Ignore records already handled.
        if ($mail->status !== ScheduledMail::STATUS_PENDING) {
            return $this->done();
        }

        // Safety net: should always hold because the work event creation_date
        // equals scheduled_at, so the queue does not pick it up earlier.
        if (strtotime($mail->scheduled_at) > time()) {
            return $this->done();
        }

        try {
            if ($this->deliver($mail)) {
                $mail->status = ScheduledMail::STATUS_SENT;
                $mail->sent_at = Tools::dateTime();
                $mail->error = null;
                $mail->save();

                $this->updateFemail($mail);
                $this->deleteFiles($mail);
            } else {
                $this->markFailed($mail, 'NewMail::send() returned false. Check the SMTP configuration.');
            }
        } catch (Throwable $exception) {
            $this->markFailed($mail, $exception->getMessage());
        }

        return $this->done();
    }

    /**
     * Rebuilds and sends the email using the core NewMail abstraction.
     *
     * @param ScheduledMail $mail
     *
     * @return bool
     */
    protected function deliver(ScheduledMail $mail): bool
    {
        $newMail = NewMail::create();

        // Restore the sender user (signature, reply-to, history nick).
        $user = null;
        if (!empty($mail->nick)) {
            $candidate = new User();
            if ($candidate->loadFromCode($mail->nick)) {
                $user = $candidate;
                $newMail->setUser($user);
            }
        }

        if (!empty($mail->email_from)) {
            $newMail->setMailbox($mail->email_from);
        }

        $newMail->subject((string)$mail->subject)
            ->body((string)$mail->body);

        foreach (NewMail::splitEmails((string)$mail->email_to) as $email) {
            $newMail->to($email);
        }
        foreach (NewMail::splitEmails((string)$mail->email_cc) as $email) {
            $newMail->cc($email);
        }
        foreach (NewMail::splitEmails((string)$mail->email_bcc) as $email) {
            $newMail->bcc($email);
        }

        if ($mail->reply_to && null !== $user && !empty($user->email) && $newMail->fromEmail !== $user->email) {
            $newMail->replyTo($user->email, $user->nick);
        }

        // Re-attach the persisted files.
        $folder = $mail->getFilesFolder();
        foreach ($mail->getAttachments() as $attachment) {
            $path = $folder . DIRECTORY_SEPARATOR . $attachment['file'];
            if (file_exists($path)) {
                $newMail->addAttachment($path, $attachment['name']);
            }
        }

        return $newMail->send();
    }

    /**
     * Marks the related document(s) as emailed, only after a successful send.
     *
     * @param ScheduledMail $mail
     */
    protected function updateFemail(ScheduledMail $mail): void
    {
        if (empty($mail->model_class_name)) {
            return;
        }

        $className = '\\FacturaScripts\\Dinamic\\Model\\' . $mail->model_class_name;
        if (false === class_exists($className)) {
            return;
        }

        $codes = [];
        if (!empty($mail->model_code)) {
            $codes[] = $mail->model_code;
        }
        if (!empty($mail->model_codes)) {
            $codes = array_merge($codes, explode(',', $mail->model_codes));
        }

        foreach (array_unique($codes) as $code) {
            $model = new $className();
            if ($model->loadFromCode($code) && $model->hasColumn('femail')) {
                $model->femail = Tools::date();
                $model->save();
            }
        }
    }

    /**
     * Stores the failure reason and logs it.
     *
     * @param ScheduledMail $mail
     * @param string $error
     */
    protected function markFailed(ScheduledMail $mail, string $error): void
    {
        $mail->status = ScheduledMail::STATUS_FAILED;
        $mail->error = $error;
        $mail->save();

        Tools::log()->error('scheduled-mail-failed', ['%id%' => $mail->id, '%error%' => $error]);
    }

    /**
     * Removes the persisted attachment folder after a successful send.
     *
     * @param ScheduledMail $mail
     */
    protected function deleteFiles(ScheduledMail $mail): void
    {
        $folder = $mail->getFilesFolder();
        if (is_dir($folder)) {
            Tools::folderDelete($folder);
        }
    }
}
