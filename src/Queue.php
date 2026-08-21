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
    public function push(array $server, string $rawData): Job
    {
        $job = Job::create($server, $rawData);
        return $this->repository->create($job);
    }

    /**
     * Получить ожидающие задачи
     * @return Job[]
     */
    public function getPending(int $limit = 50): array
    {
        return $this->repository->findByStatus(Job::STATUS_PENDING, $limit);
    }

    /**
     * Получить проваленные задачи
     * @return Job[]
     */
    public function getFailed(int $limit = 50): array
    {
        return $this->repository->findByStatus(Job::STATUS_FAILED, $limit);
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

    /**
     * Повторить задачу
     */
    public function retry(int $jobId): void
    {
        $job = $this->repository->findById($jobId);

        if (!$job) {
            throw new \InvalidArgumentException("Задача #{$jobId} не найдена");
        }

        $job->markPending();
        $this->repository->updateStatus($job);
    }

    /**
     * Повторить все проваленные задачи
     */
    public function retryAllFailed(int $limit = 50): int
    {
        $failed = $this->repository->findByStatus(Job::STATUS_FAILED, $limit);
        $count = 0;

        foreach ($failed as $job) {
            $job->markPending();
            $this->repository->updateStatus($job);
            $count++;
        }

        return $count;
    }

    /**
     * Сменить статус у списка задач
     *
     * @param int[] $jobIds
     * @return int Количество изменённых задач
     */
    public function setStatusMany(array $jobIds, string $status): int
    {
        $allowed = [
            Job::STATUS_PENDING,
            Job::STATUS_COMPLETED,
            Job::STATUS_FAILED,
        ];

        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Неизвестный статус: {$status}");
        }

        $ids = $this->normalizeIds($jobIds);

        if ($ids === []) {
            return 0;
        }

        return $this->repository->updateStatusByIds($ids, $status);
    }

    /**
     * Удалить список задач
     *
     * @param int[] $jobIds
     * @return int Количество удалённых задач
     */
    public function deleteMany(array $jobIds): int
    {
        $ids = $this->normalizeIds($jobIds);

        if ($ids === []) {
            return 0;
        }

        return $this->repository->deleteByIds($ids);
    }

    /**
     * Привести список ID к уникальным положительным числам
     *
     * @param array<int|string> $jobIds
     * @return int[]
     */
    private function normalizeIds(array $jobIds): array
    {
        $ids = [];

        foreach ($jobIds as $jobId) {
            $id = (int) $jobId;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Получить все задачи с пагинацией
     */
    public function getAll(int $page = 1, int $limit = 50): array
    {
        $jobs = $this->repository->findPaginated($page, $limit);

        return array_map(
            fn (Job $job) => $job->toArray(),
            $jobs
        );
    }

    /**
     * Получить проваленные задачи с пагинацией
     */
    public function getFailedPaginated(int $page = 1, int $limit = 50): array
    {
        $jobs = $this->repository->findByStatusPaginated(Job::STATUS_FAILED, $page, $limit);

        return array_map(
            fn (Job $job) => $job->toArray(),
            $jobs
        );
    }

    /**
     * Отфильтрованный список задач (массивы) — для UI/дашборда.
     *
     * @param array{
     *     status?: string,
     *     hook?: string,
     *     q?: string,
     *     created_from?: string,
     *     created_to?: string
     * } $filters
     * @return array<int, array<string, mixed>>
     */
    public function findFiltered(array $filters, int $page = 1, int $limit = 50): array
    {
        return array_map(
            fn (Job $job) => $job->toArray(),
            $this->repository->findFiltered($filters, $page, $limit)
        );
    }

    /**
     * Общее количество задач под те же фильтры (без пагинации).
     *
     * @param array{
     *     status?: string,
     *     hook?: string,
     *     q?: string,
     *     created_from?: string,
     *     created_to?: string
     * } $filters
     */
    public function countFiltered(array $filters): int
    {
        return $this->repository->countFiltered($filters);
    }

    /**
     * Удалить старые задачи
     */
    public function deleteOldRecords(int $daysToKeep = 30): int
    {
        return $this->repository->deleteOldRecords($daysToKeep);
    }
}
