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
     * Получить новые задачи конкретной очереди
     * Порядок — от старых к новым, как у findNew.
     *
     * @throws \Exception
     * @return Job[]
     */
    public function findNewByQueueName(string $queueName, int $page = 1, int $limit = 50): array
    {
        try {
            return $this->repository->findFiltered([
                'queue_name' => $queueName,
                'status' => Job::STATUS_NEW,
                'sort' => 'ASC',
            ], $page, $limit);
        } catch (\Exception $e) {
            throw new \Exception("Ошибка получения новых задач из очереди '$queueName': $e");
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
     *
     * @param string|null $result Результат выполнения, заполняется по желанию
     */
    public function markCompleted(Job $job, ?string $result = null): ?Job
    {
        $job->markCompleted($result);
        return $this->repository->updateStatus($job);
    }

    /**
     * Отметить задачу как проваленную
     *
     * @param string|null $result Результат выполнения, заполняется по желанию
     */
    public function markFailed(Job $job, ?string $error = null, ?string $result = null): ?Job
    {
        $job->markFailed($error, $result);
        return $this->repository->updateStatus($job);
    }

    public function delete(int|Job $job): bool
    {
        $jobId = $job instanceof Job ? $job->id : $job;

        return $jobId > 0 && $this->repository->deleteByIds([$jobId]) === 1;
    }
}
