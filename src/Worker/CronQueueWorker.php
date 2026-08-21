<?php

declare(strict_types=1);

namespace Integrat\Queue\Worker;

use InvalidArgumentException;
use Integrat\Queue\Hook\HookExecutor;
use Integrat\Queue\Job;
use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;
use Throwable;

/**
 * Cron-воркер: блокирует параллельный запуск и разбирает очередь до конца.
 */
final class CronQueueWorker
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $databaseFile,
        private readonly string $lockFile,
        private readonly int $batchSize = 50,
    ) {
        if ($this->batchSize < 1) {
            throw new InvalidArgumentException('Размер пачки должен быть больше нуля');
        }
    }

    /**
     * Создаёт каталог и lock-файл при необходимости, затем пытается взять lock.
     */
    public function acquireLock(): bool
    {
        if (is_resource($this->lockHandle)) {
            return true;
        }

        if (!$this->createLockDirectory()) {
            return false;
        }

        $handle = fopen($this->lockFile, 'c');

        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    public function releaseLock(): void
    {
        if (!is_resource($this->lockHandle)) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    public function run(): void
    {
        $queue = new Queue(new SqliteJobRepository($this->databaseFile));
        $processor = new HookExecutor($this->projectRoot);

        while (true) {
            $jobs = $queue->getPending($this->batchSize);

            if ($jobs === []) {
                return;
            }

            foreach ($jobs as $job) {
                if (!$this->process($job, $queue, $processor)) {
                    return;
                }
            }
        }
    }

    private function process(Job $job, Queue $queue, HookExecutor $processor): bool
    {
        $error = null;

        try {
            $processor->handle($job);
        } catch (Throwable $exception) {
            error_log("Job #{$job->getId()} failed: " . $exception->getMessage());
            $error = $exception->getMessage();
        }

        try {
            $error === null
                ? $queue->markCompleted($job)
                : $queue->markFailed($job, $error);
        } catch (Throwable $exception) {
            // Задача осталась pending — завершаем прогон, чтобы не зациклиться.
            error_log("Job #{$job->getId()}: не удалось обновить статус: " . $exception->getMessage());
            return false;
        }

        return true;
    }

    private function createLockDirectory(): bool
    {
        $directory = dirname($this->lockFile);

        return is_dir($directory)
            || mkdir($directory, 0755, true)
            || is_dir($directory);
    }
}
