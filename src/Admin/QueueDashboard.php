<?php

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

    /**
     * @param string|null $cssUrl   URL стилей админки. По умолчанию (null) стили
     *                              встраиваются в страницу из файла пакета — это работает
     *                              независимо от того, доступен ли vendor/ из веба.
     *                              Укажите URL, если хотите отдавать CSS отдельным файлом
     *                              (кешируется браузером).
     */
    public function __construct(QueueAdmin $admin, ?string $cssUrl = null)
    {
        $this->admin = $admin;
        $this->cssUrl = $cssUrl;
    }

    public function handle(): void
    {
        $allowedLimits = [25, 50, 100, 200, 500, 1000, 2000];
        $allowedStatuses = [
            Job::STATUS_NEW,
            Job::STATUS_PROCESSING,
            Job::STATUS_COMPLETED,
            Job::STATUS_FAILED,
        ];

        $availableTypes = $this->admin->getTypes();

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $basePath = strtok($requestUri, '?');

        if ($basePath === false) {
            $basePath = '';
        }

        $requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Ключ — значение кнопки массового действия, значение — её подпись
        $bulkActions = [
            'status:' . Job::STATUS_NEW => 'Пометить new',
            'status:' . Job::STATUS_PROCESSING => 'Пометить processing',
            'status:' . Job::STATUS_COMPLETED => 'Пометить completed',
            'status:' . Job::STATUS_FAILED => 'Пометить failed',
            'delete' => 'Удалить',
        ];

        if ($requestMethod === 'POST') {
            $postLimit = (int) ($_POST['limit'] ?? 50);

            if (!in_array($postLimit, $allowedLimits, true)) {
                $postLimit = 50;
            }

            $back = [
                'search' => trim((string) ($_POST['search'] ?? '')),
                'status' => (string) ($_POST['status'] ?? ''),
                'type' => trim((string) ($_POST['type'] ?? '')),
                'created_from' => $this->normalizeDate($_POST['created_from'] ?? ''),
                'created_to' => $this->normalizeDate($_POST['created_to'] ?? ''),
                'limit' => $postLimit === 50 ? '' : $postLimit,
                'page' => max(1, (int) ($_POST['page'] ?? 1)),
            ];

            try {
                if (isset($_POST['bulk_action'])) {
                    $bulkAction = (string) $_POST['bulk_action'];
                    $ids = $_POST['ids'] ?? [];

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
                } else {
                    throw new \InvalidArgumentException('Неизвестное действие.');
                }
            } catch (\Throwable $exception) {
                $back['err'] = $exception->getMessage();
            }

            foreach ($back as $key => $value) {
                if ($value === '') {
                    unset($back[$key]);
                }
            }

            header('Location: ' . $basePath . '?' . http_build_query($back));
            exit;
        }

        $perPage = (int) ($_GET['limit'] ?? 50);

        if (!in_array($perPage, $allowedLimits, true)) {
            $perPage = 50;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $status = (string) ($_GET['status'] ?? '');
        $type = trim((string) ($_GET['type'] ?? ''));
        $search = trim((string) ($_GET['search'] ?? ''));
        $createdFrom = $this->normalizeDate($_GET['created_from'] ?? '');
        $createdTo = $this->normalizeDate($_GET['created_to'] ?? '');

        if (!in_array($status, $allowedStatuses, true)) {
            $status = '';
        }

        if (!in_array($type, $availableTypes, true)) {
            $type = '';
        }

        $filters = [
            'status' => $status,
            'type' => $type,
            'search' => $search,
            'created_from' => $createdFrom,
            'created_to' => $createdTo,
        ];

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
            || $type !== ''
            || $createdFrom !== ''
            || $createdTo !== '';

        $listUrl = function (array $overrides = []) use (
            $search,
            $status,
            $type,
            $createdFrom,
            $createdTo,
            $perPage,
            $page
        ): string {
            $params = [
                'search' => $search,
                'status' => $status,
                'type' => $type,
                'created_from' => $createdFrom,
                'created_to' => $createdTo,
                'limit' => $perPage === 50 ? null : $perPage,
                'page' => $page,
            ];

            foreach ($overrides as $key => $value) {
                $params[$key] = $value;
            }

            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    unset($params[$key]);
                }
            }

            return '?' . http_build_query($params);
        };

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
    <?php if (!empty($_GET['ok'])): ?>
        <div class="flash ok">
            <?= $this->escape($_GET['ok']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['err'])): ?>
        <div class="flash error">
            Ошибка: <?= $this->escape($_GET['err']) ?>
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
                    placeholder="ID, тип, источник, payload, error или result"
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
                <label for="type">Тип</label>
                <select id="type" name="type">
                    <option value="">Все типы</option>
                    <?php foreach ($availableTypes as $availableType): ?>
                        <option
                            value="<?= $this->escape($availableType) ?>"
                            <?= $type === $availableType ? 'selected' : '' ?>
                        >
                            <?= $this->escape($availableType) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="created_from">Дата создания, от</label>
                <input
                    id="created_from"
                    type="date"
                    name="created_from"
                    value="<?= $this->escape($createdFrom) ?>"
                    max="<?= $this->escape($createdTo) ?>"
                >
            </div>

            <div class="field">
                <label for="created_to">Дата создания, до</label>
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

            <?php if ($hasActiveFilters): ?>
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
        <input type="hidden" name="limit" value="<?= $this->escape($perPage) ?>">
        <input type="hidden" name="page" value="<?= $this->escape($page) ?>">
        <input type="hidden" name="status" value="<?= $this->escape($status) ?>">
        <input type="hidden" name="type" value="<?= $this->escape($type) ?>">
        <input type="hidden" name="search" value="<?= $this->escape($search) ?>">
        <input type="hidden" name="created_from" value="<?= $this->escape($createdFrom) ?>">
        <input type="hidden" name="created_to" value="<?= $this->escape($createdTo) ?>">

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

        <!-- Сводка стоит справа — на месте убранной кнопки «Переотправить все проваленные» -->
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
                        <th>type</th>
                        <th>source</th>
                        <th>status</th>
                        <th>error</th>
                        <th>result</th>
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
                            $errorText = (string) ($row['error'] ?? '');
                            $resultText = (string) ($row['result'] ?? '');
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

                                <td class="job-type">
                                    <?= $this->highlight($row['type'], $search) ?>
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
                                    <?php if ($errorText !== ''): ?>
                                        <pre class="error-text"><?= $this->highlight($errorText, $search) ?></pre>
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

                                <td class="date">
                                    <?= $this->escape($row['created_at']) ?>
                                </td>

                                <td class="date">
                                    <?= $this->escape($row['updated_at']) ?>
                                </td>

                                <td class="date">
                                    <?= ($row['closed_at'] ?? '') === '' ? '—' : $this->escape($row['closed_at']) ?>
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

        <form class="page-size-form" method="get">
            <input type="hidden" name="search" value="<?= $this->escape($search) ?>">
            <input type="hidden" name="status" value="<?= $this->escape($status) ?>">
            <input type="hidden" name="type" value="<?= $this->escape($type) ?>">
            <input type="hidden" name="created_from" value="<?= $this->escape($createdFrom) ?>">
            <input type="hidden" name="created_to" value="<?= $this->escape($createdTo) ?>">

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
            button.addEventListener('click', function (event) {
                var count = checked().length;

                if (count === 0) {
                    event.preventDefault();
                    alert('Отметьте хотя бы одну задачу.');

                    return;
                }

                var label = button.textContent.trim().toLowerCase();

                if (!confirm('Выполнить действие «' + label + '» для отмеченных задач (' + count + ')?')) {
                    event.preventDefault();
                }
            });
        });

        sync();
    })();
</script>
</body>
</html>
        <?php
    }

    /**
     * @param mixed $value
     */
    private function escape($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8',
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
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
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
            $value,
        );
    }
}
