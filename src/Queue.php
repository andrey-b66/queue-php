<?php

namespace Integrat\Queue;

use Integrat\Queue\Storage\SqliteJobRepository;

class Queue
{
    public function __construct(
        private SqliteJobRepository $repository,
    ) {
    }

    /**
     * Добавить задачу в очередь
     */
    public function push(Job $job): Job
    {
        return $this->repository->create($job);
    }

    /**
     * Получить задачи по типу
     * @throws \Exception
     * @return Job[]
     */
    public function findByQueueName(string $queueName, int $page = 1, int $limit = 50): array
    {
        try {
            return $this->repository->findFiltered(['queue_name' => $queueName], $page, $limit);
        } catch (\Exception $e) {
            throw new \Exception("Ошибка получения задач из очереди '$queueName': $e");
        }
    }

    /**
     * Получить все новые задачи
     * @throws \Exception
     * @return Job[]
     */
    public function findNew(int $page = 1, int $limit = 50): array
    {
        try {
            return $this->repository->findFiltered([
                'status' => Job::STATUS_NEW,
                'sort' => 'ASC',
            ], $page, $limit);
        } catch (\Exception $e) {
            throw new \Exception("Ошибка при получении новых задач: $e");
        }
    }

    /**
     * Получить задачи которые выполняются
     * @throws \Exception
     * @return Job[]
     */
    public function findProcessing(int $page = 1, int $limit = 50): array
    {
        try {
            return $this->repository->findFiltered([
                'status' => Job::STATUS_PROCESSING,
                'sort' => 'ASC',
            ], $page, $limit);
        } catch (\Exception $e) {
            throw new \Exception("Ошибка при получении задач которые в процессе выполнения: $e");
        }
    }

    /**
     * Получить выполненные задачи
     * @throws \Exception
     * @return Job[]
     */
    public function findCompleted(int $page = 1, int $limit = 50): array
    {
        try {
            return $this->repository->findFiltered([
                'status' => Job::STATUS_COMPLETED,
                'sort' => 'ASC',
            ], $page, $limit);
        } catch (\Exception $e) {
            throw new \Exception("Ошибка при получении выполненных задач: $e");
        }
    }

    /**
     * Получить проваленные задачи
     * @throws \Exception
     * @return Job[]
     */
    public function findFailed(int $page = 1, int $limit = 50): array
    {
        try {
            return $this->repository->findFiltered([
                'status' => Job::STATUS_FAILED,
                'sort' => 'ASC',
            ], $page, $limit);
        } catch (\Exception $e) {
            throw new \Exception("Ошибка при получении проваленных задач: $e");
        }
    }

    public function findById(int $jobId): ?Job
    {
        return $this->repository->findById($jobId);
    }

    /**
     * Отметить задачу как выполняющуюся
     */
    public function markProcessing(Job $job): ?Job
    {
        $job->markProcessing();
        return $this->repository->updateStatus($job);
    }

    /**
     * Отметить задачу как выполненную
     */
    public function markCompleted(Job $job): ?Job
    {
        $job->markCompleted();
        return $this->repository->updateStatus($job);
    }

    /**
     * Отметить задачу как проваленную
     */
    public function markFailed(Job $job, ?string $error = null): ?Job
    {
        $job->markFailed($error);
        return $this->repository->updateStatus($job);
    }

    public function delete(int|Job $job): bool
    {
        $jobId = $job instanceof Job ? $job->id : $job;

        return $jobId > 0 && $this->repository->deleteByIds([$jobId]) === 1;
    }
}
