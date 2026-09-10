<?php

namespace Integrat\Queue;

use Integrat\Queue\Storage\SqliteJobRepository;

class Queue
{
    private SqliteJobRepository $repository;

    public function __construct(SqliteJobRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Добавить задачу в очередь
     *
     * @throws \Exception
     */
    public function push(Job $job): Job
    {
        try {
            return $this->repository->create($job);
        } catch (\Exception $e) {
            // Задача в БД не легла — выкладываем её в лог PHP,
            // чтобы payload можно было восстановить руками.
            error_log(sprintf(
                'Не удалось добавить задачу в очередь: type=%s source=%s payload=%s',
                $job->type,
                $job->source,
                $job->payload
            ));

            throw new \Exception("Ошибка при добавлении задачи в очередь '{$job->type}': $e");
        }
    }

    /**
     * Получить задачи по фильтрам.
     *
     * Фильтры комбинируются через AND, пустые значения игнорируются.
     * Порядок по умолчанию — от старых к новым: очередь разбирается FIFO.
     * Передайте 'sort' => 'DESC', чтобы получить сначала свежие.
     *
     * @param array{
     *     status?: string,
     *     type?: string,
     *     source?: string,
     *     q?: string,
     *     created_from?: string,
     *     created_to?: string,
     *     sort?: 'ASC'|'DESC'
     * } $filters
     * @return Job[]
     */
    public function find(array $filters = [], int $page = 1, int $limit = 50): array
    {
        return $this->repository->findFiltered($filters + ['sort' => 'ASC'], $page, $limit);
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

    public function delete(int $jobId): bool
    {
        return $jobId > 0 && $this->repository->deleteByIds([$jobId]) === 1;
    }
}
