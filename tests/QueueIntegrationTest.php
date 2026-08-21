<?php

declare(strict_types=1);

use Integrat\Queue\Job;
use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;
use Integrat\Queue\Worker\CronQueueWorker;

require dirname(__DIR__) . '/vendor/autoload.php';

$temporaryRoot = rtrim(sys_get_temp_dir(), "/\\")
    . '/integrat-queue-runtime-'
    . bin2hex(random_bytes(6));

function assertRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeRuntimeDirectory(string $directory): void
{
    $temporaryDirectory = str_replace('\\', '/', rtrim(sys_get_temp_dir(), "/\\"));
    $normalized = str_replace('\\', '/', $directory);

    if (!str_starts_with($normalized, $temporaryDirectory . '/integrat-queue-runtime-')) {
        throw new RuntimeException("Отказ от удаления неожиданного каталога: {$directory}");
    }

    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

try {
    mkdir($temporaryRoot . '/hooks', 0755, true);
    mkdir($temporaryRoot . '/storage/database', 0755, true);
    mkdir($temporaryRoot . '/storage/locks', 0755, true);

    file_put_contents(
        $temporaryRoot . '/hooks/example.php',
        <<<'PHP'
<?php

file_put_contents(
    __DIR__ . '/../processed.json',
    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
);
PHP,
    );

    $databaseFile = $temporaryRoot . '/storage/database/queue.sqlite';
    $queue = new Queue(new SqliteJobRepository($databaseFile));
    $job = $queue->push(
        [
            'HTTP_HOST' => 'localhost:3000',
            'REQUEST_URI' => '/webhook.php?hook=example',
            'REMOTE_ADDR' => '127.0.0.1',
        ],
        '{"contact_id":42}',
    );

    assertRuntime($job instanceof Job, 'push() должен вернуть Job');
    assertRuntime($job->getId() > 0, 'задание должно получить ID');
    assertRuntime($job->getHookName() === 'example', 'имя хука извлечено неверно');
    assertRuntime($job->getPayload() === ['contact_id' => 42], 'JSON payload прочитан неверно');
    assertRuntime(count($queue->getPending()) === 1, 'задание не найдено в pending');

    $worker = new CronQueueWorker(
        projectRoot: $temporaryRoot,
        databaseFile: $databaseFile,
        lockFile: $temporaryRoot . '/storage/locks/worker.lock',
    );

    assertRuntime($worker->acquireLock(), 'воркер не смог получить lock');
    try {
        $worker->run();
    } finally {
        $worker->releaseLock();
    }

    $rows = $queue->getAll();
    assertRuntime(($rows[0]['status'] ?? null) === Job::STATUS_COMPLETED, 'воркер не завершил задание');
    assertRuntime(
        json_decode((string) file_get_contents($temporaryRoot . '/processed.json'), true) === ['contact_id' => 42],
        'хук не получил payload',
    );

    fwrite(STDOUT, "QueueIntegrationTest: OK\n");
} finally {
    unset($worker, $queue, $job);
    removeRuntimeDirectory($temporaryRoot);
}
