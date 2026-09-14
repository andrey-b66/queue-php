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
     */
    public function findFiltered(array $filters = [], int $page = 1, int $limit = 50): array
    {
        // Отрицательный LIMIT в SQLite снимает ограничение и отдаёт всю таблицу
        $page = max(1, $page);
        $limit = max(1, $limit);
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

        if (!empty($filters['id'])) {
            $where[] = 'id = :id';
            $params[':id'] = (int) $filters['id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['source'])) {
            $where[] = 'source = :source';
            $params[':source'] = $filters['source'];
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
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

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $job;
    }

    /**
     * Массовая смена статуса по списку ID.
     *
     * Что именно означает переход, задано в mark-методах `Job`; здесь то же
     * самое, но одним UPDATE на весь список, без загрузки объектов.
     *
     * Ошибка сбрасывается, если новый статус не `failed` — она относилась
     * к прошлому прогону и для new/processing/completed уже неактуальна.
     * Результат сбрасывается при возврате в `new`/`processing`: задача будет
     * выполняться заново, поэтому прошлый результат к ней больше не относится.
     * Info не трогаем ни при каком статусе: это заметка вызывающего, а не
     * след прогона, и к смене статуса она отношения не имеет.
     *
     * @param int[] $ids
     * @return int Количество затронутых задач
     */
    public function updateStatusByIds(array $ids, string $status): int
    {
        $updatedAt = gmdate(Job::DATE_FORMAT);
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

        return $this->executeForIds($sql, $ids, [
            ':status' => $status,
            ':updated_at' => $updatedAt,
            ':closed_at' => $closedAt,
        ]);
    }

    /**
     * Массовое удаление по списку ID.
     *
     * @param int[] $ids
     * @return int Количество удалённых задач
     */
    public function deleteByIds(array $ids): int
    {
        return $this->executeForIds('DELETE FROM jobs', $ids);
    }

    /**
     * Выполняет `$sql WHERE id IN (...)` пачками по IDS_PER_QUERY id.
     *
     * Все пачки идут в одной транзакции: если упадёт любая, не применится ни одна.
     *
     * @param int[] $ids
     * @param array<string, string|null> $params Общие параметры запроса, кроме id
     * @return int Количество затронутых строк
     */
    private function executeForIds(string $sql, array $ids, array $params = []): int
    {
        // Без дублей: один id в двух пачках посчитался бы дважды
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return 0;
        }

        $affected = 0;

        $this->pdo->beginTransaction();

        try {
            foreach (array_chunk($ids, self::IDS_PER_QUERY) as $chunk) {
                $prepared = $this->buildIdPlaceholders($chunk);

                $stmt = $this->pdo->prepare(
                    $sql . ' WHERE id IN (' . implode(', ', $prepared['placeholders']) . ')'
                );

                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }

                foreach ($prepared['params'] as $key => $value) {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                }

                $stmt->execute();
                $affected += $stmt->rowCount();
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $affected;
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
