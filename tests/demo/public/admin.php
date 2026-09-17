<?php

declare(strict_types=1);

// Админка очереди на демо-базе. Запуск: composer demo, затем http://localhost:8000/admin.php

use Integrat\Queue\Dashboard;
use Integrat\Queue\Queue;

require __DIR__ . '/../../../vendor/autoload.php';

// Админка не проверяет права — стенд только для локального запуска
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('Демо-стенд запускается только встроенным сервером PHP: composer demo');
}

$queue = new Queue(__DIR__ . '/../storage/jobs.sqlite');

(new Dashboard($queue, 'Europe/Moscow'))->handle();
