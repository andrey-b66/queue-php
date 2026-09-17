<?php

declare(strict_types=1);

namespace Integrat\Queue\Tests;

use Integrat\Queue\Job;
use Integrat\Queue\Worker;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    use TemporaryDatabase;

    private Worker $worker;

    protected function setUp(): void
    {
        $this->createDatabase();
        $this->worker = new Worker($this->queue, $this->dbDir . '/worker.lock');
    }

    protected function tearDown(): void
    {
        unset($this->worker);
        $this->removeDatabase();
    }

    /**
     * run() выполняет новые задачи обработчиками их source в порядке поступления и записывает
     * возвращённое в result; число — строкой.
     */
    public function testRunCompletesNewJobsWithHandlersOfTheirSource(): void
    {
        $calls = [];

        foreach (['crm' => 'готово', 'mail' => null, 'number' => 42] as $source => $result) {
            $this->worker->register($source, function (Job $job) use (&$calls, $result) {
                $calls[] = $job->id;

                return $result;
            });
        }

        $this->queue->push(Job::create('crm', '{}'));
        $this->queue->push(Job::create('mail', '{}'));
        $this->queue->push(Job::create('number', '{}'));

        $this->worker->run();

        $this->assertSame([1, 2, 3], $calls);

        $jobs = $this->queue->find();
        $this->assertSame(array_fill(0, 3, Job::STATUS_COMPLETED), array_column($jobs, 'status'));
        $this->assertSame(['готово', null, '42'], array_column($jobs, 'result'));
    }

    /**
     * Задача уходит в failed, если обработчик бросил исключение (его текст — в error), вернул
     * не строку и не число или для её source нет обработчика.
     */
    public function testFailingJobsAreMarkedFailed(): void
    {
        $this->worker->register('crm', function (Job $job): ?string {
            throw new \RuntimeException('CRM недоступна');
        });
        $this->worker->register('array', fn (Job $job) => ['id' => 42]);

        $this->queue->push(Job::create('crm', '{}'));
        $this->queue->push(Job::create('array', '{}'));
        $this->queue->push(Job::create('shop', '{}'));

        $this->worker->run();

        $jobs = $this->queue->find();
        $this->assertSame(array_fill(0, 3, Job::STATUS_FAILED), array_column($jobs, 'status'));
        $this->assertSame('CRM недоступна', $jobs[0]->error);
        $this->assertSame('Нет обработчика для source «shop»', $jobs[2]->error);
    }

    /** run() берёт только новые задачи: processing, completed и failed не трогает */
    public function testRunTakesOnlyNewJobs(): void
    {
        $this->worker->register('crm', fn (Job $job): ?string => 'выполнено заново');

        $this->queue->push(Job::create('crm', '{}')->markProcessing());
        $this->queue->push(Job::create('crm', '{}')->markCompleted('раньше'));
        $this->queue->push(Job::create('crm', '{}')->markFailed(null, 'таймаут'));

        $this->worker->run();

        $jobs = $this->queue->find();
        $this->assertSame(
            [Job::STATUS_PROCESSING, Job::STATUS_COMPLETED, Job::STATUS_FAILED],
            array_column($jobs, 'status')
        );
        $this->assertSame([null, 'раньше', null], array_column($jobs, 'result'));
    }

    /** run() разбирает очередь до конца: больше одной порции и задачи, пришедшие во время работы */
    public function testRunProcessesWholeQueueIncludingJobsAddedDuringRun(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $this->queue->push(Job::create('crm', '{}'));
        }

        $this->worker->register('crm', function (Job $job): ?string {
            if ($job->id === 1) {
                $this->queue->push(Job::create('crm', 'пришла во время работы'));
            }

            return null;
        });

        $this->worker->run();

        $this->assertSame(151, $this->queue->count(['status' => Job::STATUS_COMPLETED]));
        $this->assertSame(0, $this->queue->count(['status' => Job::STATUS_NEW]));
    }

    /**
     * Пока файл блокировки держит другой воркер, run() сразу выходит и задач не трогает;
     * когда блокировку отпустили, следующий запуск работает.
     */
    public function testRunDoesNothingWhileAnotherWorkerHoldsLock(): void
    {
        $this->worker->register('crm', fn (Job $job): ?string => null);
        $this->queue->push(Job::create('crm', '{}'));

        $otherWorkerLock = fopen($this->dbDir . '/worker.lock', 'c');
        flock($otherWorkerLock, LOCK_EX);

        try {
            $this->worker->run();
            $this->assertSame(Job::STATUS_NEW, $this->queue->findById(1)->status);
        } finally {
            flock($otherWorkerLock, LOCK_UN);
            fclose($otherWorkerLock);
        }

        $this->worker->run();
        $this->assertSame(Job::STATUS_COMPLETED, $this->queue->findById(1)->status);
    }

    /** Второй обработчик для того же source — ошибка, а не молчаливая замена первого */
    public function testRegisterRejectsSecondHandlerForSameSource(): void
    {
        $this->worker->register('crm', fn (Job $job): ?string => null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Обработчик для source «crm» уже зарегистрирован');

        $this->worker->register('crm', fn (Job $job): ?string => null);
    }

    /** Если файл блокировки не открыть, run() бросает RuntimeException с путём к нему */
    public function testRunReportsLockFileThatCannotBeOpened(): void
    {
        // Файл нельзя создать внутри обычного файла
        $file = $this->dbDir . '/not-a-folder';
        file_put_contents($file, '');
        $worker = new Worker($this->queue, $file . '/worker.lock');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Не удалось открыть файл блокировки воркера ' . $file . '/worker.lock');

        $worker->run();
    }
}
