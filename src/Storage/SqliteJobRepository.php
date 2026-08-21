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

            $this->createCallTable();
        } catch (PDOException $e) {
            throw new \Exception("Failed to initialize queue database: $e");
        }
    }

    private function createCallTable(): void
    {
        $query = "CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_url TEXT,
            to_url TEXT,
            status TEXT,
            data TEXT,
            created_at TEXT,
            updated_at TEXT,
            error TEXT
        )";
        $this->pdo->exec($query);
    }

    public function create(Job $job): Job
    {
        $sql = "INSERT INTO jobs (
            from_url,
            to_url,
            status,
            data,
            created_at,
            updated_at
        ) VALUES (
            :from_url,
            :to_url,
            :status,
            :data,
            :created_at,
            :updated_at
        )";

        $stmt = $this->pdo->prepare($sql);

        // Используем геттеры вместо прямого доступа к свойствам
        $stmt->execute([
            ':from_url' => $job->getFromUrl(),
            ':to_url' => $job->getToUrl(),
            ':status' => $job->getStatus(),
            ':data' => $job->getData(),
            ':created_at' => $job->getCreatedAt(),
            ':updated_at' => $job->getUpdatedAt(),
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

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Job::fromDatabase($row) : null;
    }

    public function findByStatus(string $status, int $limit): array
    {
        $sql = "SELECT * FROM jobs 
                WHERE status = :status 
                ORDER BY created_at ASC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':limit' => $limit
        ]);

        return array_map(
            fn ($row) => Job::fromDatabase($row),
            $stmt->fetchAll()
        );
    }

    public function findPaginated(int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        $sql = "SELECT * FROM jobs
                ORDER BY created_at DESC, id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn ($row) => Job::fromDatabase($row),
            $stmt->fetchAll()
        );
    }

    public function findByStatusPaginated(string $status, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        $sql = "SELECT * FROM jobs
                WHERE status = :status
                ORDER BY created_at DESC, id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn ($row) => Job::fromDatabase($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Свободный поиск по подстроке во всех значимых колонках.
     * Ищет по data (JSON payload), from_url, to_url, status и id.
     *
     * @return Job[]
     */
    public function search(string $term, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        // Экранируем спецсимволы LIKE (% и _), чтобы искать подстроку буквально
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $like = '%' . $escaped . '%';

        $sql = "SELECT * FROM jobs
                WHERE data LIKE :like ESCAPE '\\'
                   OR from_url LIKE :like ESCAPE '\\'
                   OR to_url LIKE :like ESCAPE '\\'
                   OR status LIKE :like ESCAPE '\\'
                   OR CAST(id AS TEXT) LIKE :like ESCAPE '\\'
                ORDER BY created_at DESC, id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':like', $like);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn ($row) => Job::fromDatabase($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Гибкая выборка с комбинируемыми фильтрами (все через AND).
     *
     * @param array{
     *     status?: string,
     *     hook?: string,
     *     q?: string,
     *     created_from?: string,
     *     created_to?: string
     * } $filters
     * @return Job[]
     */
    public function findFiltered(array $filters = [], int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;

        [$where, $params] = $this->buildFilterConditions($filters);

        $sql = 'SELECT * FROM jobs';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn ($row) => Job::fromDatabase($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Количество задач под те же фильтры, что и findFiltered (без пагинации).
     *
     * @param array{
     *     status?: string,
     *     hook?: string,
     *     q?: string,
     *     created_from?: string,
     *     created_to?: string
     * } $filters
     */
    public function countFiltered(array $filters = []): int
    {
        [$where, $params] = $this->buildFilterConditions($filters);

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
     * Собирает условия WHERE и параметры для фильтруемых выборок.
     *
     * @param array{
     *     status?: string,
     *     hook?: string,
     *     q?: string,
     *     created_from?: string,
     *     created_to?: string
     * } $filters
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    private function buildFilterConditions(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['hook'])) {
            // Хук лежит в to_url как ?hook=name — матчим значение целиком (в конце или перед &)
            $hook = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['hook']);
            $where[] = "(to_url LIKE :hook_ends ESCAPE '\\' OR to_url LIKE :hook_mid ESCAPE '\\')";
            $params[':hook_ends'] = '%hook=' . $hook;
            $params[':hook_mid'] = '%hook=' . $hook . '&%';
        }

        if (isset($filters['q']) && $filters['q'] !== '') {
            $q = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']);
            $params[':q'] = '%' . $q . '%';
            $where[] = "(data LIKE :q ESCAPE '\\'
                     OR from_url LIKE :q ESCAPE '\\'
                     OR to_url LIKE :q ESCAPE '\\'
                     OR status LIKE :q ESCAPE '\\'
                     OR error LIKE :q ESCAPE '\\'
                     OR CAST(id AS TEXT) LIKE :q ESCAPE '\\')";
        }

        if (!empty($filters['created_from'])) {
            $where[] = 'created_at >= :created_from';
            $params[':created_from'] = $filters['created_from'] . ' 00:00:00';
        }

        if (!empty($filters['created_to'])) {
            $where[] = 'created_at <= :created_to';
            $params[':created_to'] = $filters['created_to'] . ' 23:59:59';
        }

        return [$where, $params];
    }

    public function updateStatus(Job $job): ?Job
    {
        $sql = "UPDATE jobs
                SET status = :status, updated_at = :updated_at, error = :error
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $job->getId(),
            ':status' => $job->getStatus(),
            ':updated_at' => $job->getUpdatedAt(),
            ':error' => $job->getError(),
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->findById($job->getId());
    }

    /**
     * Массовая смена статуса по списку ID.
     *
     * Ошибка сбрасывается, если новый статус не `failed` — она относилась
     * к прошлому прогону и для pending/completed уже неактуальна.
     *
     * @param int[] $ids
     * @return int Количество затронутых задач
     */
    public function updateStatusByIds(array $ids, string $status): int
    {
        [$placeholders, $params] = $this->buildIdPlaceholders($ids);

        if ($placeholders === []) {
            return 0;
        }

        $sql = 'UPDATE jobs SET status = :status, updated_at = :updated_at';

        if ($status !== Job::STATUS_FAILED) {
            $sql .= ', error = NULL';
        }

        $sql .= ' WHERE id IN (' . implode(', ', $placeholders) . ')';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'));

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
        [$placeholders, $params] = $this->buildIdPlaceholders($ids);

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
     * @return array{0: array<int, string>, 1: array<string, int>}
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

        return [$placeholders, $params];
    }

    public function deleteOldRecords(int $daysToKeep = 30): int
    {
        $sql = "DELETE FROM jobs 
                WHERE created_at < datetime('now', '-{$daysToKeep} days')";

        return $this->pdo->exec($sql);
    }
}
