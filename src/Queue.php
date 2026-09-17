<?php

declare(strict_types=1);

namespace Integrat\Queue;

use PDO;

/**
 * Очередь задач на SQLite.
 *
 * Ошибка базы выходит наружу как есть — PDOException.
 */
class Queue
{
    private PDO $pdo;

    /**
     * Файл базы и папку под него очередь создаёт сама, таблицу `jobs` — тоже.
     *
     * @throws \RuntimeException если не удалось создать папку
     */
    public function __construct(string $dbPath)
    {
        $dbDir = dirname($dbPath);

        // Папку мог только что создать другой процесс — поэтому после неудачного
        // mkdir проверяем ещё раз, прежде чем считать это ошибкой
        if (!is_dir($dbDir) && !@mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
            throw new \RuntimeException(
                "Не удалось создать папку для базы очереди {$dbDir}: " . (error_get_last()['message'] ?? '')
            );
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
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
        )");
    }

    /**
     * Добавить задачу в очередь.
     */
    public function push(Job $job): Job
    {
        $sql = 'INSERT INTO jobs (source, payload, status, created_at, updated_at, closed_at, info, result, error)
                VALUES (:source, :payload, :status, :created_at, :updated_at, :closed_at, :info, :result, :error)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->fields($job) + [':created_at' => $job->createdAt]);

        // Записали все поля объекта, так что в базе лежит ровно он —
        // перечитывать нечего, не хватало только id.
        $job->id = (int) $this->pdo->lastInsertId();

        return $job;
    }

    public function findById(int $jobId): ?Job
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);

        $row = $stmt->fetch();

        return $row ? Job::fromDatabase($row) : null;
    }

    /**
     * Задачи, которые подходят под все условия фильтра, постранично.
     *
     * Условия — ключи массива, любое можно не указывать; пустая строка и null
     * тоже значат «условие не задано». Пустой фильтр — все задачи.
     *
     * - `status`          — статус, точное совпадение;
     * - `source`          — источник, точное совпадение;
     * - `createdFrom`     — created_at не раньше, включительно;
     * - `createdTo`       — created_at не позже, включительно;
     * - `infoContains`, `resultContains`, `errorContains` — в info, result или error есть этот текст;
     * - `payloadContains` — то же для payload.
     *
     * Даты — в UTC, в формате Job::DATE_FORMAT. Текст ищется как есть,
     * с учётом регистра. json_encode() без флагов пишет кириллицу, слеши и кавычки
     * экранированными (`\u0418`, `\/`, `\"`), поэтому ищется и такая запись текста —
     * так находится и JSON, вложенный строкой.
     *
     * Сортировка по умолчанию — по id: он растёт в порядке вставки, это и есть порядок
     * поступления. В отличие от дат, он не зависит от часов и часового пояса записавшего
     * процесса. $sortBy — `id`, `createdAt`, `updatedAt` или `closedAt`. По умолчанию
     * сначала старые, $newestFirst = true — сначала свежие. Даты записаны с точностью
     * до секунды: задачи с одинаковым временем идут по id в том же направлении, поэтому
     * при листании ни одна не теряется и не повторяется. Пустой closed_at SQLite считает
     * меньше любой даты.
     *
     * @param array<string, string|null> $filter
     * @return Job[]
     * @throws \InvalidArgumentException если в фильтре неизвестное условие, неизвестное поле
     *                                   сортировки, page или limit меньше 1
     */
    public function find(
        array $filter = [],
        int $page = 1,
        int $limit = 50,
        bool $newestFirst = false,
        string $sortBy = 'id'
    ): array {
        if ($limit < 1 || $page < 1) {
            throw new \InvalidArgumentException("Некорректная пагинация: page={$page}, limit={$limit}");
        }

        // Название колонки попадает в SQL как есть, поэтому только из этого списка
        $columns = ['id' => 'id', 'createdAt' => 'created_at', 'updatedAt' => 'updated_at', 'closedAt' => 'closed_at'];

        if (!isset($columns[$sortBy])) {
            throw new \InvalidArgumentException("Неизвестное поле сортировки: {$sortBy}");
        }

        [$where, $params] = $this->buildWhere($filter);
        $direction = $newestFirst ? 'DESC' : 'ASC';
        $order = $sortBy === 'id' ? "id {$direction}" : "{$columns[$sortBy]} {$direction}, id {$direction}";

        $stmt = $this->pdo->prepare("SELECT * FROM jobs{$where} ORDER BY {$order} LIMIT :limit OFFSET :offset");
        $stmt->execute($params + [':limit' => $limit, ':offset' => ($page - 1) * $limit]);

        $jobs = [];

        foreach ($stmt->fetchAll() as $row) {
            $jobs[] = Job::fromDatabase($row);
        }

        return $jobs;
    }

    /**
     * Сколько задач подходит под фильтр. Условия — как у find().
     *
     * @param array<string, string|null> $filter
     * @throws \InvalidArgumentException если в фильтре неизвестное условие
     */
    public function count(array $filter = []): int
    {
        [$where, $params] = $this->buildWhere($filter);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM jobs{$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Непустые источники, которые есть в очереди, по алфавиту.
     *
     * @return string[]
     */
    public function listSources(): array
    {
        $statement = $this->pdo->query(
            "SELECT DISTINCT source
             FROM jobs
             WHERE source <> ''
             ORDER BY source ASC"
        );

        // Колонка source текстовая: SQLite хранит в ней любое значение как текст,
        // и PDO отдаёт строки
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Записать изменения существующей задачи.
     *
     * Пишутся все поля, кроме `id` (ключ) и `created_at` — момент создания задачи
     * неизменен. Переходы живут в модели — `$job->markCompleted('готово')` и т.п.,
     * здесь только запись в базу. Если задачи с таким id уже нет, ничего не меняется.
     */
    public function update(Job $job): void
    {
        $sql = 'UPDATE jobs
                SET source = :source, payload = :payload, status = :status, updated_at = :updated_at,
                    closed_at = :closed_at, info = :info, result = :result, error = :error
                WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->fields($job) + [':id' => $job->id]);
    }

    /**
     * Отметить задачи статусом.
     *
     * Задачи загружаются, переводятся тем же mark-методом, что и по одной
     * (`markNew()`, `markFailed()` и т.д., без аргументов), и записываются по одной.
     * Транзакции нет: если воркер в это время меняет те же задачи, одна запись
     * перекроет другую, а при сбое уже записанные задачи останутся записанными.
     * Несуществующие id пропускаются.
     *
     * @param int[] $jobIds
     * @return int Количество записанных задач
     * @throws \InvalidArgumentException если статус неизвестен
     */
    public function mark(array $jobIds, string $status): int
    {
        $transitions = [
            Job::STATUS_NEW => fn (Job $job) => $job->markNew(),
            Job::STATUS_PROCESSING => fn (Job $job) => $job->markProcessing(),
            Job::STATUS_COMPLETED => fn (Job $job) => $job->markCompleted(),
            Job::STATUS_FAILED => fn (Job $job) => $job->markFailed(),
        ];

        if (!isset($transitions[$status])) {
            throw new \InvalidArgumentException("Неизвестный статус: {$status}");
        }

        // Без дублей: иначе одну задачу записали бы и посчитали дважды.
        // Одинаковые id ложатся в один ключ
        $ids = [];

        foreach ($jobIds as $jobId) {
            $ids[(int) $jobId] = (int) $jobId;
        }

        // Пишутся только поля, которые меняют mark-методы: source, payload и info,
        // изменённые в это время воркером, не затираются
        $update = $this->pdo->prepare(
            'UPDATE jobs
             SET status = :status, updated_at = :updated_at, closed_at = :closed_at, result = :result, error = :error
             WHERE id = :id'
        );

        $updated = 0;

        foreach ($ids as $id) {
            $job = $this->findById($id);

            if ($job === null) {
                continue;
            }

            $transitions[$status]($job);

            $update->execute([
                ':id' => $job->id,
                ':status' => $job->status,
                ':updated_at' => $job->updatedAt,
                ':closed_at' => $job->closedAt,
                ':result' => $job->result,
                ':error' => $job->error,
            ]);

            $updated += $update->rowCount();
        }

        return $updated;
    }

    /**
     * Удалить задачи по списку id.
     *
     * Задачи удаляются по одной, без транзакции: если удаление оборвётся
     * на середине, уже удалённые задачи не вернутся.
     *
     * @param int[] $jobIds
     * @return int Количество удалённых задач
     */
    public function delete(array $jobIds): int
    {
        $deleted = 0;
        $stmt = $this->pdo->prepare('DELETE FROM jobs WHERE id = :id');

        // Повторный id уже ничего не удалит и в счёт не попадёт
        foreach ($jobIds as $jobId) {
            $stmt->execute([':id' => (int) $jobId]);
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    /**
     * Удалить задачи, закрытые больше указанного числа дней назад.
     *
     * Срок считается от закрытия, а не от создания: задача, которая провисела
     * в очереди месяц и закрылась вчера, должна прожить полный срок хранения.
     *
     * Незакрытые задачи не трогаем. Задача, зависшая в `processing`, — это повод
     * разобраться, а не мусор, и она должна дожить до разбора. Отдельно статус
     * не проверяется: closed_at ставят только markCompleted() и markFailed(),
     * у остальных он пуст, а пустое значение сравнение не проходит.
     *
     * @return int Количество удалённых задач
     */
    public function deleteOldRecords(int $daysToKeep): int
    {
        $cutoff = gmdate(Job::DATE_FORMAT, time() - $daysToKeep * 86400);

        $stmt = $this->pdo->prepare('DELETE FROM jobs WHERE closed_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Поля задачи как параметры запроса — все, кроме id и created_at: их push() и update()
     * добавляют сами.
     *
     * @return array<string, string|null>
     */
    private function fields(Job $job): array
    {
        return [
            ':source' => $job->source,
            ':payload' => $job->payload,
            ':status' => $job->status,
            ':updated_at' => $job->updatedAt,
            ':closed_at' => $job->closedAt,
            ':info' => $job->info,
            ':result' => $job->result,
            ':error' => $job->error,
        ];
    }

    /**
     * Фильтр find() и count() — в SQL: ' WHERE ...' (пустая строка, если условий нет)
     * и параметры к нему. Условия описаны у find().
     *
     * @param array<string, string|null> $filter
     * @return array{0: string, 1: array<string, string>}
     * @throws \InvalidArgumentException если в фильтре неизвестное условие
     */
    private function buildWhere(array $filter): array
    {
        $conditions = [
            'status' => 'status = :status',
            'source' => 'source = :source',
            'createdFrom' => 'created_at >= :createdFrom',
            'createdTo' => 'created_at <= :createdTo',
        ];

        // Поиск текста: условие => поле, в котором ищется текст
        $textConditions = [
            'infoContains' => 'info',
            'resultContains' => 'result',
            'errorContains' => 'error',
            'payloadContains' => 'payload',
        ];

        $where = [];
        $params = [];

        foreach ($filter as $name => $value) {
            // Опечатка в названии условия молча отобрала бы задачи без этого условия
            if (!isset($conditions[$name]) && !isset($textConditions[$name])) {
                throw new \InvalidArgumentException("Неизвестное условие фильтра: {$name}");
            }

            $value = (string) $value;

            if ($value === '') {
                continue;
            }

            $params[":{$name}"] = $value;

            if (isset($conditions[$name])) {
                $where[] = $conditions[$name];
                continue;
            }

            // Текст ищется и так, как его записал бы json_encode(): без кавычек по краям.
            // Невалидный UTF-8 json_encode() не записывает — тогда ищем только как есть
            $escaped = json_encode($value);
            $params[":{$name}Escaped"] = $escaped === false ? $value : substr($escaped, 1, -1);

            // instr, а не LIKE: символы % и _ в тексте ищутся буквально
            $column = $textConditions[$name];
            $where[] = "(instr({$column}, :{$name}) > 0 OR instr({$column}, :{$name}Escaped) > 0)";
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }
}
