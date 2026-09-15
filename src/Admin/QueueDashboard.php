<?php

declare(strict_types=1);

namespace Integrat\Queue\Admin;

use Integrat\Queue\Job;

/**
 * Простой встроенный дашборд очереди.
 *
 * Здесь находятся только обработка фильтров, действия над задачами
 * и HTML-разметка страницы. Данные загружаются через QueueAdmin.
 */
final class QueueDashboard
{
    private QueueAdmin $admin;
    private ?string $cssUrl;
    private \DateTimeZone $timezone;

    /**
     * @param string|null $cssUrl   URL стилей админки. По умолчанию (null) стили
     *                              встраиваются в страницу из файла пакета — это работает
     *                              независимо от того, доступен ли vendor/ из веба.
     *                              Укажите URL, если хотите отдавать CSS отдельным файлом
     *                              (кешируется браузером).
     * @param string      $timezone Часовой пояс, в котором админка показывает время и
     *                              понимает даты фильтра, например 'Europe/Moscow'.
     *                              В базе время всегда в UTC.
     */
    public function __construct(QueueAdmin $admin, ?string $cssUrl = null, string $timezone = 'UTC')
    {
        $this->admin = $admin;
        $this->cssUrl = $cssUrl;
        // Неизвестный пояс — ошибка настройки: пусть падает сразу, а не показывает неверное время
        $this->timezone = new \DateTimeZone($timezone);
    }

    /**
     * Обработать текущий запрос и сразу отправить ответ в браузер.
     *
     * Для отдельного PHP-файла. Во фреймворке, где контроллер должен вернуть
     * объект ответа, используйте process().
     */
    public function handle(): void
    {
        $response = $this->process($_GET, $_POST, $_SERVER, $_COOKIE);

        http_response_code($response['status']);

        foreach ($response['headers'] as $name => $value) {
            // Set-Cookie добавляем, а не заменяем: приложение могло уже поставить свои cookie
            header($name . ': ' . $value, strcasecmp($name, 'Set-Cookie') !== 0);
        }

        echo $response['body'];
    }

    /**
     * Обработать запрос и вернуть ответ, ничего не отправляя и не завершая процесс.
     *
     * @param array<string, mixed> $query   Параметры строки запроса, как $_GET
     * @param array<string, mixed> $post    Поля POST-формы, как $_POST
     * @param array<string, mixed> $server  Как $_SERVER; нужны REQUEST_METHOD, REQUEST_URI и HTTPS
     * @param array<string, mixed> $cookies Cookie запроса, как $_COOKIE
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function process(array $query, array $post, array $server, array $cookies): array
    {
        $allowedLimits = [25, 50, 100, 200, 500, 1000, 2000];
        $defaultLimit = 50;

        // Ключ — значение параметра sort, значение — подпись
        $allowedSorts = [
            'desc' => 'Сначала новые',
            'asc' => 'Сначала старые',
        ];

        // В админке удобнее видеть сначала свежие
        $defaultSort = 'desc';

        $allowedStatuses = [
            Job::STATUS_NEW,
            Job::STATUS_PROCESSING,
            Job::STATUS_COMPLETED,
            Job::STATUS_FAILED,
        ];

        $availableSources = $this->admin->getSources();

        $requestUri = (string) ($server['REQUEST_URI'] ?? '');
        $basePath = strtok($requestUri, '?');

        if ($basePath === false) {
            $basePath = '';
        }

        $requestMethod = (string) ($server['REQUEST_METHOD'] ?? 'GET');

        // Защита от CSRF — см. csrfCookieHeader(). Префикс __Host- (только по HTTPS)
        // не даёт поддоменам подменить cookie
        $https = $this->isHttps($server);
        $csrfCookieName = $https ? '__Host-queue_dashboard_csrf' : 'queue_dashboard_csrf';
        $csrfToken = $this->readCsrfToken($cookies[$csrfCookieName] ?? '');

        // Ключ — значение кнопки массового действия, значение — её подпись
        $bulkActions = [
            'status:' . Job::STATUS_NEW => 'Пометить new',
            'status:' . Job::STATUS_PROCESSING => 'Пометить processing',
            'status:' . Job::STATUS_COMPLETED => 'Пометить completed',
            'status:' . Job::STATUS_FAILED => 'Пометить failed',
            'delete' => 'Удалить',
        ];

        // Сроки хранения, доступные в форме очистки, в днях
        $allowedCleanupDays = [30, 60, 90, 180, 365];

        if ($requestMethod === 'POST') {
            // Состояние списка передаём в редирект как есть — его проверит обработка GET ниже
            $back = [];

            foreach (['search', 'status', 'source', 'created_from', 'created_to', 'limit', 'sort', 'page'] as $key) {
                $back[$key] = trim((string) ($post[$key] ?? ''));
            }

            try {
                $submittedToken = $post['csrf_token'] ?? '';

                if ($csrfToken === '' || !is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
                    throw new \InvalidArgumentException('Форма устарела — обновите страницу и повторите действие.');
                }

                if (isset($post['bulk_action'])) {
                    $bulkAction = (string) $post['bulk_action'];
                    $ids = $post['ids'] ?? [];

                    if (!is_array($ids) || $ids === []) {
                        throw new \InvalidArgumentException('Не отмечено ни одной задачи.');
                    }

                    if (!isset($bulkActions[$bulkAction])) {
                        throw new \InvalidArgumentException('Неизвестное массовое действие.');
                    }

                    if ($bulkAction === 'delete') {
                        $count = $this->admin->deleteMany($ids);
                        $back['ok'] = "Удалено задач: {$count}";
                    } else {
                        $newStatus = substr($bulkAction, strlen('status:'));
                        $count = $this->admin->setStatusMany($ids, $newStatus);
                        $back['ok'] = "Статус «{$newStatus}» проставлен задачам: {$count}";
                    }
                } elseif (isset($post['cleanup_days'])) {
                    $days = (int) $post['cleanup_days'];

                    if (!in_array($days, $allowedCleanupDays, true)) {
                        throw new \InvalidArgumentException('Недопустимый срок хранения.');
                    }

                    $count = $this->admin->deleteOldRecords($days);
                    $back['ok'] = "Удалено задач, закрытых больше {$days} дн. назад: {$count}";
                } else {
                    throw new \InvalidArgumentException('Неизвестное действие.');
                }
            } catch (\Throwable $exception) {
                $back['err'] = $exception->getMessage();
            }

            return [
                'status' => 302,
                'headers' => ['Location' => $basePath . '?' . http_build_query($this->withoutEmpty($back))],
                'body' => '',
            ];
        }

        $headers = ['Content-Type' => 'text/html; charset=utf-8'];

        if ($csrfToken === '') {
            $csrfToken = bin2hex(random_bytes(32));
            $headers['Set-Cookie'] = $this->csrfCookieHeader($csrfCookieName, $csrfToken, $https);
        }

        $perPage = (int) ($query['limit'] ?? $defaultLimit);

        if (!in_array($perPage, $allowedLimits, true)) {
            $perPage = $defaultLimit;
        }

        $page = (int) ($query['page'] ?? 1);
        $status = (string) ($query['status'] ?? '');
        $source = trim((string) ($query['source'] ?? ''));
        $search = trim((string) ($query['search'] ?? ''));
        $createdFrom = $this->normalizeDate($query['created_from'] ?? '');
        $createdTo = $this->normalizeDate($query['created_to'] ?? '');
        $sort = strtolower(trim((string) ($query['sort'] ?? '')));

        if (!isset($allowedSorts[$sort])) {
            $sort = $defaultSort;
        }

        if (!in_array($status, $allowedStatuses, true)) {
            $status = '';
        }

        if (!in_array($source, $availableSources, true)) {
            $source = '';
        }

        // В форме выбирают день в поясе админки, а в базе лежит время в UTC:
        // разворачиваем в сутки целиком и переводим границы в UTC.
        // Невыбранные фильтры не передаём: пустое значение ищется как есть
        $filters = $this->withoutEmpty([
            'status' => $status,
            'source' => $source,
            'search' => $search,
            'created_from' => $createdFrom === '' ? '' : $this->toUtc($createdFrom . ' 00:00:00'),
            'created_to' => $createdTo === '' ? '' : $this->toUtc($createdTo . ' 23:59:59'),
            'sort' => strtoupper($sort),
        ]);

        $rows = $this->admin->findFiltered($filters, $page, $perPage);
        $totalRows = $this->admin->countFiltered($filters);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));

        $statusColors = [
            Job::STATUS_NEW => '#a16207',
            Job::STATUS_PROCESSING => '#1d4ed8',
            Job::STATUS_COMPLETED => '#15803d',
            Job::STATUS_FAILED => '#b91c1c',
        ];

        $hasActiveFilters = $search !== ''
            || $status !== ''
            || $source !== ''
            || $createdFrom !== ''
            || $createdTo !== '';

        // Текущее состояние списка — для ссылок пагинации и скрытых полей форм.
        // Значения по умолчанию пустые: в URL и форму они не попадают
        $state = [
            'search' => $search,
            'status' => $status,
            'source' => $source,
            'created_from' => $createdFrom,
            'created_to' => $createdTo,
            'limit' => $perPage === $defaultLimit ? '' : $perPage,
            'sort' => $sort === $defaultSort ? '' : $sort,
            'page' => $page,
        ];

        $listUrl = function (array $overrides = []) use ($state): string {
            return '?' . http_build_query($this->withoutEmpty(array_merge($state, $overrides)));
        };

        // Страница собирается в буфер: отправлять её — дело handle() или фреймворка
        $bufferLevel = ob_get_level();
        ob_start();

        try {
            ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Очередь задач</title>
    <?php if ($this->cssUrl !== null): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($this->cssUrl, ENT_QUOTES, 'UTF-8') ?>">
    <?php else: ?>
        <style><?= $this->readCss() ?></style>
    <?php endif; ?>

    <noscript>
        <!-- Без JS панель массовых действий показать некому — показываем её всегда -->
        <style>
            .bulk-bar {
                display: flex;
            }
        </style>
    </noscript>
</head>
<body>
<header>
    <h1>Очередь задач</h1>
</header>

<main>
    <?php if (!empty($query['ok'])): ?>
        <div class="flash ok">
            <?= $this->escape($query['ok']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($query['err'])): ?>
        <div class="flash error">
            Ошибка: <?= $this->escape($query['err']) ?>
        </div>
    <?php endif; ?>

    <form class="panel" method="get">
        <div class="filter-grid">
            <div class="field">
                <label for="search">Поиск</label>
                <input
                    id="search"
                    type="text"
                    name="search"
                    value="<?= $this->escape($search) ?>"
                    placeholder="ID, источник, payload, info, result или error"
                >
            </div>

            <div class="field">
                <label for="status">Статус</label>
                <select id="status" name="status">
                    <option value="">Все статусы</option>
                    <?php foreach ($allowedStatuses as $allowedStatus): ?>
                        <option
                            value="<?= $this->escape($allowedStatus) ?>"
                            <?= $status === $allowedStatus ? 'selected' : '' ?>
                        >
                            <?= $this->escape($allowedStatus) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="source">Источник</label>
                <select id="source" name="source">
                    <option value="">Все источники</option>
                    <?php foreach ($availableSources as $availableSource): ?>
                        <option
                            value="<?= $this->escape($availableSource) ?>"
                            <?= $source === $availableSource ? 'selected' : '' ?>
                        >
                            <?= $this->escape($availableSource) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="created_from">Дата создания (<?= $this->escape($this->timezone->getName()) ?>), от</label>
                <input
                    id="created_from"
                    type="date"
                    name="created_from"
                    value="<?= $this->escape($createdFrom) ?>"
                    max="<?= $this->escape($createdTo) ?>"
                >
            </div>

            <div class="field">
                <label for="created_to">Дата создания (<?= $this->escape($this->timezone->getName()) ?>), до</label>
                <input
                    id="created_to"
                    type="date"
                    name="created_to"
                    value="<?= $this->escape($createdTo) ?>"
                    min="<?= $this->escape($createdFrom) ?>"
                >
            </div>
        </div>

        <div class="filter-actions">
            <button type="submit">Применить фильтры</button>

            <?php if ($hasActiveFilters || $sort !== $defaultSort): ?>
                <a class="button-link secondary" href="<?= $this->escape($basePath) ?>">
                    Сбросить
                </a>
            <?php endif; ?>

            <div class="filter-pager">
                <?php if ($page > 1): ?>
                    <a class="button-link secondary" href="<?= $this->escape($listUrl(['page' => $page - 1])) ?>">
                        ← Назад
                    </a>
                <?php else: ?>
                    <span class="button-link secondary disabled">← Назад</span>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="button-link secondary" href="<?= $this->escape($listUrl(['page' => $page + 1])) ?>">
                        Вперёд →
                    </a>
                <?php else: ?>
                    <span class="button-link secondary disabled">Вперёд →</span>
                <?php endif; ?>

                <label for="sort-top">Порядок:</label>
                <select id="sort-top" name="sort" onchange="this.form.submit()">
                    <?php foreach ($allowedSorts as $sortValue => $sortLabel): ?>
                        <option
                            value="<?= $this->escape($sortValue) ?>"
                            <?= $sort === $sortValue ? 'selected' : '' ?>
                        >
                            <?= $this->escape($sortLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="limit-top">На странице:</label>
                <select id="limit-top" name="limit" onchange="this.form.submit()">
                    <?php foreach ($allowedLimits as $allowedLimit): ?>
                        <option
                            value="<?= $this->escape($allowedLimit) ?>"
                            <?= $perPage === $allowedLimit ? 'selected' : '' ?>
                        >
                            <?= $this->escape($allowedLimit) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <!-- Одна форма на всю таблицу: отметки строк и массовые действия над ними -->
    <form class="jobs-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= $this->escape($csrfToken) ?>">
        <?= $this->hiddenFields($state) ?>

        <!-- Панель массовых действий: скрыта, пока не отмечена ни одна задача (показывает JS) -->
        <div class="bulk-bar" data-bulk-bar>
            <span class="bulk-title">С отмеченными:</span>

            <?php foreach ($bulkActions as $bulkValue => $bulkLabel): ?>
                <button
                    type="submit"
                    name="bulk_action"
                    value="<?= $this->escape($bulkValue) ?>"
                    <?= $bulkValue === 'delete' ? 'class="danger"' : '' ?>
                    data-bulk-apply
                >
                    <?= $this->escape($bulkLabel) ?>
                </button>
            <?php endforeach; ?>

            <span class="selected-count" data-selected-count>Отмечено: 0</span>
        </div>

        <div class="actions-row">
            <p class="summary">
                Страница <?= $this->escape($page) ?> из <?= $this->escape($totalPages) ?>.
                Показано задач: <?= count($rows) ?> из <?= $this->escape($totalRows) ?>.

                <?php if ($createdFrom !== ''): ?>
                    Созданы с <?= $this->escape($createdFrom) ?>.
                <?php endif; ?>

                <?php if ($createdTo !== ''): ?>
                    Созданы по <?= $this->escape($createdTo) ?> включительно.
                <?php endif; ?>
            </p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="select">
                            <input
                                type="checkbox"
                                data-select-all
                                title="Отметить все на странице"
                                <?= $rows === [] ? 'disabled' : '' ?>
                            >
                        </th>
                        <th>id</th>
                        <th>source</th>
                        <th>status</th>
                        <th>info</th>
                        <th>result</th>
                        <th>error</th>
                        <th>created_at</th>
                        <th>updated_at</th>
                        <th>closed_at</th>
                        <th>payload</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="11" class="empty">
                                <?= $hasActiveFilters ? 'Ничего не найдено' : 'Задач нет' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $payloadText = $this->makeJsonReadable((string) $row['payload']);
                            $infoText = (string) ($row['info'] ?? '');
                            $resultText = (string) ($row['result'] ?? '');
                            $errorText = (string) ($row['error'] ?? '');
                            ?>
                            <tr>
                                <td class="select">
                                    <input
                                        type="checkbox"
                                        name="ids[]"
                                        value="<?= $this->escape($row['id']) ?>"
                                        data-row-select
                                        aria-label="Отметить задачу #<?= $this->escape($row['id']) ?>"
                                    >
                                </td>

                                <td class="id">
                                    <?= $this->highlight($row['id'], $search) ?>
                                </td>

                                <td class="source">
                                    <?= $this->highlight($row['source'], $search) ?>
                                </td>

                                <td>
                                    <span
                                        class="badge"
                                        style="background: <?= $this->escape($statusColors[$row['status']] ?? '#6b7280') ?>"
                                    >
                                        <?= $this->highlight($row['status'], $search) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($infoText !== ''): ?>
                                        <pre class="info-text"><?= $this->highlight($infoText, $search) ?></pre>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($resultText !== ''): ?>
                                        <pre class="result-text"><?= $this->highlight($resultText, $search) ?></pre>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($errorText !== ''): ?>
                                        <pre class="error-text"><?= $this->highlight($errorText, $search) ?></pre>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <td class="date">
                                    <?= $this->escape($this->toDisplayTime((string) $row['created_at'])) ?>
                                </td>

                                <td class="date">
                                    <?= $this->escape($this->toDisplayTime((string) $row['updated_at'])) ?>
                                </td>

                                <td class="date">
                                    <?= ($row['closed_at'] ?? '') === '' ? '—' : $this->escape($this->toDisplayTime((string) $row['closed_at'])) ?>
                                </td>

                                <td class="payload-cell">
                                    <details class="payload" <?= $search !== '' ? 'open' : '' ?>>
                                        <summary>Показать данные</summary>
                                        <pre><?= $this->expandEscapedNewLines($this->highlight($payloadText, $search)) ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <div class="pager">
        <?php if ($page > 1): ?>
            <a href="<?= $this->escape($listUrl(['page' => $page - 1])) ?>">
                ← Назад
            </a>
        <?php else: ?>
            <span class="disabled">← Назад</span>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a href="<?= $this->escape($listUrl(['page' => $page + 1])) ?>">
                Вперёд →
            </a>
        <?php else: ?>
            <span class="disabled">Вперёд →</span>
        <?php endif; ?>

        <!-- Без page: при смене размера страницы возвращаемся на первую -->
        <form class="page-size-form" method="get">
            <?= $this->hiddenFields(array_diff_key($state, ['limit' => true, 'page' => true])) ?>

            <label for="limit">На странице:</label>
            <select id="limit" name="limit" onchange="this.form.submit()">
                <?php foreach ($allowedLimits as $allowedLimit): ?>
                    <option
                        value="<?= $this->escape($allowedLimit) ?>"
                        <?= $perPage === $allowedLimit ? 'selected' : '' ?>
                    >
                        <?= $this->escape($allowedLimit) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <noscript>
                <button type="submit">Применить</button>
            </noscript>
        </form>
    </div>

    <!-- Обслуживание: отдельной формой, потому что вложенные формы HTML запрещает -->
    <form class="cleanup-form" method="post" data-cleanup-form>
        <input type="hidden" name="csrf_token" value="<?= $this->escape($csrfToken) ?>">
        <?= $this->hiddenFields(array_diff_key($state, ['page' => true])) ?>

        <label for="cleanup_days">Удалить задачи, закрытые больше</label>
        <select id="cleanup_days" name="cleanup_days">
            <?php foreach ($allowedCleanupDays as $allowedDays): ?>
                <option value="<?= $this->escape($allowedDays) ?>">
                    <?= $this->escape($allowedDays) ?> дн.
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="danger">Очистить</button>

        <span class="cleanup-note">
            Задачи в статусах new и processing не трогаются — зависшая задача переживёт очистку.
        </span>
    </form>
</main>

<script>
    (function () {
        var form = document.querySelector('.jobs-form');

        if (!form) {
            return;
        }

        var selectAll = form.querySelector('[data-select-all]');
        var rowSelects = Array.prototype.slice.call(form.querySelectorAll('[data-row-select]'));
        var counter = form.querySelector('[data-selected-count]');
        var bulkBar = form.querySelector('[data-bulk-bar]');
        var applyButtons = Array.prototype.slice.call(form.querySelectorAll('[data-bulk-apply]'));

        function checked() {
            return rowSelects.filter(function (box) {
                return box.checked;
            });
        }

        function sync() {
            var count = checked().length;

            counter.textContent = 'Отмечено: ' + count;
            bulkBar.classList.toggle('is-visible', count > 0);

            if (selectAll) {
                selectAll.checked = count > 0 && count === rowSelects.length;
                selectAll.indeterminate = count > 0 && count < rowSelects.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rowSelects.forEach(function (box) {
                    box.checked = selectAll.checked;
                });

                sync();
            });
        }

        rowSelects.forEach(function (box) {
            box.addEventListener('change', sync);
        });

        applyButtons.forEach(function (button) {
            // Кнопки видны только при отмеченных задачах, так что проверять пустой выбор не нужно
            button.addEventListener('click', function (event) {
                var count = checked().length;
                var label = button.textContent.trim().toLowerCase();

                if (!confirm('Выполнить действие «' + label + '» для отмеченных задач (' + count + ')?')) {
                    event.preventDefault();
                }
            });
        });

        var cleanupForm = document.querySelector('[data-cleanup-form]');

        if (cleanupForm) {
            cleanupForm.addEventListener('submit', function (event) {
                var days = cleanupForm.querySelector('[name="cleanup_days"]').value;

                if (!confirm('Удалить задачи, закрытые больше ' + days + ' дней назад? Отменить будет нельзя.')) {
                    event.preventDefault();
                }
            });
        }

        sync();
    })();
</script>
</body>
</html>
            <?php
        } catch (\Throwable $exception) {
            // Недорисованная страница не должна утечь в вывод
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            throw $exception;
        }

        return [
            'status' => 200,
            'headers' => $headers,
            'body' => (string) ob_get_clean(),
        ];
    }

    /**
     * @param mixed $value
     */
    private function escape($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }

    /**
     * Стили админки из файла пакета — для встраивания в страницу.
     * Если файл недоступен, страница просто отрисуется без оформления.
     */
    private function readCss(): string
    {
        $css = @file_get_contents(dirname(__DIR__, 2) . '/resources/QueueDashboard.css');

        if ($css === false) {
            return '';
        }

        // Страховка от преждевременного закрытия <style> — в самом CSS такого нет,
        // но файл могли отредактировать.
        return str_replace('</', '<\/', $css);
    }

    /**
     * @param mixed $value
     */
    private function highlight($value, string $search): string
    {
        $escapedValue = $this->escape($value);

        if ($search === '') {
            return $escapedValue;
        }

        $escapedSearch = $this->escape($search);
        $pattern = '/' . preg_quote($escapedSearch, '/') . '/iu';
        $highlighted = preg_replace($pattern, '<mark>$0</mark>', $escapedValue);

        if ($highlighted === null) {
            return $escapedValue;
        }

        return $highlighted;
    }

    /**
     * Заголовок Set-Cookie с токеном защиты от CSRF.
     *
     * Схема double-submit cookie: одно и то же случайное значение лежит в cookie
     * и в скрытом поле POST-форм. Чужой сайт не может прочитать cookie, чтобы
     * подставить значение в свою форму, а из-за SameSite браузер и не отправит её
     * с межсайтовым POST. Сессии не нужны — дашборд не зависит от того, как
     * устроено приложение, в которое он встроен.
     */
    private function csrfCookieHeader(string $name, string $token, bool $https): string
    {
        return $name . '=' . $token . '; Path=/; HttpOnly; SameSite=Lax' . ($https ? '; Secure' : '');
    }

    /**
     * @param mixed $token Значение cookie из запроса
     * @return string Токен или пустая строка, если cookie нет или она подделана
     */
    private function readCsrfToken($token): string
    {
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return '';
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function isHttps(array $server): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));

        return $https !== '' && $https !== 'off';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $params): array
    {
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
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
     * Время из базы (UTC) — во время пояса админки.
     * Строку в неожиданном формате показываем как есть.
     */
    private function toDisplayTime(string $utc): string
    {
        $date = \DateTimeImmutable::createFromFormat(Job::DATE_FORMAT, $utc, new \DateTimeZone('UTC'));

        if ($date === false) {
            return $utc;
        }

        return $date->setTimezone($this->timezone)->format(Job::DATE_FORMAT);
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

    /**
     * @param mixed $value
     */
    private function normalizeDate($value): string
    {
        $date = trim((string) $value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        $parts = explode('-', $date);
        $year = (int) $parts[0];
        $month = (int) $parts[1];
        $day = (int) $parts[2];

        if (!checkdate($month, $day, $year)) {
            return '';
        }

        return $date;
    }

    private function makeJsonReadable(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $payload;
        }

        $decoded = $this->decodeNestedJson($decoded);
        $result = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($result === false) {
            return $payload;
        }

        return $result;
    }

    /**
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

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = ltrim($value);

        if ($trimmed === '') {
            return $value;
        }

        $firstCharacter = $trimmed[0];

        if ($firstCharacter !== '{' && $firstCharacter !== '[') {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $value;
        }

        return $this->decodeNestedJson($decoded);
    }

    private function expandEscapedNewLines(string $value): string
    {
        return str_replace(
            ['\\r\\n', '\\n'],
            "\n",
            $value
        );
    }
}
