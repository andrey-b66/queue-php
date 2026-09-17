<?php

declare(strict_types=1);

// Демо-стенд для ручной проверки админки: наполнить базу случайными задачами или очистить её.
// Запуск: composer demo, затем http://localhost:8000

use Integrat\Queue\Job;
use Integrat\Queue\Queue;

require __DIR__ . '/../../../vendor/autoload.php';

// Страница очищает базу и не проверяет права — стенд только для локального запуска
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('Демо-стенд запускается только встроенным сервером PHP: composer demo');
}

$queue = new Queue(__DIR__ . '/../storage/jobs.sqlite');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $message = 'Неизвестное действие';

    if ($action === 'generate') {
        $count = max(1, min(2000, (int) ($_POST['count'] ?? 0)));
        $sources = ['crm', 'shop', 'mail', 'https://example.com/webhooks/orders'];
        $errors = ['Таймаут соединения', 'HTTP 500 от CRM', "Неверный email\nв поле customer.email"];

        for ($i = 0; $i < $count; $i++) {
            // Даты разбросаны на 60 дней назад: есть что отбирать периодом и что чистить
            $createdAt = time() - random_int(0, 60 * 86400);
            $closedAt = min(time(), $createdAt + random_int(1, 3600));

            $job = Job::create(
                $sources[array_rand($sources)],
                (string) json_encode([
                    'order_id' => random_int(1000, 9999),
                    'customer' => ['name' => 'Иван Петров', 'email' => 'ivan@example.com'],
                    // Вложенный JSON строкой — админка раскрывает и его
                    'items' => json_encode([['sku' => 'A-1', 'qty' => random_int(1, 5)]]),
                    'comment' => "Позвонить\nперед доставкой",
                ], JSON_UNESCAPED_UNICODE),
                random_int(0, 3) === 0 ? 'повторная отправка' : null
            );

            $status = Job::STATUSES[array_rand(Job::STATUSES)];

            if ($status === Job::STATUS_PROCESSING) {
                $job->markProcessing();
            }

            if ($status === Job::STATUS_COMPLETED) {
                $job->markCompleted('{"crm_id": ' . random_int(100000, 999999) . '}');
            }

            if ($status === Job::STATUS_FAILED) {
                $job->markFailed(null, $errors[array_rand($errors)]);
            }

            $job->createdAt = gmdate(Job::DATE_FORMAT, $createdAt);
            $job->updatedAt = $job->status === Job::STATUS_NEW ? $job->createdAt : gmdate(Job::DATE_FORMAT, $closedAt);
            $job->closedAt = $job->closedAt === null ? null : $job->updatedAt;

            $queue->push($job);
        }

        $message = "Сгенерировано задач: {$count}";
    }

    if ($action === 'clear') {
        $deleted = 0;

        while (($jobs = $queue->find([], 1, 1000)) !== []) {
            $deleted += $queue->delete(array_column($jobs, 'id'));
        }

        $message = "Удалено задач: {$deleted}";
    }

    header('Location: /?' . http_build_query(['ok' => $message]), true, 303);
    exit;
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Демо очереди</title>
    <style>
        body {
            max-width: 760px;
            margin: 0 auto;
            padding: 24px 16px;
            color: #1f2937;
            background: #f5f5f5;
            font: 14px/1.5 Arial, sans-serif;
        }

        section,
        .flash {
            margin-bottom: 16px;
            padding: 16px;
            background: #ffffff;
            border: 1px solid #d1d5db;
        }

        .flash {
            color: #166534;
            background: #dcfce7;
        }

        input,
        button,
        .admin-link {
            padding: 8px 14px;
            border-radius: 4px;
            font: inherit;
        }

        input {
            width: 100px;
            border: 1px solid #9ca3af;
        }

        button,
        .admin-link {
            display: inline-block;
            color: #ffffff;
            background: #1f2937;
            border: 0;
            text-decoration: none;
            cursor: pointer;
        }

        .danger {
            background: #b91c1c;
        }
    </style>
</head>
<body>
<h1>Демо очереди</h1>

<?php if (!empty($_GET['ok'])): ?>
    <div class="flash"><?= htmlspecialchars((string) $_GET['ok'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section>
    <a class="admin-link" href="/admin.php">Открыть админку →</a>
</section>

<section>
    <form method="post">
        <input type="hidden" name="action" value="generate">

        <label for="count">Сгенерировать случайных задач:</label>
        <input id="count" name="count" type="number" min="1" max="2000" value="120">

        <button type="submit">Сгенерировать</button>
    </form>
</section>

<section>
    <form method="post" onsubmit="return confirm('Удалить все задачи из демо-базы?')">
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="danger">Удалить все задачи</button>
    </form>
</section>
</body>
</html>
