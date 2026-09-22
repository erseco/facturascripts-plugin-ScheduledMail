<?php

/**
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */

namespace FacturaScripts\Plugins\ScheduledMail\Worker;

use FacturaScripts\Test\Plugins\ScheduledMailFlowTest;

// Advance only the worker clock; model validation still uses the real clock.
function time(): int
{
    return ScheduledMailFlowTest::$workerTime ?? \time();
}

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\ScheduledMail\Controller\EditScheduledMail;
use FacturaScripts\Plugins\ScheduledMail\Controller\ListScheduledMail;
use FacturaScripts\Plugins\ScheduledMail\Extension\Controller\SendMail;
use FacturaScripts\Plugins\ScheduledMail\Init;
use FacturaScripts\Plugins\ScheduledMail\Lib\ScheduledMailScheduler;
use FacturaScripts\Plugins\ScheduledMail\Model\ScheduledMail;
use FacturaScripts\Plugins\ScheduledMail\Worker\SendScheduledMailWorker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ScheduledMailFlowTest extends TestCase
{
    public static ?int $workerTime = null;
    private string $subject;
    private array $files = [];
    private ?User $testUser = null;

    protected function setUp(): void
    {
        $this->subject = uniqid('Scheduled coverage ');
        // No real SMTP, sendmail or external email delivery in the tests.
        Tools::settingsSet('email', 'email', '');
        Tools::settingsSet('email', 'host', '');
        Tools::settingsSet('email', 'mailer', 'smtp');
        Tools::settingsSet('email', 'emailcc', '');
        Tools::settingsSet('email', 'emailbcc', '');
        (new Init())->init();
    }

    protected function tearDown(): void
    {
        foreach (ScheduledMail::all([Where::eq('subject', $this->subject)]) as $mail) {
            foreach (
                WorkEvent::all([
                Where::eq('name', Init::WORK_EVENT), Where::eq('value', (string)$mail->id),
                ]) as $event
            ) {
                $event->delete();
            }
            $mail->delete();
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (null !== $this->testUser) {
            $this->testUser->delete();
        }
        self::$workerTime = null;
        WorkQueue::preventNewEvents([]);
        Tools::settingsClear();
        Tools::log()->clear();
    }

    public function testSchedulerPersistsRecipientsAttachmentsAndQueryPriority(): void
    {
        $mail = $this->newMail();
        $name = uniqid('attachment-') . '.txt';
        $source = Tools::folder(NewMail::ATTACHMENTS_TMP_PATH, $name);
        Tools::folderCheckOrCreate(dirname($source));
        file_put_contents($source, 'persistent attachment');
        $this->files[] = $source;
        $mail->addAttachment($source, $name);
        $request = $this->request();
        $request->query->set('modelClassName', 'FacturaCliente');
        $request->query->set('modelCode', '42');
        $request->request->set('modelClassName', 'PresupuestoCliente');
        $request->request->set('modelCodes', '43,44');
        self::assertTrue(ScheduledMailScheduler::schedule($mail, $request, $this->user(), time() + 3600));
        $stored = $this->stored();
        self::assertSame('FacturaCliente', $stored->model_class_name);
        self::assertSame('42', $stored->model_code);
        self::assertSame('43,44', $stored->model_codes);
        self::assertSame('to@example.com', $stored->email_to);
        self::assertSame('cc@example.com', $stored->email_cc);
        self::assertSame('bcc@example.com', $stored->email_bcc);
        self::assertCount(1, $stored->getAttachments());
        self::assertSame('persistent attachment', file_get_contents($stored->getFilesFolder() . '/' . $name));
        self::assertSame(1, WorkEvent::count([
            Where::eq('name', Init::WORK_EVENT), Where::eq('value', (string)$stored->id),
        ]));
    }

    public function testSchedulerRejectsInvalidInputsAndMissingWorker(): void
    {
        foreach (['email-from', 'email-to', 'email-cc', 'email-bcc'] as $field) {
            $request = $this->request();
            $request->request->set($field, 'invalid');
            self::assertFalse(ScheduledMailScheduler::schedule(
                $this->newMail(),
                $request,
                $this->user(),
                time() + 3600
            ));
        }
        self::assertFalse(ScheduledMailScheduler::schedule(
            NewMail::create(),
            $this->request(),
            $this->user(),
            time() + 3600
        ));
        self::assertFalse(ScheduledMailScheduler::schedule(
            $this->newMail(),
            $this->request(),
            $this->user(),
            time() - 3600
        ));
        self::assertSame(0, ScheduledMail::count([Where::eq('subject', $this->subject)]));
        WorkQueue::preventNewEvents([Init::WORK_EVENT]);
        self::assertFalse(ScheduledMailScheduler::schedule(
            $this->newMail(),
            $this->request(),
            $this->user(),
            time() + 3600
        ));
        self::assertSame('failed', $this->stored()->status);
    }

    public function testWorkerSkipsMissingFutureAndCompletedRecords(): void
    {
        $worker = new SendScheduledMailWorker();
        $event = new WorkEvent();
        $event->value = '-1';
        self::assertTrue($worker->run($event));
        $mail = $this->makeStored();
        $event->value = (string)$mail->id;
        self::assertTrue($worker->run($event));
        $mail->reload();
        self::assertSame('pending', $mail->status);
        $mail->status = 'sent';
        self::assertTrue($mail->save());
        self::assertTrue($worker->run($event));
        $mail->reload();
        self::assertSame('sent', $mail->status);
    }

    public function testWorkerRecordsFailureAndExceptionWithoutDeletingAttachments(): void
    {
        $mail = $this->makeStored();
        $event = new WorkEvent();
        $event->value = (string)$mail->id;
        self::$workerTime = time() + 7200;
        self::assertTrue((new SendScheduledMailWorker())->run($event));
        $mail->reload();
        self::assertSame('failed', $mail->status);
        self::assertNotEmpty($mail->error);
        self::assertDirectoryExists($mail->getFilesFolder());
        $mail->status = 'pending';
        self::assertTrue($mail->save());
        $worker = new class () extends SendScheduledMailWorker {
            protected function deliver(ScheduledMail $mail): bool
            {
                throw new \RuntimeException('Transport unavailable');
            }
        };
        self::assertTrue($worker->run($event));
        $mail->reload();
        self::assertSame('Transport unavailable', $mail->error);
    }

    public function testWorkerSuccessCleansFilesAndIsIdempotent(): void
    {
        $mail = $this->makeStored();
        $event = new WorkEvent();
        $event->value = (string)$mail->id;
        self::$workerTime = time() + 7200;
        $worker = new class () extends SendScheduledMailWorker {
            public int $deliveries = 0;

            protected function deliver(ScheduledMail $mail): bool
            {
                $this->deliveries++;
                return true;
            }
        };
        self::assertTrue($worker->run($event));
        $mail->reload();
        self::assertSame('sent', $mail->status);
        self::assertNotEmpty($mail->sent_at);
        self::assertNull($mail->error);
        self::assertDirectoryDoesNotExist($mail->getFilesFolder());
        self::assertTrue($worker->run($event));
        self::assertSame(1, $worker->deliveries);
    }

    public function testDocumentUpdatesIgnoreUnknownModelsAndMissingRecords(): void
    {
        $mail = $this->makeStored();
        $worker = new SendScheduledMailWorker();
        $mail->model_class_name = 'MissingModel';
        $this->invoke($worker, 'updateFemail', $mail);
        $mail->model_class_name = 'User';
        $mail->model_code = $this->user()->nick;
        $mail->model_codes = $this->user()->nick . ',missing-user';
        $this->invoke($worker, 'updateFemail', $mail);
        self::assertNull($this->stored()->sent_at);
        $invalid = new ScheduledMail();
        $invalid->email_to = 'to@example.com';
        self::assertFalse($invalid->test());
    }

    public function testEditingPendingDateRegistersNewEvent(): void
    {
        $mail = $this->makeStored();
        $page = new class ('EditScheduledMail') extends EditScheduledMail {
            protected function validateFormToken(): bool
            {
                return true;
            }
        };
        $page->permissions = new ControllerPermissions();
        $page->permissions->allowUpdate = true;
        $page->request = new Request(['request' => [
            'code' => (string)$mail->id, 'id' => (string)$mail->id,
            'status' => 'pending', 'creation_date' => $mail->creation_date,
            'nick' => $mail->nick, 'email_from' => $mail->email_from,
            'scheduled_at' => date('Y-m-d H:i:s', time() + 10800),
            'email_to' => 'to@example.com', 'subject' => $this->subject,
            'body' => 'Rescheduled',
        ]]);
        $this->invoke($page, 'createViews');
        $page->active = $page->getMainViewName();
        $saved = $this->invoke($page, 'editAction');
        self::assertTrue($saved, json_encode(Tools::log()->read('', ['error', 'warning'])));
        $mail->reload();
        self::assertGreaterThan(time() + 10000, strtotime($mail->scheduled_at));
        self::assertSame(2, WorkEvent::count([
            Where::eq('name', Init::WORK_EVENT), Where::eq('value', (string)$mail->id),
        ]));
    }

    public function testExtensionValidatesTokenAndSchedulesOnlyItsAction(): void
    {
        $host = new class () {
            public bool $validToken = true;
            public bool $redirected = false;
            public $request;
            public $newMail;
            public $user;

            protected function validateFormToken(): bool
            {
                return $this->validToken;
            }

            protected function redirectAfter(): void
            {
                $this->redirected = true;
            }
        };
        $host->request = $this->request();
        $host->newMail = $this->newMail();
        $host->user = $this->user();
        $hook = (new SendMail())->execAction();
        $hook->call($host, 'send');
        $host->validToken = false;
        $hook->call($host, 'schedule');
        $host->validToken = true;
        $hook->call($host, 'schedule');
        self::assertFalse($host->redirected);
        $host->request->request->set('email-scheduled-at', date('Y-m-d H:i:s', time() + 3600));
        $host->request->request->set('email-from', 'invalid');
        $hook->call($host, 'schedule');
        self::assertFalse($host->redirected);
        $host->request->request->set('email-from', 'from@example.com');
        $hook->call($host, 'schedule');
        self::assertTrue($host->redirected);
        self::assertSame('pending', $this->stored()->status);
    }

    public function testViewsAndReschedulingUseRealQueue(): void
    {
        $mail = $this->makeStored();
        $list = new ListScheduledMail('ListScheduledMail');
        self::assertSame('admin', $list->getPageData()['menu']);
        $this->invoke($list, 'createViews');
        self::assertFalse($list->views['ListScheduledMail']->settings['btnNew']);
        $page = new EditScheduledMail('EditScheduledMail');
        $page->permissions = new ControllerPermissions();
        self::assertSame('ScheduledMail', $page->getModelClassName());
        self::assertFalse($page->getPageData()['showonmenu']);
        $page->request = new Request(['query' => ['code' => (string)$mail->id]]);
        $this->invoke($page, 'createViews');
        $view = $page->views[$page->getMainViewName()];
        $this->invoke($page, 'loadData', $page->getMainViewName(), $view);
        self::assertEquals($mail->id, $view->model->id);
        $mail->status = 'sent';
        self::assertTrue($mail->save());
        $this->invoke($page, 'loadData', $page->getMainViewName(), $view);
        self::assertFalse($view->settings['btnSave']);
        $this->invoke($page, 'rescheduleWorkEvent', $mail);
        $mail->scheduled_at = date('Y-m-d H:i:s', time() - 10);
        WorkQueue::preventNewEvents([Init::WORK_EVENT]);
        $this->invoke($page, 'rescheduleWorkEvent', $mail);
        $page->permissions->allowUpdate = false;
        self::assertFalse($this->invoke($page, 'editAction'));
        (new Init())->update();
        (new Init())->uninstall();
    }

    private function newMail(): NewMail
    {
        return NewMail::create()->to('to@example.com')->cc('cc@example.com')->bcc('bcc@example.com')
            ->subject($this->subject)->body('Scheduled body');
    }

    private function request(): Request
    {
        return new Request(['request' => [
            'email-from' => 'from@example.com', 'email-to' => 'to@example.com', 'replyto' => '1',
        ]]);
    }

    private function user(): User
    {
        if (null === $this->testUser) {
            $this->testUser = new User();
            $this->testUser->nick = uniqid('mail-test-');
            $this->testUser->email = 'reply@example.com';
            $this->testUser->setPassword('Test-only-' . bin2hex(random_bytes(8)) . '1');
            self::assertTrue($this->testUser->save());
        }
        return $this->testUser;
    }

    private function makeStored(): ScheduledMail
    {
        self::assertTrue(ScheduledMailScheduler::schedule(
            $this->newMail(),
            $this->request(),
            $this->user(),
            time() + 3600
        ));
        return $this->stored();
    }

    private function stored(): ScheduledMail
    {
        $mail = ScheduledMail::findWhere([Where::eq('subject', $this->subject)]);
        self::assertNotNull($mail);
        return $mail;
    }

    private function invoke($object, string $name, ...$args)
    {
        $method = new ReflectionMethod($object, $name);
        $method->setAccessible(true);
        return $method->invoke($object, ...$args);
    }
}
