<?php

declare(strict_types=1);

namespace Integrat\Queue\Tests;

use Integrat\Queue\Dashboard;
use Integrat\Queue\Job;
use PHPUnit\Framework\TestCase;

/**
 * Админка без браузера: запросы подаются в process() так, как их отправила бы
 * страница, — отбор фильтром, клик по ссылке, отправка формы.
 *
 * JS на странице (отметить все, подтверждения, смена размера страницы)
 * здесь не проверяется — это ручная проверка на демо-стенде, см. README.
 */
final class DashboardTest extends TestCase
{
    use TemporaryDatabase;

    private const CSRF_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const COOKIES = ['queue_dashboard_csrf' => self::CSRF_TOKEN];

    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->createDatabase();
        $this->dashboard = new Dashboard($this->queue);
    }

    protected function tearDown(): void
    {
        unset($this->dashboard);
        $this->removeDatabase();
    }

    /**
     * Первый заход без cookie: страница со всеми задачами, свежие сверху, без кнопки «Сбросить».
     * Ставится CSRF-cookie, и тот же токен подставлен в формы.
     */
    public function testFirstVisitShowsAllJobsNewestFirstAndSetsCsrfCookie(): void
    {
        $this->queue->push(Job::create('crm', '{}'));
        $this->queue->push(Job::create('shop', '{}'));
        $this->queue->push(Job::create('crm', '{}'));

        $response = $this->dashboard->process([], [], ['REQUEST_METHOD' => 'GET'], []);

        $this->assertSame(200, $response['status']);
        $this->assertSame('text/html; charset=utf-8', $response['headers']['Content-Type']);
        $this->assertMatchesRegularExpression(
            '/^queue_dashboard_csrf=([0-9a-f]{64}); Path=\/; HttpOnly; SameSite=Lax$/',
            $response['headers']['Set-Cookie']
        );

        // Токен в формах тот же, что в cookie, — иначе ни одно действие не пройдёт
        preg_match('/queue_dashboard_csrf=([0-9a-f]{64})/', $response['headers']['Set-Cookie'], $cookie);
        $this->assertStringContainsString('name="csrf_token" value="' . $cookie[1] . '"', $response['body']);

        $this->assertSame(['3', '2', '1'], $this->shownIds($response));
        $this->assertStringContainsString('Показано задач: 3 из 3.', $response['body']);
        $this->assertStringNotContainsString('<a class="button-link secondary" href="?">', $response['body']);
    }

    /**
     * CSRF-cookie уже есть: новая не ставится, в формы идёт токен из неё. Пустой список —
     * «Задач нет», полоса листания только над таблицей.
     */
    public function testKnownCsrfCookieIsReused(): void
    {
        $response = $this->get();

        $this->assertArrayNotHasKey('Set-Cookie', $response['headers']);
        $this->assertStringContainsString('name="csrf_token" value="' . self::CSRF_TOKEN . '"', $response['body']);
        $this->assertStringContainsString('Задач нет', $response['body']);
        $this->assertSame(1, substr_count($response['body'], '<form class="list-bar" method="get">'));
    }

    /**
     * Все фильтры сразу, как их отправляет форма (незаполненные — пустыми): в списке только задачи,
     * подходящие под все условия, форма показывает каждое из них и предлагает сбросить.
     */
    public function testFiltersAreCombined(): void
    {
        $this->queue->push(Job::create('crm', '{"email":"ivan@example.com"}')->markFailed());  // подходит под всё
        $this->queue->push(Job::create('crm', '{"email":"ivan@example.com"}'));                // другой статус
        $this->queue->push(Job::create('shop', '{"email":"ivan@example.com"}')->markFailed()); // другой источник
        $this->queue->push(Job::create('crm', '{"email":"petr@example.com"}')->markFailed());  // другой текст

        $response = $this->get([
            'status' => 'failed',
            'source' => 'crm',
            'created_from' => '2000-01-01',
            'created_to' => '',
            'search_info' => '',
            'search_result' => '',
            'search_error' => '',
            'search_payload' => 'ivan@',
        ]);

        $this->assertSame(['1'], $this->shownIds($response));
        $this->assertStringContainsString('Показано задач: 1 из 1.', $response['body']);
        $this->assertMatchesRegularExpression('/<option\s+value="failed"\s+selected\s*>/', $response['body']);
        $this->assertMatchesRegularExpression('/<option\s+value="crm"\s+selected\s*>/', $response['body']);
        $this->assertMatchesRegularExpression('/name="created_from"\s+value="2000-01-01"/', $response['body']);
        $this->assertMatchesRegularExpression('/name="search_payload"\s+value="ivan@"/', $response['body']);
        $this->assertStringContainsString('<a class="button-link secondary" href="?">', $response['body']);
    }

    /**
     * Период и время — в поясе админки: выбранные дни берутся целиком по местному времени, а время
     * в таблице показано местным.
     */
    public function testPeriodAndTimesUseAdminTimezone(): void
    {
        // Сутки 11 сентября по Москве (UTC+3) — это с 10.09 21:00 до 11.09 20:59:59 по UTC
        foreach (['2026-09-10 20:59:59', '2026-09-10 21:00:00', '2026-09-11 20:59:59', '2026-09-11 21:00:00'] as $createdAt) {
            $job = Job::create('crm', '{}');
            $job->createdAt = $createdAt;
            $this->queue->push($job);
        }

        $response = (new Dashboard($this->queue, 'Europe/Moscow'))->process(
            ['created_from' => '2026-09-11', 'created_to' => '2026-09-11'],
            [],
            ['REQUEST_METHOD' => 'GET'],
            self::COOKIES
        );

        $this->assertSame(['3', '2'], $this->shownIds($response));
        $this->assertMatchesRegularExpression('/name="created_from"\s+value="2026-09-11"/', $response['body']);
        $this->assertMatchesRegularExpression('/name="created_to"\s+value="2026-09-11"/', $response['body']);
        $this->assertStringContainsString('2026-09-11 00:00:00', $response['body']);
    }

    /**
     * Поле текста ищет только в своём поле задачи, пробелы по краям обрезаются, текст остаётся в поле,
     * а форма действий ведёт обратно на тот же поиск.
     */
    public function testEachTextFieldSearchesItsOwnJobField(): void
    {
        $this->queue->push(Job::create('crm', '{}', 'timeout'));
        $this->queue->push(Job::create('crm', '{}')->markCompleted('timeout'));
        $this->queue->push(Job::create('crm', '{}')->markFailed(null, 'timeout'));
        $this->queue->push(Job::create('crm', '{"note":"timeout"}'));

        foreach (['info' => '1', 'result' => '2', 'error' => '3', 'payload' => '4'] as $field => $id) {
            // Пробелы по краям — случайность при вставке из буфера
            $response = $this->get(["search_{$field}" => ' timeout ']);

            $this->assertSame([$id], $this->shownIds($response), "search_{$field}");
            $this->assertMatchesRegularExpression("/name=\"search_{$field}\"\\s+value=\"timeout\"/", $response['body']);
            $this->assertStringContainsString(
                "<form class=\"jobs-form\" method=\"post\" action=\"?search_{$field}=timeout&amp;page=1\">",
                $response['body']
            );
        }
    }

    /** Источник, задач которого в базе уже нет, остаётся выбранным в форме, список пуст */
    public function testSourceMissingFromBaseStaysInFilter(): void
    {
        $this->queue->push(Job::create('crm', '{}'));

        $response = $this->get(['source' => 'old-crm']);

        $this->assertStringContainsString('Показано задач: 0 из 0.', $response['body']);
        $this->assertMatchesRegularExpression('/<option\s+value="old-crm"\s+selected\s*>/', $response['body']);
    }

    /**
     * Неверные параметры адреса не применяются и не ломают страницу: несуществующая дата и не дата,
     * массив вместо строки, неизвестные поле сортировки и размер страницы.
     */
    public function testInvalidQueryParametersAreIgnored(): void
    {
        $job = Job::create('crm', '{}');
        $job->createdAt = '2026-03-01 12:00:00';
        $this->queue->push($job);

        $response = $this->get([
            // 2026-02-30 PHP превратил бы во 2 марта и отсёк бы задачу
            'created_from' => '2026-02-30',
            'created_to' => 'завтра',
            'status' => ['new'],
            'search_error' => ['x'],
            'ok' => ['x'],
            'sort_by' => 'payload',
            'limit' => '7',
        ]);

        $this->assertStringContainsString('Показано задач: 1 из 1.', $response['body']);
        $this->assertStringContainsString(
            '<a class="sort-link" href="?sort=asc">id<span class="sort-arrow">↓</span></a>',
            $response['body']
        );
        $this->assertStringNotContainsString('name="sort_by"', $response['body']);
    }

    /**
     * Листание: на странице limit задач, сводка и ссылки — над таблицей и под ней. Ссылки и адреса форм
     * сохраняют фильтр, limit и sort; адрес формы очистки — без page.
     */
    public function testPaginationKeepsFilterInLinksAboveAndBelowTable(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->queue->push(Job::create('crm', '{}'));
        }

        $this->queue->push(Job::create('shop', '{}'));

        $firstPage = $this->get(['source' => 'crm', 'limit' => '25', 'sort' => 'asc']);

        $this->assertSame(implode(',', range(1, 25)), implode(',', $this->shownIds($firstPage)));
        $this->assertSame(2, substr_count($firstPage['body'], 'Страница 1 из 2.'));
        $this->assertSame(2, substr_count($firstPage['body'], 'Показано задач: 25 из 30.'));
        $this->assertSame(2, substr_count($firstPage['body'], 'href="?source=crm&amp;limit=25&amp;sort=asc&amp;page=2"'));

        // Формы действий отправляются на адрес текущего списка — после действия вернёмся на него
        $this->assertStringContainsString(
            '<form class="jobs-form" method="post" action="?source=crm&amp;limit=25&amp;sort=asc&amp;page=1">',
            $firstPage['body']
        );
        $this->assertMatchesRegularExpression(
            '/<form\s+class="cleanup-form"\s+method="post"\s+action="\?source=crm&amp;limit=25&amp;sort=asc"/',
            $firstPage['body']
        );

        $secondPage = $this->get(['source' => 'crm', 'limit' => '25', 'sort' => 'asc', 'page' => '2']);

        $this->assertSame(implode(',', range(26, 30)), implode(',', $this->shownIds($secondPage)));
    }

    /**
     * Заголовки id, created_at, updated_at и closed_at — ссылки на сортировку. У текущего столбца
     * стрелка, а ссылка меняет направление; у остальных — сортировка сначала новые. Ссылки сохраняют
     * фильтр и ведут на первую страницу, сортировка сохраняется в формах.
     */
    public function testColumnHeadersSortList(): void
    {
        foreach (['2026-09-11 12:00:00', '2026-09-10 12:00:00', '2026-09-12 12:00:00'] as $createdAt) {
            $job = Job::create('crm', '{}');
            $job->createdAt = $createdAt;
            $this->queue->push($job);
        }

        $newestFirst = $this->get(['status' => 'new', 'sort_by' => 'created_at', 'page' => '1']);

        $this->assertSame(['3', '1', '2'], $this->shownIds($newestFirst));
        $this->assertStringContainsString(
            '<a class="sort-link" href="?status=new&amp;sort_by=created_at&amp;sort=asc">created_at<span class="sort-arrow">↓</span></a>',
            $newestFirst['body']
        );
        $this->assertStringContainsString('<a class="sort-link" href="?status=new">id</a>', $newestFirst['body']);
        $this->assertStringContainsString(
            '<a class="sort-link" href="?status=new&amp;sort_by=closed_at">closed_at</a>',
            $newestFirst['body']
        );

        // Форма фильтра и обе полосы листания
        $this->assertSame(3, substr_count($newestFirst['body'], 'name="sort_by" value="created_at"'));
        $this->assertStringContainsString(
            '<form class="jobs-form" method="post" action="?status=new&amp;sort_by=created_at&amp;page=1">',
            $newestFirst['body']
        );

        $oldestFirst = $this->get(['status' => 'new', 'sort_by' => 'created_at', 'sort' => 'asc']);

        $this->assertSame(['2', '1', '3'], $this->shownIds($oldestFirst));
        $this->assertStringContainsString(
            '<a class="sort-link" href="?status=new&amp;sort_by=created_at">created_at<span class="sort-arrow">↑</span></a>',
            $oldestFirst['body']
        );
    }

    /**
     * Массовая смена статуса: меняются только отмеченные задачи, редирект возвращает на тот же список
     * без старых сообщений, и страница после редиректа показывает новое сообщение.
     */
    public function testBulkStatusChangeUpdatesCheckedJobsAndReturnsToSameList(): void
    {
        $this->queue->push(Job::create('crm', '{}')->markFailed(null, 'таймаут'));
        $this->queue->push(Job::create('crm', '{}')->markFailed(null, 'таймаут'));
        $this->queue->push(Job::create('crm', '{}')->markFailed(null, 'таймаут'));

        // Фильтр списка приходит в адресе формы, отметки и действие — в теле POST
        $response = $this->post(
            ['bulk_action' => 'new', 'ids' => ['1', '3']],
            ['status' => 'failed', 'sort' => 'asc', 'err' => 'старая ошибка']
        );

        $this->assertSame(302, $response['status']);
        // Адрес относительный — браузер останется на той же странице админки
        $this->assertStringStartsWith('?', $response['headers']['Location']);

        $query = $this->redirectQuery($response);
        $this->assertSame('failed', $query['status']);
        $this->assertSame('asc', $query['sort']);
        $this->assertSame('Статус «new» проставлен задачам: 2', $query['ok']);
        $this->assertArrayNotHasKey('err', $query);

        $this->assertSame(Job::STATUS_NEW, $this->queue->findById(1)->status);
        $this->assertSame(Job::STATUS_FAILED, $this->queue->findById(2)->status);
        $this->assertSame(Job::STATUS_NEW, $this->queue->findById(3)->status);

        $page = $this->get($query);

        $this->assertStringContainsString('Статус «new» проставлен задачам: 2', $page['body']);
        $this->assertSame(['2'], $this->shownIds($page));
    }

    /**
     * Отметить задачи можно new, completed и failed, но не processing: такую задачу воркер не возьмёт.
     * Найти задачи в processing фильтр по статусу по-прежнему позволяет.
     */
    public function testBulkActionsDoNotOfferProcessing(): void
    {
        $response = $this->get();

        preg_match_all('/name="bulk_action"\s+value="(\w+)"/', $response['body'], $matches);
        $this->assertSame(['new', 'completed', 'failed', 'delete'], $matches[1]);
        $this->assertMatchesRegularExpression('/<option\s+value="processing"/', $response['body']);
    }

    /** Удаление отмеченных и очистка закрытых раньше срока: в редиректе — сколько удалено */
    public function testDeleteAndCleanupActions(): void
    {
        $this->queue->push(Job::create('crm', '{}'));

        $oldCompleted = Job::create('crm', '{}')->markCompleted();
        $oldCompleted->closedAt = gmdate(Job::DATE_FORMAT, time() - 40 * 86400);
        $this->queue->push($oldCompleted);

        $this->queue->push(Job::create('crm', '{}'));

        $deleted = $this->post(['bulk_action' => 'delete', 'ids' => ['1']]);
        $this->assertSame('Удалено задач: 1', $this->redirectQuery($deleted)['ok']);

        $cleaned = $this->post(['cleanup_days' => '30']);
        $this->assertSame('Удалено задач, закрытых больше 30 дн. назад: 1', $this->redirectQuery($cleaned)['ok']);

        $this->assertSame([3], array_column($this->queue->find(), 'id'));
    }

    /**
     * Действие с чужим токеном, без CSRF-cookie или с ошибкой (неизвестный статус) не выполняется:
     * ошибка не выбрасывается, а уходит в адрес редиректа.
     */
    public function testFailedActionReportsErrorAndChangesNothing(): void
    {
        $this->queue->push(Job::create('crm', '{}'));

        $staleForm = 'Форма устарела — обновите страницу и повторите действие.';

        foreach ([
            [['csrf_token' => str_repeat('f', 64), 'bulk_action' => 'delete', 'ids' => ['1']], self::COOKIES, $staleForm],
            [['bulk_action' => 'delete', 'ids' => ['1']], [], $staleForm],
            [['bulk_action' => 'done', 'ids' => ['1']], self::COOKIES, 'Неизвестный статус: done'],
        ] as [$post, $cookies, $error]) {
            $this->assertSame($error, $this->redirectQuery($this->post($post, [], $cookies))['err']);
        }

        $this->assertSame(Job::STATUS_NEW, $this->queue->findById(1)->status);
    }

    /** Ошибка базы при загрузке страницы не перехватывается — выбрасывается PDOException */
    public function testDatabaseErrorWhileLoadingPageIsThrown(): void
    {
        $other = new \PDO('sqlite:' . $this->dbDir . '/jobs.sqlite');
        $other->exec('DROP TABLE jobs');
        $other = null;

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('no such table: jobs');

        $this->get();
    }

    /**
     * Поля задачи и сообщения выводятся экранированными. JSON в payload раскрыт вместе с JSON,
     * вложенным строкой; \n в значениях — перенос, а обратный слеш перед n остаётся слешем.
     * Payload не JSON — как есть.
     */
    public function testJobFieldsAreShownSafelyAndReadably(): void
    {
        $this->queue->push(Job::create(
            '<b>crm</b>',
            (string) json_encode([
                'html' => '<script>alert(1)</script>',
                'comment' => "Позвонить\nперед доставкой",
                'path' => 'C:\new',
                'items' => json_encode([['sku' => 'A-1']]),
            ], JSON_UNESCAPED_UNICODE),
            '<i>заметка</i>'
        ));
        $this->queue->push(Job::create('crm', 'текст с \n как есть'));

        $body = $this->get(['ok' => '<b>готово</b>'])['body'];

        foreach (['<b>crm</b>', '<i>заметка</i>', '<script>alert(1)</script>', '<b>готово</b>'] as $html) {
            $this->assertStringContainsString(htmlspecialchars($html), $body);
            $this->assertStringNotContainsString($html, $body);
        }

        $this->assertStringContainsString("Позвонить\nперед доставкой", $body);
        $this->assertStringContainsString('&quot;C:\\\\new&quot;', $body);
        $this->assertStringContainsString('&quot;sku&quot;: &quot;A-1&quot;', $body);
        $this->assertStringContainsString('текст с \n как есть', $body);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function get(array $query = []): array
    {
        return $this->dashboard->process($query, [], ['REQUEST_METHOD' => 'GET'], self::COOKIES);
    }

    /**
     * Отправка формы; токен по умолчанию — верный.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @param array<string, string> $cookies
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function post(array $post, array $query = [], array $cookies = self::COOKIES): array
    {
        return $this->dashboard->process(
            $query,
            $post + ['csrf_token' => self::CSRF_TOKEN],
            ['REQUEST_METHOD' => 'POST'],
            $cookies
        );
    }

    /**
     * id задач в таблице, в порядке показа.
     *
     * @param array{body: string} $response
     * @return string[]
     */
    private function shownIds(array $response): array
    {
        preg_match_all('/name="ids\[\]"\s+value="(\d+)"/', $response['body'], $matches);

        return $matches[1];
    }

    /**
     * Параметры адреса, на который ведёт редирект после действия.
     *
     * @param array{headers: array<string, string>} $response
     * @return array<string, mixed>
     */
    private function redirectQuery(array $response): array
    {
        parse_str((string) parse_url($response['headers']['Location'], PHP_URL_QUERY), $query);

        return $query;
    }
}
