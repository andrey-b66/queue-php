<?php

namespace Integrat\Queue\Storage;

use Integrat\Queue\Job;
use PDO;
use PDOException;

class SqliteJobRepository
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        try {
            $dbDir = dirname($dbPath);
            if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true)) {
                throw new \Exception("Ошибка при создании папки: $dbDir");
            }

            $this->pdo = new PDO('sqlite:' . $dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');

            $this->createJobsTable();
        } catch (PDOException $e) {
            throw new \Exception("Failed to initialize queue database: $e");
        }
    }

    private function createJobsTable(): void
    {
        $query = "CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT,
            source TEXT,
            payload TEXT,
            status TEXT,
            created_at TEXT,
            updated_at TEXT,
            closed_at TEXT,
            error TEXT,
            result TEXT
        )";
        $this->pdo->exec($query);
    }

    public function create(Job $job): Job
    {
        $sql = "INSERT INTO jobs (
            type,
            source,
            payload,
            status,
            created_at,
            updated_at,
            closed_at,
            error,
            result
        ) VALUES (
            :type,
            :source,
            :payload,
            :status,
            :created_at,
            :updated_at,
            :closed_at,
            :error,
            :result
        )";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':type' => $job->type,
            ':source' => $job->source,
            ':payload' => $job->payload,
            ':status' => $job->status,
            ':created_at' => $job->createdAt,
            ':updated_at' => $job->updatedAt,
            ':closed_at' => $job->closedAt,
            ':error' => $job->error,
            ':result' => $job->result,
        ]);

        // После вставки получаем полный объект из БД
        $newId = (int) $this->pdo->lastInsertId();
        return $this->findById($newId);
    }

    public function findById(int $id): ?Job
    {
        $sql = "SELECT * FROM jobs WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();

        return $row ? Job::fromDatabase($row) : null;
    }

    /**
     * Гибкая выборка с комбинируемыми фильтрами (все через AND).
     *
     * @param array{
     *     status?: string,
     *     type?: string,
     *     source?: string,
     *     search?: string,
     *     created_from?: string,
     *     created_to?: string,
     *     sort?: 'ASC'|'DESC'
     * } $filters
     * @return Job[]
     */
    public function findFiltered(array $filters = [], int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        $conditions = $this->buildFilterConditions($filters);
        $where = $conditions['where'];
        $params = $conditions['params'];

        $sort = strtoupper((string) ($filters['sort'] ?? 'DESC'));

        if (!in_array($sort, ['ASC', 'DESC'], true)) {
            $sort = 'DESC';
        }

        $sql = 'SELECT * FROM jobs';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY created_at {$sort}, id {$sort} LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $jobs = [];

        foreach ($stmt->fetchAll() as $row) {
            $jobs[] = Job::fromDatabase($row);
        }

        return $jobs;
    }

    /**
     * Количество задач под те же фильтры, что и findFiltered (без пагинации).
     *
     * @param array{
     *     status?: string,
     *     type?: string,
     *     source?: string,
     *     search?: string,
     *     created_from?: string,
     *     created_to?: string
     * } $filters
     */
    public function countFiltered(array $filters = []): int
    {
        $conditions = $this->buildFilterConditions($filters);
        $where = $conditions['where'];
        $params = $conditions['params'];

        $sql = 'SELECT COUNT(*) FROM jobs';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return string[]
     */
    public function findTypes(): array
    {
        $statement = $this->pdo->query(
            "SELECT DISTINCT type
             FROM jobs
             WHERE type <> ''
             ORDER BY type ASC"
        );

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Собирает условия WHERE и параметры для фильтруемых выборок.
     *
     * @param array{
     *     status?: string,
     *     type?: string,
     *     source?: string,
     *     search?: string,
     *     created_from?: string,
     *     created_to?: string
     * } $filters
     * @return array{where: array<int, string>, params: array<string, string>}
     */
    private function buildFilterConditions(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $where[] = 'type = :type';
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['source'])) {
            $where[] = 'source = :source';
            $params[':source'] = $filters['source'];
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']);
            $params[':search'] = '%' . $search . '%';
            $where[] = "(type LIKE :search ESCAPE '\\'
                     OR source LIKE :search ESCAPE '\\'
                     OR payload LIKE :search ESCAPE '\\'
                     OR status LIKE :search ESCAPE '\\'
                     OR error LIKE :search ESCAPE '\\'
                     OR result LIKE :search ESCAPE '\\'
                     OR CAST(id AS TEXT) LIKE :search ESCAPE '\\')";
        }

        if (!empty($filters['created_from'])) {
            $where[] = 'created_at >= :created_from';
            $params[':created_from'] = $filters['created_from'] . ' 00:00:00';
        }

        if (!empty($filters['created_to'])) {
            $where[] = 'created_at <= :created_to';
            $params[':created_to'] = $filters['created_to'] . ' 23:59:59';
        }

        return [
            'where' => $where,
            'params' => $params,
        ];
    }

    public function updateStatus(Job $job): ?Job
    {
        $sql = "UPDATE jobs
                SET status = :status,
                    updated_at = :updated_at,
                    closed_at = :closed_at,
                    error = :error,
                    result = :result
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $job->id,
            ':status' => $job->status,
            ':updated_at' => $job->updatedAt,
            ':closed_at' => $job->closedAt,
            ':error' => $job->error,
            ':result' => $job->result,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->findById($job->id);
    }

    /**
     * Массовая смена статуса по списку ID.
     *
     * Ошибка сбрасывается, если новый статус не `failed` — она относилась
     * к прошлому прогону и для new/processing/completed уже неактуальна.
     * Результат сбрасывается при возврате в `new`/`processing`: задача будет
     * выполняться заново, поэтому прошлый результат к ней больше не относится.
     *
     * @param int[] $ids
     * @return int Количество затронутых задач
     */
    public function updateStatusByIds(array $ids, string $status): int
    {
        $prepared = $this->buildIdPlaceholders($ids);
        $placeholders = $prepared['placeholders'];
        $params = $prepared['params'];

        if ($placeholders === []) {
            return 0;
        }

        $updatedAt = date('Y-m-d H:i:s');
        $closedAt = in_array($status, [Job::STATUS_COMPLETED, Job::STATUS_FAILED], true)
            ? $updatedAt
            : null;

        $sql = 'UPDATE jobs
                SET status = :status, updated_at = :updated_at, closed_at = :closed_at';

        if ($status !== Job::STATUS_FAILED) {
            $sql .= ', error = NULL';
        }

        if (in_array($status, [Job::STATUS_NEW, Job::STATUS_PROCESSING], true)) {
            $sql .= ', result = NULL';
        }

        $sql .= ' WHERE id IN (' . implode(', ', $placeholders) . ')';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':updated_at', $updatedAt);
        $stmt->bindValue(':closed_at', $closedAt);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Массовое удаление по списку ID.
     *
     * @param int[] $ids
     * @return int Количество удалённых задач
     */
    public function deleteByIds(array $ids): int
    {
        $prepared = $this->buildIdPlaceholders($ids);
        $placeholders = $prepared['placeholders'];
        $params = $prepared['params'];

        if ($placeholders === []) {
            return 0;
        }

        $sql = 'DELETE FROM jobs WHERE id IN (' . implode(', ', $placeholders) . ')';

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Готовит именованные плейсхолдеры для условия `id IN (...)`.
     *
     * @param int[] $ids
     * @return array{placeholders: array<int, string>, params: array<string, int>}
     */
    private function buildIdPlaceholders(array $ids): array
    {
        $placeholders = [];
        $params = [];
        $index = 0;

        foreach ($ids as $id) {
            $placeholder = ':id' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int) $id;
            $index++;
        }

        return [
            'placeholders' => $placeholders,
            'params' => $params,
        ];
    }

    public function deleteOldRecords(int $daysToKeep = 30): int
    {
        $sql = "DELETE FROM jobs 
                WHERE created_at < datetime('now', '-{$daysToKeep} days')";

        return $this->pdo->exec($sql);
    }
}
