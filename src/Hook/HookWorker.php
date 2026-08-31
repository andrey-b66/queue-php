<?php

declare(strict_types=1);

namespace Integrat\Queue\Hook;

use InvalidArgumentException;
use Integrat\Queue\Job;
use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;
use Throwable;

/**
 * Готовая цепочка обработки: new → processing → hook → completed/failed.
 */
final class HookWorker
{
    /** @var resource|null */
    private $lockHandle = null;

    private readonly string $lockFile;
    private readonly Queue $queue;
    private readonly HookExecutor $hookExecutor;

    public function __construct(
        string $databaseFile,
        string $hooksDirectory,
        private readonly int $batchSize = 50,
    ) {
        if ($this->batchSize < 1) {
            throw new InvalidArgumentException('Размер пачки должен быть больше нуля');
        }

        $this->queue = new Queue(new SqliteJobRepository($databaseFile));
        $this->hookExecutor = new HookExecutor($hooksDirectory);

        // Репозиторий уже создал БД. Абсолютный путь гарантирует один lock
        // даже тогда, когда одна и та же БД указана разными относительными путями.
        $databaseFile = realpath($databaseFile) ?: $databaseFile;
        $this->lockFile = $databaseFile . '.hook-worker.lock';
    }

    /**
     * @return int количество обработанных задач
     */
    public function run(): int
    {
        if (!$this->acquireLock()) {
            return 0;
        }

        $processed = 0;

        try {
            while (true) {
                $jobs = $this->queue->findNew(1, $this->batchSize);

                if ($jobs === []) {
                    return $processed;
                }

                foreach ($jobs as $job) {
                    if (!$this->process($job)) {
                        return $processed;
                    }

                    $processed++;
                }
            }
        } finally {
            $this->releaseLock();
        }
    }

    private function process(Job $job): bool
    {
        $result = null;

        try {
            $processingJob = $this->queue->markProcessing($job);

            if ($processingJob === null) {
                error_log("Задача {$job->id} исчезла до начала обработки");
                return false;
            }

            $job = $processingJob;
            $result = $this->hookExecutor->handle($job);
        } catch (Throwable $exception) {
            error_log("Задача {$job->id} провалена: " . $exception->getMessage());

            try {
                return $this->queue->markFailed($job, $exception->getMessage()) !== null;
            } catch (Throwable $statusException) {
                error_log(
                    "Не удалось отметить задачу {$job->id} как failed: "
                    . $statusException->getMessage()
                );
                return false;
            }
        }

        try {
            return $this->queue->markCompleted($job, $result) !== null;
        } catch (Throwable $exception) {
            error_log("Не удалось завершить задачу {$job->id}: " . $exception->getMessage());
            return false;
        }
    }

    private function acquireLock(): bool
    {
        if (is_resource($this->lockHandle)) {
            return true;
        }

        $directory = dirname($this->lockFile);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
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

    private function releaseLock(): void
    {
        if (!is_resource($this->lockHandle)) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }
}
