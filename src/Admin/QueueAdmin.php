<?php

declare(strict_types=1);

namespace Integrat\Queue\Admin;

use Integrat\Queue\Job;
use Integrat\Queue\Storage\SqliteJobRepository;
use InvalidArgumentException;

/**
 * Операции чтения и массового управления, необходимые веб-админке.
 */
final class QueueAdmin
{
    public function __construct(
        private readonly SqliteJobRepository $repository,
    ) {
    }

    /**
     * @param array{
     *     status?: string,
     *     queue_name?: string,
     *     source?: string,
     *     q?: string,
     *     created_from?: string,
     *     created_to?: string,
     *     sort?: 'ASC'|'DESC'
     * } $filters
     * @return array<int, array<string, mixed>>
     */
    public function findFiltered(array $filters = [], int $page = 1, int $limit = 50): array
    {
        return array_map(
            static fn (Job $job): array => $job->toArray(),
            $this->repository->findFiltered($filters, $page, $limit),
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countFiltered(array $filters = []): int
    {
        return $this->repository->countFiltered($filters);
    }

    /**
     * @return string[]
     */
    public function getQueueNames(): array
    {
        return $this->repository->findQueueNames();
    }

    /**
     * @param array<int|string> $jobIds
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
            throw new InvalidArgumentException("Неизвестный статус: {$status}");
        }

        return $this->repository->updateStatusByIds($this->normalizeIds($jobIds), $status);
    }

    /**
     * @param array<int|string> $jobIds
     */
    public function deleteMany(array $jobIds): int
    {
        return $this->repository->deleteByIds($this->normalizeIds($jobIds));
    }

    public function deleteOldRecords(int $daysToKeep = 30): int
    {
        return $this->repository->deleteOldRecords($daysToKeep);
    }

    /**
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
}
