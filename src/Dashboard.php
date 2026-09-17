<?php

declare(strict_types=1);

namespace Integrat\Queue;

/**
 * Простой встроенный дашборд очереди.
 *
 * Здесь обработка запроса: фильтры и действия над задачами. Разметка страницы —
 * в resources/Dashboard.phtml, стили — в resources/Dashboard.css.
 * Данные загружаются через Queue.
 */
final class Dashboard
{
    private const LIMITS = [25, 50, 100, 200, 500, 1000, 2000];
    private const DEFAULT_LIMIT = 50;
    private const CLEANUP_DAYS = [30, 60, 90, 180, 365];

    /**
     * Статусы, которые можно проставить отмеченным задачам. Без processing: воркер берёт только new,
     * и отмеченная вручную задача выглядела бы выполняемой, но не выполнилась бы никогда
     */
    private const MARK_STATUSES = [Job::STATUS_NEW, Job::STATUS_COMPLETED, Job::STATUS_FAILED];

    /** Поиск текста: поле задачи => условие фильтра очереди; в адресе и форме — search_<поле> */
    private const TEXT_FIELDS = [
        'info' => 'infoContains',
        'result' => 'resultContains',
        'error' => 'errorContains',
        'payload' => 'payloadContains',
    ];

    /** Сортировка: значение sort_by в адресе => поле сортировки очереди; без sort_by — id */
    private const SORT_FIELDS = [
        'id' => 'id',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
        'closed_at' => 'closedAt',
    ];

    private Queue $queue;
    private \DateTimeZone $timezone;

    /**
     * @param string $timezone Часовой пояс, в котором админка показывает время и
     *                         понимает даты фильтра, например 'Europe/Moscow'.
     *                         В базе время всегда в UTC.
     * @throws \Exception если часовой пояс неизвестен — сразу, а не при показе страницы
     */
    public function __construct(Queue $queue, string $timezone = 'UTC')
    {
        $this->queue = $queue;
        $this->timezone = new \DateTimeZone($timezone);
    }

    /**
     * Обработать текущий запрос и сразу отправить ответ в браузер.
     *
     * Для отдельного PHP-файла. Во фреймворке, где контроллер должен вернуть
     * объект ответа, используйте process().
     *
     * @throws \PDOException если база недоступна при загрузке страницы
     */
    public function handle(): void
    {
        $response = $this->process($_GET, $_POST, $_SERVER, $_COOKIE);

        http_response_code($response['status']);

        foreach ($response['headers'] as $name => $value) {
            // Set-Cookie добавляем, а не заменяем: приложение могло уже поставить свои cookie
            header($name . ': ' . $value, $name !== 'Set-Cookie');
        }

        echo $response['body'];
    }

    /**
     * Обработать запрос и вернуть ответ, ничего не отправляя и не завершая процесс.
     *
     * Ошибка действия (массовая смена статуса, удаление, очистка), в том числе ошибка
     * базы, не выбрасывается, а показывается на странице после редиректа.
     *
     * @param array<string, mixed> $query   Параметры строки запроса, как $_GET
     * @param array<string, mixed> $post    Поля POST-формы, как $_POST
     * @param array<string, mixed> $server  Как $_SERVER; нужны REQUEST_METHOD и HTTPS
     * @param array<string, mixed> $cookies Cookie запроса, как $_COOKIE
     * @return array{status: int, headers: array<string, string>, body: string}
     * @throws \PDOException если база недоступна при загрузке страницы
     */
    public function process(array $query, array $post, array $server, array $cookies): array
    {
        // Защита от CSRF по схеме double-submit cookie: одно и то же случайное значение
        // лежит в cookie и в скрытом поле POST-форм. Чужой сайт не может прочитать cookie,
        // чтобы подставить значение в свою форму, а из-за SameSite браузер и не отправит её
        // с межсайтовым POST. Сессии не нужны — дашборд не зависит от того, как устроено
        // приложение, в которое он встроен. Префикс __Host- (только по HTTPS) не даёт
        // поддоменам подменить cookie
        $https = !in_array(strtolower((string) ($server['HTTPS'] ?? '')), ['', 'off'], true);
        $csrfCookie = $https ? '__Host-queue_dashboard_csrf' : 'queue_dashboard_csrf';
        $csrfToken = $this->stringParam($cookies, $csrfCookie);

        if (($server['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            return $this->redirectAfterAction($query, $post, $csrfToken);
        }

        $headers = ['Content-Type' => 'text/html; charset=utf-8'];

        if ($csrfToken === '') {
            $csrfToken = bin2hex(random_bytes(32));
            $headers['Set-Cookie'] = "{$csrfCookie}={$csrfToken}; Path=/; HttpOnly; SameSite=Lax" . ($https ? '; Secure' : '');
        }

        return ['status' => 200, 'headers' => $headers, 'body' => $this->renderPage($query, $csrfToken)];
    }

    /**
     * Выполнить действие формы и вернуть редирект на тот же список с сообщением
     * о результате или об ошибке.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function redirectAfterAction(array $query, array $post, string $csrfToken): array
    {
        // Формы отправляются на адрес текущего списка — туда и возвращаемся.
        // Параметры списка проверит обработка GET
        $back = $query;
        unset($back['ok'], $back['err']);

        try {
            $back['ok'] = $this->performAction($post, $csrfToken);
        } catch (\Throwable $exception) {
            $back['err'] = $exception->getMessage();
        }

        // Относительный адрес: браузер останется на той же странице, где бы она ни была
        return [
            'status' => 302,
            'headers' => ['Location' => '?' . http_build_query($this->withoutEmpty($back))],
            'body' => '',
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return string Сообщение о результате; пустое, если действия в форме нет
     * @throws \InvalidArgumentException если токен формы не совпал с cookie
     */
    private function performAction(array $post, string $csrfToken): string
    {
        if ($csrfToken === '' || !hash_equals($csrfToken, $this->stringParam($post, 'csrf_token'))) {
            throw new \InvalidArgumentException('Форма устарела — обновите страницу и повторите действие.');
        }

        // Значения приходят из форм страницы, и подделать их может только тот,
        // кто прошёл проверку токена, то есть сам админ. Поэтому они не перепроверяются
        if (isset($post['bulk_action'])) {
            $action = $this->stringParam($post, 'bulk_action');
            $ids = (array) ($post['ids'] ?? []);

            if ($action === 'delete') {
                return 'Удалено задач: ' . $this->queue->delete($ids);
            }

            // Кнопки смены статуса отправляют сам статус; неизвестный отклонит очередь
            return "Статус «{$action}» проставлен задачам: " . $this->queue->mark($ids, $action);
        }

        if (isset($post['cleanup_days'])) {
            $days = (int) $post['cleanup_days'];

            return "Удалено задач, закрытых больше {$days} дн. назад: " . $this->queue->deleteOldRecords($days);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $query
     * @throws \PDOException если база недоступна
     */
    private function renderPage(array $query, string $csrfToken): string
    {
        $ok = $this->stringParam($query, 'ok');
        $error = $this->stringParam($query, 'err');

        $perPage = (int) $this->stringParam($query, 'limit');

        if (!in_array($perPage, self::LIMITS, true)) {
            $perPage = self::DEFAULT_LIMIT;
        }

        $page = max(1, (int) $this->stringParam($query, 'page'));
        $status = $this->stringParam($query, 'status');
        $source = $this->stringParam($query, 'source');
        $createdFrom = $this->normalizeDate($this->stringParam($query, 'created_from'));
        $createdTo = $this->normalizeDate($this->stringParam($query, 'created_to'));
        // Текст для поиска в полях задачи: параметр search_<поле> => текст
        $searches = [];

        foreach (array_keys(self::TEXT_FIELDS) as $field) {
            $searches["search_{$field}"] = trim($this->stringParam($query, "search_{$field}"));
        }

        // В админке удобнее видеть сначала свежие; старые первыми — только по sort=asc
        $newestFirst = $this->stringParam($query, 'sort') !== 'asc';
        $sortBy = $this->stringParam($query, 'sort_by');

        if (!isset(self::SORT_FIELDS[$sortBy])) {
            $sortBy = 'id';
        }

        // Источника из старой ссылки может уже не быть в базе. Он остаётся в фильтре
        // и добавляется в список, чтобы форма показывала то, по чему отобраны задачи
        $availableSources = $this->queue->listSources();

        if ($source !== '' && !in_array($source, $availableSources, true)) {
            $availableSources[] = $source;
        }

        // Задача должна подходить под все заданные условия. В форме выбирают дни в поясе
        // админки, а в базе лежит время в UTC: границы разворачиваем в сутки целиком
        // и переводим в UTC. Любую из дат можно не задавать
        $filter = [
            'status' => $status,
            'source' => $source,
            'createdFrom' => $createdFrom === '' ? '' : $this->toUtc($createdFrom . ' 00:00:00'),
            'createdTo' => $createdTo === '' ? '' : $this->toUtc($createdTo . ' 23:59:59'),
        ];

        foreach (self::TEXT_FIELDS as $field => $condition) {
            $filter[$condition] = $searches["search_{$field}"];
        }

        $jobs = $this->queue->find($filter, $page, $perPage, $newestFirst, self::SORT_FIELDS[$sortBy]);
        $totalRows = $this->queue->count($filter);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));

        // Текущее состояние списка — для ссылок пагинации и адресов форм.
        // Значения по умолчанию пустые: в URL и форму они не попадают
        $state = [
            'status' => $status,
            'source' => $source,
            'created_from' => $createdFrom,
            'created_to' => $createdTo,
        ] + $searches + [
            'limit' => $perPage === self::DEFAULT_LIMIT ? '' : $perPage,
            'sort_by' => $sortBy === 'id' ? '' : $sortBy,
            'sort' => $newestFirst ? '' : 'asc',
            'page' => $page,
        ];

        // «Сбросить» нужна, если заданы фильтры или сортировка; страница и её размер — не в счёт
        $canReset = $this->withoutEmpty(array_diff_key($state, ['limit' => true, 'page' => true])) !== [];

        $listUrl = function (array $overrides = []) use ($state): string {
            return '?' . http_build_query($this->withoutEmpty(array_merge($state, $overrides)));
        };

        // Заголовок столбца — ссылка на сортировку по нему: первый клик — сначала новые,
        // повторный — сначала старые. Новая сортировка начинается с первой страницы
        $sortLink = function (string $field) use ($listUrl, $sortBy, $newestFirst): string {
            $current = $field === $sortBy;
            $url = $listUrl([
                'sort_by' => $field === 'id' ? '' : $field,
                'sort' => $current && $newestFirst ? 'asc' : '',
                'page' => '',
            ]);
            $arrow = $current ? '<span class="sort-arrow">' . ($newestFirst ? '↓' : '↑') . '</span>' : '';

            return '<a class="sort-link" href="' . $this->escape($url) . '">' . $field . $arrow . '</a>';
        };

        // Шаблон видит $this и все переменные этого метода
        ob_start();
        require dirname(__DIR__) . '/resources/Dashboard.phtml';

        return (string) ob_get_clean();
    }

    /**
     * Параметр запроса строкой. Массив (name[]=...) — всё равно что параметра нет.
     *
     * @param array<string, mixed> $params
     */
    private function stringParam(array $params, string $name): string
    {
        $value = $params[$name] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param mixed $value
     */
    private function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $params): array
    {
        foreach ($params as $key => $value) {
            if ($value === '') {
                unset($params[$key]);
            }
        }

        return $params;
    }

    /**
     * Скрытые поля формы; пустые значения пропускаются.
     *
     * @param array<string, mixed> $fields
     */
    private function hiddenFields(array $fields): string
    {
        $html = '';

        foreach ($this->withoutEmpty($fields) as $name => $value) {
            $html .= '<input type="hidden" name="' . $this->escape($name)
                . '" value="' . $this->escape($value) . '">' . "\n";
        }

        return $html;
    }

    /**
     * Время из базы (UTC) — во время пояса админки. Пустое время — прочерк,
     * строка в неожиданном формате — как есть.
     */
    private function toDisplayTime(?string $utc): string
    {
        if ((string) $utc === '') {
            return '—';
        }

        $date = \DateTimeImmutable::createFromFormat(Job::DATE_FORMAT, $utc, new \DateTimeZone('UTC'));

        return $date === false ? $utc : $date->setTimezone($this->timezone)->format(Job::DATE_FORMAT);
    }

    /**
     * Время в поясе админки — во время UTC для сравнения с базой.
     */
    private function toUtc(string $localTime): string
    {
        return (new \DateTimeImmutable($localTime, $this->timezone))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(Job::DATE_FORMAT);
    }

    private function normalizeDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        // Сверка с исходной строкой отсекает несуществующие даты: 2026-02-30 PHP превратил бы в 2 марта
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : '';
    }

    /**
     * Payload для показа. JSON — с отступами, с раскрытым JSON, вложенным строкой,
     * и с настоящими переносами вместо \n в значениях. Не JSON — как есть.
     */
    private function formatPayload(string $payload): string
    {
        $decoded = $this->decodeNestedJson($payload);

        if (!is_array($decoded)) {
            return $payload;
        }

        $json = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Экранированные последовательности читаются целиком, слева направо: так \\n
        // (обратный слеш и буква n) не превращается в перенос
        return (string) preg_replace_callback(
            '/\\\\(?:r\\\\n|.)/',
            fn (array $escape): string => in_array($escape[0], ['\\r\\n', '\\n'], true) ? "\n" : $escape[0],
            $json
        );
    }

    /**
     * Раскрыть JSON в массив — рекурсивно, вместе с JSON, вложенным строкой.
     * Всё, что не JSON-объект или массив, возвращается как есть.
     *
     * @param mixed $value
     * @return mixed
     */
    private function decodeNestedJson($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->decodeNestedJson($item);
            }

            return $value;
        }

        // Строку '42' или 'true' json_decode тоже разберёт, но в скаляр — такие остаются строками
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $this->decodeNestedJson($decoded) : $value;
    }
}
