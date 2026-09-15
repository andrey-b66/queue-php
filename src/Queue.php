<?php

declare(strict_types=1);

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
     * Без фильтров возвращаются все задачи. Переданные фильтры комбинируются через AND
     * и ищутся по значению как есть; пустая дата выборку не ограничивает.
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
     * @throws \InvalidArgumentException если page или limit меньше 1
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
     * Количество задач под те же фильтры, что и find(), без пагинации.
     *
     * @param array{
     *     id?: int,
     *     status?: string,
     *     source?: string,
     *     search?: string,
     *     created_from?: string,
     *     created_to?: string
     * } $filters
     */
    public function count(array $filters = []): int
    {
        return $this->repository->countFiltered($filters);
    }

    /**
     * Непустые источники, которые есть в очереди, по алфавиту.
     *
     * @return string[]
     */
    public function sources(): array
    {
        return $this->repository->findSources();
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

    /**
     * Сменить статус сразу нескольким задачам.
     *
     * Задачи загружаются, переводятся тем же mark-методом, что и по одной
     * (`markNew()`, `markFailed()` и т.д., без аргументов), и записываются.
     * Транзакции нет: если воркер в это время меняет те же задачи, одна запись
     * перекроет другую. Несуществующие id пропускаются.
     *
     * @param int[] $jobIds
     * @return int Количество записанных задач
     * @throws \InvalidArgumentException если статус неизвестен
     */
    public function setStatusMany(array $jobIds, string $status): int
    {
        $allowedStatuses = [
            Job::STATUS_NEW,
            Job::STATUS_PROCESSING,
            Job::STATUS_COMPLETED,
            Job::STATUS_FAILED,
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new \InvalidArgumentException("Неизвестный статус: {$status}");
        }

        $jobs = $this->repository->findByIds($jobIds);

        foreach ($jobs as $job) {
            switch ($status) {
                case Job::STATUS_NEW:
                    $job->markNew();
                    break;
                case Job::STATUS_PROCESSING:
                    $job->markProcessing();
                    break;
                case Job::STATUS_COMPLETED:
                    $job->markCompleted();
                    break;
                case Job::STATUS_FAILED:
                    $job->markFailed();
                    break;
            }
        }

        return $this->repository->updateMany($jobs);
    }

    /**
     * @param int[] $jobIds
     * @return int Количество удалённых задач
     */
    public function deleteMany(array $jobIds): int
    {
        return $this->repository->deleteByIds($jobIds);
    }

    /**
     * Удалить задачи, закрытые больше указанного числа дней назад.
     *
     * Срок считается от закрытия, а не от создания. Задачи в `new` и `processing`
     * не удаляются.
     *
     * @return int Количество удалённых задач
     */
    public function deleteOldRecords(int $daysToKeep = 30): int
    {
        return $this->repository->deleteOldRecords($daysToKeep);
    }
}
