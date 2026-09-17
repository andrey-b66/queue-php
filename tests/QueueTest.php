<?php

declare(strict_types=1);

namespace Integrat\Queue\Tests;

use Integrat\Queue\Job;
use Integrat\Queue\Queue;
use PHPUnit\Framework\TestCase;

final class QueueTest extends TestCase
{
    use TemporaryDatabase;

    protected function setUp(): void
    {
        $this->createDatabase();
    }

    protected function tearDown(): void
    {
        $this->removeDatabase();
    }

    /** push() сохраняет все поля задачи и присваивает id; findById() для несуществующего id — null */
    public function testPushSavesJobAndAssignsId(): void
    {
        $job = $this->queue->push(Job::create('crm', '{"order_id":42}', 'заметка')->markFailed('частично', 'таймаут'));

        $this->assertSame(1, $job->id);
        $this->assertEquals($job, $this->queue->findById(1));
        $this->assertNull($this->queue->findById(2));
    }

    /**
     * find() без фильтра: страницы по limit, за последней страницей — пустой массив. По умолчанию
     * задачи идут в порядке поступления, с $newestFirst — в обратном. count() без фильтра — все задачи.
     */
    public function testFindWithoutFilterPagesAndSortsByArrival(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queue->push(Job::create('crm', '{}'));
        }

        $this->assertSame([1, 2], array_column($this->queue->find([], 1, 2), 'id'));
        $this->assertSame([5], array_column($this->queue->find([], 3, 2), 'id'));
        $this->assertSame([], $this->queue->find([], 4, 2));
        $this->assertSame([5, 4, 3], array_column($this->queue->find([], 1, 3, true), 'id'));
        $this->assertSame(5, $this->queue->count());
    }

    /**
     * Условия createdFrom и createdTo: обе границы включаются, секунда за границей уже не попадает;
     * каждую границу можно задать без другой.
     */
    public function testFindByCreatedIncludesBoundsAndAllowsOneSide(): void
    {
        foreach (['2026-09-10 23:59:59', '2026-09-11 00:00:00', '2026-09-11 23:59:59', '2026-09-12 00:00:00'] as $createdAt) {
            $job = Job::create('crm', '{}');
            $job->createdAt = $createdAt;
            $this->queue->push($job);
        }

        $day = ['createdFrom' => '2026-09-11 00:00:00', 'createdTo' => '2026-09-11 23:59:59'];

        $this->assertSame([2, 3], array_column($this->queue->find($day), 'id'));
        $this->assertSame(2, $this->queue->count($day));

        $this->assertSame([2, 3, 4], array_column($this->queue->find(['createdFrom' => '2026-09-11 00:00:00']), 'id'));
        $this->assertSame([1, 2, 3], array_column($this->queue->find(['createdTo' => '2026-09-11 23:59:59']), 'id'));
    }

    /**
     * Условия поиска текста: каждое ищет только в своём поле, текст находится и как есть, и в записи
     * json_encode() — кириллица, слеши, JSON строкой. Символ % ищется буквально, регистр учитывается.
     */
    public function testFindByTextInEachField(): void
    {
        $this->queue->push(Job::create('crm', '{"email":"ivan@example.com"}'));
        // json_encode() без флагов экранирует кириллицу и слеши
        $this->queue->push(Job::create('crm', (string) json_encode(['name' => 'Иван', 'site' => 'https://crm.example'])));
        // JSON, вложенный строкой: его кавычки экранированы
        $this->queue->push(Job::create('crm', '{"items":"[{\"sku\":\"A-1\"}]"}'));
        $this->queue->push(Job::create('crm', 'скидка 100%'));
        $this->queue->push(Job::create('crm', '{}', 'повторная отправка'));
        $this->queue->push(Job::create('crm', '{}')->markCompleted((string) json_encode(['name' => 'Иван'])));
        $this->queue->push(Job::create('crm', '{}')->markFailed(null, 'Таймаут соединения'));
        // Тот же текст в payload: условием по другому полю не находится
        $this->queue->push(Job::create('crm', '{"note":"повторная отправка, Таймаут"}'));

        foreach ([
            [['payloadContains' => 'ivan@'], [1]],
            [['payloadContains' => 'Иван'], [2]],
            [['payloadContains' => 'https://crm.example'], [2]],
            [['payloadContains' => '"sku":"A-1"'], [3]],
            [['payloadContains' => '100%'], [4]],
            [['payloadContains' => 'IVAN@'], []],
            [['infoContains' => 'повторная'], [5]],
            [['resultContains' => 'Иван'], [6]],
            [['errorContains' => 'Таймаут'], [7]],
            [['errorContains' => 'таймаут'], []],
        ] as [$filter, $ids]) {
            $this->assertSame($ids, array_column($this->queue->find($filter), 'id'), json_encode($filter, JSON_UNESCAPED_UNICODE));
            $this->assertSame(count($ids), $this->queue->count($filter));
        }
    }

    /**
     * Все условия сразу: задача должна подходить под каждое, источник совпадает точно. Пустая строка
     * и null значат «условие не задано» — и в find(), и в count().
     */
    public function testFindCombinesAllGivenConditions(): void
    {
        foreach ([
            [Job::STATUS_FAILED, 'crm', '2026-09-11 12:00:00', 'ivan@example.com'],   // 1: подходит под всё
            [Job::STATUS_NEW, 'crm', '2026-09-11 12:00:00', 'ivan@example.com'],      // 2: другой статус
            [Job::STATUS_FAILED, 'crm-old', '2026-09-11 12:00:00', 'ivan@example.com'], // 3: другой источник
            [Job::STATUS_FAILED, 'crm', '2026-09-12 12:00:00', 'ivan@example.com'],   // 4: другой день
            [Job::STATUS_FAILED, 'crm', '2026-09-11 12:00:00', 'petr@example.com'],   // 5: другой текст
        ] as [$status, $source, $createdAt, $email]) {
            $job = Job::create($source, '{"email":"' . $email . '"}');
            $job->status = $status;
            $job->createdAt = $createdAt;
            $this->queue->push($job);
        }

        $filter = [
            'status' => Job::STATUS_FAILED,
            'source' => 'crm',
            'createdFrom' => '2026-09-11 00:00:00',
            'createdTo' => '2026-09-11 23:59:59',
            'payloadContains' => 'ivan@',
        ];

        $this->assertSame([1], array_column($this->queue->find($filter), 'id'));
        $this->assertSame(1, $this->queue->count($filter));

        $filter['source'] = '';
        $filter['createdTo'] = null;

        $this->assertSame([1, 3, 4], array_column($this->queue->find($filter), 'id'));
        $this->assertSame(3, $this->queue->count($filter));
    }

    /**
     * find() сортирует по created_at, updated_at и closed_at — каждое поле по своей колонке, в обе
     * стороны. Пустой closed_at меньше любой даты.
     */
    public function testFindSortsByDateFields(): void
    {
        // У каждого поля свой порядок задач: перепутанная колонка даст другой список
        foreach ([
            ['2026-09-10 12:00:00', '2026-09-12 12:00:00', '2026-09-11 12:00:00'],
            ['2026-09-11 12:00:00', '2026-09-10 12:00:00', '2026-09-12 12:00:00'],
            ['2026-09-12 12:00:00', '2026-09-11 12:00:00', '2026-09-10 12:00:00'],
            ['2026-09-13 12:00:00', '2026-09-13 12:00:00', null],
        ] as [$createdAt, $updatedAt, $closedAt]) {
            $job = Job::create('crm', '{}');
            $job->createdAt = $createdAt;
            $job->updatedAt = $updatedAt;
            $job->closedAt = $closedAt;
            $this->queue->push($job);
        }

        foreach (['createdAt' => [1, 2, 3, 4], 'updatedAt' => [2, 3, 1, 4], 'closedAt' => [4, 3, 1, 2]] as $field => $ids) {
            $this->assertSame($ids, array_column($this->queue->find([], 1, 50, false, $field), 'id'), $field);
            $this->assertSame(array_reverse($ids), array_column($this->queue->find([], 1, 50, true, $field), 'id'), $field);
        }
    }

    /**
     * Задачи с одинаковым временем идут по id в том же направлении, поэтому при листании
     * ни одна не теряется и не повторяется.
     */
    public function testFindSortsEqualTimesByIdAcrossPages(): void
    {
        foreach (['2026-09-11 12:00:00', '2026-09-11 12:00:00', '2026-09-10 12:00:00', '2026-09-11 12:00:00'] as $createdAt) {
            $job = Job::create('crm', '{}');
            $job->createdAt = $createdAt;
            $this->queue->push($job);
        }

        $newestFirst = [];

        for ($page = 1; $page <= 2; $page++) {
            $newestFirst[] = array_column($this->queue->find([], $page, 2, true, 'createdAt'), 'id');
        }

        $this->assertSame([[4, 2], [1, 3]], $newestFirst);
        $this->assertSame([3, 1, 2, 4], array_column($this->queue->find([], 1, 50, false, 'createdAt'), 'id'));
    }

    /**
     * Неверные аргументы — InvalidArgumentException с понятным текстом, а не молча все задачи
     * или название поля в SQL.
     */
    public function testRejectsInvalidArguments(): void
    {
        $calls = [
            'Неизвестное условие фильтра: state' => fn () => $this->queue->find(['state' => Job::STATUS_NEW]),
            'Некорректная пагинация: page=0, limit=50' => fn () => $this->queue->find([], 0),
            'Неизвестное поле сортировки: created_at; DROP TABLE jobs'
                => fn () => $this->queue->find([], 1, 50, false, 'created_at; DROP TABLE jobs'),
            'Неизвестный статус: done' => fn () => $this->queue->mark([1], 'done'),
        ];

        foreach ($calls as $message => $call) {
            try {
                $call();
                $this->fail("Нет исключения: {$message}");
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    /** listSources() — источники без повторов и пустых, по алфавиту, строками */
    public function testListSourcesReturnsDistinctNonEmptySourcesAlphabetically(): void
    {
        // '42' — источник из цифр тоже должен вернуться строкой
        foreach (['shop', 'crm', '', 'shop', 'b2b', '42'] as $source) {
            $this->queue->push(Job::create($source, '{}'));
        }

        $this->assertSame(['42', 'b2b', 'crm', 'shop'], $this->queue->listSources());
    }

    /** update() записывает изменения задачи, кроме created_at; задачу, которой нет в базе, не создаёт */
    public function testUpdateSavesChangesExceptCreatedAt(): void
    {
        $job = Job::create('crm', '{}');
        $job->createdAt = '2026-09-01 12:00:00';
        $this->queue->push($job);

        $job->info = 'проверено';
        $job->createdAt = '2026-09-15 12:00:00';
        $this->queue->update($job->markCompleted('готово'));

        $saved = $this->queue->findById($job->id);
        $this->assertSame(Job::STATUS_COMPLETED, $saved->status);
        $this->assertSame('готово', $saved->result);
        $this->assertSame('проверено', $saved->info);
        $this->assertNotNull($saved->closedAt);
        $this->assertSame('2026-09-01 12:00:00', $saved->createdAt);

        $missing = Job::create('crm', '{}');
        $missing->id = 999;
        $this->queue->update($missing);

        $this->assertNull($this->queue->findById(999));
        $this->assertSame(1, $this->queue->count());
    }

    /**
     * mark() переводит задачи теми же переходами, что и методы задачи, и записывает результат:
     * new и processing стирают следы прогона, completed и failed закрывают задачу. Несуществующий id
     * пропускается, повторный считается один раз.
     */
    public function testMarkAppliesJobTransitions(): void
    {
        $this->queue->push(Job::create('crm', '{}')->markFailed('частично', 'таймаут'));
        $this->queue->push(Job::create('crm', '{}')->markFailed('частично', 'таймаут'));
        $this->queue->push(Job::create('crm', '{}')->markFailed('частично', 'таймаут'));
        $this->queue->push(Job::create('crm', '{}'));

        $this->assertSame(1, $this->queue->mark([1, 999, '1'], Job::STATUS_NEW));
        $this->assertSame(1, $this->queue->mark([2], Job::STATUS_PROCESSING));
        $this->assertSame(1, $this->queue->mark([3], Job::STATUS_COMPLETED));
        $this->assertSame(1, $this->queue->mark([4], Job::STATUS_FAILED));

        foreach ([
            1 => [Job::STATUS_NEW, false, null],
            2 => [Job::STATUS_PROCESSING, false, null],
            3 => [Job::STATUS_COMPLETED, true, 'частично'],
            4 => [Job::STATUS_FAILED, true, null],
        ] as $id => [$status, $closed, $result]) {
            $job = $this->queue->findById($id);
            $this->assertSame($status, $job->status, "id {$id}");
            $this->assertSame($closed, $job->closedAt !== null, "id {$id}");
            $this->assertSame($result, $job->result, "id {$id}");
            $this->assertNull($job->error, "id {$id}");
        }
    }

    /** Папку под базу создать нельзя — конструктор бросает RuntimeException с путём к ней */
    public function testQueueReportsFolderThatCannotBeCreated(): void
    {
        // Папку нельзя создать внутри обычного файла
        $file = $this->dbDir . '/not-a-folder';
        file_put_contents($file, '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Не удалось создать папку для базы очереди ' . $file . '/sub');

        new Queue($file . '/sub/jobs.sqlite');
    }

    /**
     * delete() удаляет задачи по id и считает каждую один раз: повторный и несуществующий id
     * в счёт не идут, пустой список — 0.
     */
    public function testDeleteCountsEachJobOnce(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->queue->push(Job::create('crm', '{}'));
        }

        $this->assertSame(3, $this->queue->delete([1, '2', 3, 1, 999]));
        $this->assertSame([4, 5], array_column($this->queue->find(), 'id'));
        $this->assertSame(0, $this->queue->delete([]));
    }

    /**
     * deleteOldRecords() удаляет только completed и failed, закрытые раньше срока. Недавно закрытые,
     * а также new и processing, даже давно не менявшиеся, остаются.
     */
    public function testDeleteOldRecordsRemovesOnlyOldClosedJobs(): void
    {
        $old = gmdate(Job::DATE_FORMAT, time() - 40 * 86400);

        $oldCompleted = Job::create('crm', '{}')->markCompleted();
        $oldCompleted->closedAt = $old;
        $this->queue->push($oldCompleted);

        $oldFailed = Job::create('crm', '{}')->markFailed(null, 'таймаут');
        $oldFailed->closedAt = $old;
        $this->queue->push($oldFailed);

        $this->queue->push(Job::create('crm', '{}')->markCompleted());

        $oldNew = Job::create('crm', '{}');
        $oldNew->createdAt = $old;
        $oldNew->updatedAt = $old;
        $this->queue->push($oldNew);

        $stuckProcessing = Job::create('crm', '{}')->markProcessing();
        $stuckProcessing->updatedAt = $old;
        $this->queue->push($stuckProcessing);

        $this->assertSame(2, $this->queue->deleteOldRecords(30));
        $this->assertSame([3, 4, 5], array_column($this->queue->find(), 'id'));
    }
}
