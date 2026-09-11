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
     */
    public function push(Job $job): Job
    {
        return $this->repository->create($job);
    }

    /**
     * Получить задачи по фильтрам.
     *
     * Фильтры комбинируются через AND, пустые значения игнорируются.
     * Порядок по умолчанию — от старых к новым, в порядке поступления.
     * Передайте 'sort' => 'DESC', чтобы получить сначала свежие.
     * Само умолчание живёт в SqliteJobRepository::findFiltered().
     *
     * @param array{
     *     id?: int,
     *     status?: string,
     *     source?: string,
     *     search?: string,
     *     created_from?: string,
     *     created_to?: string,
     *     sort?: 'ASC'|'DESC'
     * } $filters
     * @return Job[]
     */
    public function find(array $filters = [], int $page = 1, int $limit = 50): array
    {
        return $this->repository->findFiltered($filters, $page, $limit);
    }

    public function findById(int $jobId): ?Job
    {
        return $this->repository->findById($jobId);
    }

    /**
     * Записать изменения существующей задачи.
     *
     * Пишутся все изменяемые поля. Переходы живут в модели —
     * `$job->markCompleted('готово')` и т.п., здесь только запись в БД.
     *
     * @return Job|null null, если задачи с таким id уже нет
     */
    public function update(Job $job): ?Job
    {
        return $this->repository->update($job);
    }

    public function delete(int $jobId): bool
    {
        return $jobId > 0 && $this->repository->deleteByIds([$jobId]) === 1;
    }
}
