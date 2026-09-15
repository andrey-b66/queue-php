<?php

declare(strict_types=1);

namespace Integrat\Queue\Storage;

use Integrat\Queue\Job;
use PDO;
use PDOException;

class SqliteJobRepository
{
    /**
     * Сколько id передавать в одном запросе `id IN (...)`. SQLite до 3.32
     * принимает не больше 999 параметров на запрос, а такие версии стоят
     * в сборках PHP 7.4 под Windows и в Ubuntu 20.04 и старше.
     */
    private const IDS_PER_QUERY = 500;

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
            throw new \Exception(
                'Не удалось инициализировать базу очереди: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function createJobsTable(): void
    {
        $query = "CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source TEXT,
            payload TEXT,
            status TEXT,
            created_at TEXT,
            updated_at TEXT,
            closed_at TEXT,
            info TEXT,
            result TEXT,
            error TEXT
        )";
        $this->pdo->exec($query);
    }

    public function create(Job $job): Job
    {
        $sql = "INSERT INTO jobs (
            source,
            payload,
            status,
            created_at,
            updated_at,
            closed_at,
            info,
            result,
            error
        ) VALUES (
            :source,
            :payload,
            :status,
            :created_at,
            :updated_at,
            :closed_at,
            :info,
            :result,
            :error
        )";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':source' => $job->source,
            ':payload' => $job->payload,
            ':status' => $job->status,
            ':created_at' => $job->createdAt,
            ':updated_at' => $job->updatedAt,
            ':closed_at' => $job->closedAt,
            ':info' => $job->info,
            ':result' => $job->result,
            ':error' => $job->error,
        ]);

        // Записали все поля объекта, так что в базе лежит ровно он —
        // перечитывать нечего, не хватало только id.
        $job->id = (int) $this->pdo->lastInsertId();

        return $job;
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
    public function findFiltered(array $filters = [], int $page = 1, int $limit = 50): array
    {
        if ($limit < 1 || $page < 1) {
            throw new \InvalidArgumentException("Некорректная пагинация: page={$page}, limit={$limit}");
        }

        $offset = ($page - 1) * $limit;

        $conditions = $this->buildFilterConditions($filters);
        $where = $conditions['where'];
        $params = $conditions['params'];

        $sort = strtoupper((string) ($filters['sort'] ?? 'ASC'));

        if (!in_array($sort, ['ASC', 'DESC'], true)) {
            $sort = 'ASC';
        }

        $sql = 'SELECT * FROM jobs';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        // id растёт в порядке вставки — это и есть порядок поступления. В отличие
        // от created_at, он не зависит от часов и часового пояса записавшего процесса
        $sql .= " ORDER BY id {$sort} LIMIT :limit OFFSET :offset";

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
     *     id?: int,
     *     status?: string,
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
    public function findSources(): array
    {
        $statement = $this->pdo->query(
            "SELECT DISTINCT source
             FROM jobs
             WHERE source <> ''
             ORDER BY source ASC"
        );

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Ключи, которые понимает buildFilterConditions() */
    private const KNOWN_FILTERS = [
        'id',
        'status',
        'source',
        'search',
        'created_from',
        'created_to',
        'sort',
    ];

    /**
     * Собирает условия WHERE и параметры для фильтруемых выборок.
     *
     * Незнакомый ключ означает опечатку в фильтре. Вернуть в этом случае всю
     * таблицу опаснее всего: вызывающий думает, что отобрал нужное, а получил
     * всё подряд. Поэтому такой фильтр не находит ничего.
     *
     * Переданный ключ ищется по значению как есть. Пустая дата выборку не ограничивает.
     *
     * @param array{
     *     id?: int,
     *     status?: string,
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

        if (array_diff(array_keys($filters), self::KNOWN_FILTERS) !== []) {
            return [
                'where' => ['1 = 0'],
                'params' => [],
            ];
        }

        if (isset($filters['id'])) {
            $where[] = 'id = :id';
            $params[':id'] = (int) $filters['id'];
        }

        if (isset($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['source'])) {
            $where[] = 'source = :source';
            $params[':source'] = $filters['source'];
        }

        if (isset($filters['search'])) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['search']);
            $params[':search'] = '%' . $search . '%';
            $where[] = "(source LIKE :search ESCAPE '\\'
                     OR payload LIKE :search ESCAPE '\\'
                     OR status LIKE :search ESCAPE '\\'
                     OR info LIKE :search ESCAPE '\\'
                     OR result LIKE :search ESCAPE '\\'
                     OR error LIKE :search ESCAPE '\\'
                     OR CAST(id AS TEXT) LIKE :search ESCAPE '\\')";
        }

        if (!empty($filters['created_from'])) {
            $where[] = 'created_at >= :created_from';
            $params[':created_from'] = $filters['created_from'];
        }

        if (!empty($filters['created_to'])) {
            $where[] = 'created_at <= :created_to';
            $params[':created_to'] = $filters['created_to'];
        }

        return [
            'where' => $where,
            'params' => $params,
        ];
    }

    /**
     * Записать задачу целиком.
     *
     * Не переписываются `id` (ключ) и `created_at` — момент создания задачи
     * неизменен. `updated_at` берётся из объекта: временем владеет модель,
     * она проставляет его в mark-методах.
     *
     * @return Job|null null, если строки с таким id уже нет
     */
    public function update(Job $job): ?Job
    {
        return $this->updateMany([$job]) === 1 ? $job : null;
    }

    /**
     * Записать несколько задач целиком — по тем же правилам, что и update().
     *
     * Задачи пишутся по одной, без транзакции: если запись оборвётся на середине,
     * уже записанные задачи останутся записанными.
     *
     * @param Job[] $jobs
     * @return int Количество записанных задач; задачи, которых уже нет, не считаются
     */
    public function updateMany(array $jobs): int
    {
        $sql = "UPDATE jobs
                SET source = :source,
                    payload = :payload,
                    status = :status,
                    updated_at = :updated_at,
                    closed_at = :closed_at,
                    info = :info,
                    result = :result,
                    error = :error
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $updated = 0;

        foreach ($jobs as $job) {
            $stmt->execute([
                ':id' => $job->id,
                ':source' => $job->source,
                ':payload' => $job->payload,
                ':status' => $job->status,
                ':updated_at' => $job->updatedAt,
                ':closed_at' => $job->closedAt,
                ':info' => $job->info,
                ':result' => $job->result,
                ':error' => $job->error,
            ]);

            $updated += $stmt->rowCount();
        }

        return $updated;
    }

    /**
     * Задачи по списку ID, по возрастанию id. Несуществующие id пропускаются.
     *
     * @param int[] $ids
     * @return Job[]
     */
    public function findByIds(array $ids): array
    {
        $jobs = [];

        foreach ($this->chunkIds($ids) as $chunk) {
            $stmt = $this->prepareForIds('SELECT * FROM jobs', $chunk, ' ORDER BY id ASC');
            $stmt->execute();

            foreach ($stmt->fetchAll() as $row) {
                $jobs[] = Job::fromDatabase($row);
            }
        }

        return $jobs;
    }

    /**
     * Массовое удаление по списку ID.
     *
     * Пачки удаляются по очереди, без транзакции: если удаление оборвётся
     * на середине, уже удалённые пачки не вернутся.
     *
     * @param int[] $ids
     * @return int Количество удалённых задач
     */
    public function deleteByIds(array $ids): int
    {
        $deleted = 0;

        foreach ($this->chunkIds($ids) as $chunk) {
            $stmt = $this->prepareForIds('DELETE FROM jobs', $chunk);
            $stmt->execute();
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    /**
     * Разбивает id на пачки по IDS_PER_QUERY — по возрастанию и без дублей.
     *
     * @param int[] $ids
     * @return array<int, int[]>
     */
    private function chunkIds(array $ids): array
    {
        // Без дублей: один id в двух пачках обработался бы дважды
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return array_chunk($ids, self::IDS_PER_QUERY);
    }

    /**
     * Готовит запрос `$sql WHERE id IN (...)$suffix` с уже привязанными id.
     *
     * @param int[] $ids
     */
    private function prepareForIds(string $sql, array $ids, string $suffix = ''): \PDOStatement
    {
        $params = [];

        foreach (array_values($ids) as $index => $id) {
            $params[':id' . $index] = $id;
        }

        $stmt = $this->pdo->prepare(
            $sql . ' WHERE id IN (' . implode(', ', array_keys($params)) . ')' . $suffix
        );

        foreach ($params as $placeholder => $id) {
            $stmt->bindValue($placeholder, $id, PDO::PARAM_INT);
        }

        return $stmt;
    }

    /**
     * Удалить задачи, закрытые больше указанного числа дней назад.
     *
     * Срок считается от закрытия, а не от создания: задача, которая провисела
     * в очереди месяц и закрылась вчера, должна прожить полный срок хранения.
     * Если `closed_at` не заполнен (записи старых версий), берётся `updated_at`.
     *
     * Незакрытые задачи не трогаем. Задача, зависшая в `processing`, — это повод
     * разобраться, а не мусор, и она должна дожить до разбора.
     *
     * @return int Количество удалённых задач
     */
    public function deleteOldRecords(int $daysToKeep = 30): int
    {
        $cutoff = gmdate(Job::DATE_FORMAT, time() - max(0, $daysToKeep) * 86400);

        $sql = 'DELETE FROM jobs
                WHERE COALESCE(closed_at, updated_at) < :cutoff
                  AND status IN (:completed, :failed)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':cutoff' => $cutoff,
            ':completed' => Job::STATUS_COMPLETED,
            ':failed' => Job::STATUS_FAILED,
        ]);

        return $stmt->rowCount();
    }
}
