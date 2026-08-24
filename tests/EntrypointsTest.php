<?php

declare(strict_types=1);

use Integrat\Queue\Admin\QueueAdmin;
use Integrat\Queue\Admin\QueueDashboard;
use Integrat\Queue\Hook\HookWorker;
use Integrat\Queue\Installer\InstallCommand;
use Integrat\Queue\Job;
use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$temporaryRoot = rtrim(sys_get_temp_dir(), "/\\")
    . '/integrat-queue-entrypoints-'
    . bin2hex(random_bytes(6));

function assertEntrypoint(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeEntrypointDirectory(string $directory): void
{
    $temporaryDirectory = str_replace('\\', '/', rtrim(sys_get_temp_dir(), "/\\"));
    $normalized = str_replace('\\', '/', $directory);

    if (!str_starts_with($normalized, $temporaryDirectory . '/integrat-queue-entrypoints-')) {
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
    $databaseFile = $temporaryRoot . '/storage/database/queue.sqlite';
    $hooksDirectory = $temporaryRoot . '/hooks';
    mkdir($hooksDirectory, 0755, true);
    ini_set('log_errors', '1');
    ini_set('error_log', $temporaryRoot . '/worker-errors.log');

    file_put_contents(
        $hooksDirectory . '/example.php',
        <<<'PHP'
<?php

file_put_contents(
    __DIR__ . '/../result.json',
    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
);
PHP,
    );

    $repository = new SqliteJobRepository($databaseFile);
    $queue = new Queue($repository);

    // Кастомная точка входа.
    $customJob = $queue->push(Job::create('custom', 'manual', '{"id":1}'));
    assertEntrypoint($queue->findById($customJob->id)?->source === 'manual', 'findById не работает');
    assertEntrypoint($queue->findByQueueName('custom')[0]->id === $customJob->id, 'Фильтр очереди неверен');
    assertEntrypoint($queue->markProcessing($customJob)?->status === Job::STATUS_PROCESSING, 'Нет processing');
    assertEntrypoint($queue->markCompleted($customJob)?->status === Job::STATUS_COMPLETED, 'Нет completed');
    assertEntrypoint($queue->delete($customJob), 'Queue::delete не удалил задание');

    // HookWorker — единственная публичная точка готовой цепочки.
    $hookJob = $queue->push(Job::create('example', 'amoCRM', '{"contact_id":42}'));
    $failedJob = $queue->push(Job::create('missing', 'external', '{}'));
    $lockFile = $databaseFile . '.hook-worker.lock';
    $worker = new HookWorker($databaseFile, $hooksDirectory, 10);

    $externalLockHandle = fopen($lockFile, 'c');
    assertEntrypoint(is_resource($externalLockHandle), 'Не удалось открыть lock-файл в тесте');

    try {
        assertEntrypoint(flock($externalLockHandle, LOCK_EX | LOCK_NB), 'Не удалось занять lock в тесте');
        assertEntrypoint($worker->run() === 0, 'Worker проигнорировал занятый lock');
        assertEntrypoint($queue->findById($hookJob->id)?->status === Job::STATUS_NEW, 'Worker забрал задачу без lock');
    } finally {
        flock($externalLockHandle, LOCK_UN);
        fclose($externalLockHandle);
    }

    assertEntrypoint($worker->run() === 2, 'Worker вернул неверное количество');
    assertEntrypoint(is_file($lockFile), 'Worker не создал lock-файл');
    assertEntrypoint(file_get_contents($temporaryRoot . '/result.json') === '{"contact_id":42}', 'JSON не декодирован');
    assertEntrypoint($queue->findById($hookJob->id)?->status === Job::STATUS_COMPLETED, 'Hook не завершён');
    assertEntrypoint($queue->findById($failedJob->id)?->status === Job::STATUS_FAILED, 'Ошибка не дала failed');

    // Административная точка входа и Dashboard.
    $admin = new QueueAdmin($repository);
    assertEntrypoint(in_array('example', $admin->getQueueNames(), true), 'Нет имени очереди');
    assertEntrypoint($admin->countFiltered(['status' => Job::STATUS_FAILED]) === 1, 'Неверный count');
    assertEntrypoint($admin->findFiltered(['source' => 'amoCRM'])[0]['id'] === $hookJob->id, 'Фильтр неверен');
    assertEntrypoint($admin->setStatusMany([$failedJob->id], Job::STATUS_NEW) === 1, 'Статус не изменён');

    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/dashboard.php';

    ob_start();
    (new QueueDashboard($admin))->handle();
    $html = (string) ob_get_clean();

    assertEntrypoint(str_contains($html, 'queue_name'), 'Dashboard использует старую модель');
    assertEntrypoint(str_contains($html, 'processing'), 'Dashboard не показывает processing');
    assertEntrypoint(str_contains($html, 'integrat/queue dashboard'), 'CSS не встроен');

    // Установщик создаёт выбранные наборы и не перезаписывает файлы.
    $applicationRoot = $temporaryRoot . '/application';
    mkdir($applicationRoot, 0755, true);
    file_put_contents($applicationRoot . '/composer.json', "{}\n");

    $installOutput = '';
    $installErrors = '';
    $installCommand = new InstallCommand(
        packageRoot: dirname(__DIR__),
        autoloadPath: dirname(__DIR__) . '/vendor/autoload.php',
        stdout: static function (string $message) use (&$installOutput): void {
            $installOutput .= $message;
        },
        stderr: static function (string $message) use (&$installErrors): void {
            $installErrors .= $message;
        },
    );

    assertEntrypoint($installCommand->run(['integrat-queue', 'help']) === 0, 'Справка завершилась с ошибкой');
    assertEntrypoint(str_contains($installOutput, 'install:dashboard'), 'В справке нет команды dashboard');
    assertEntrypoint(str_contains($installOutput, 'install:hooks'), 'В справке нет команды hooks');

    $installOutput = '';
    assertEntrypoint(
        $installCommand->run(['integrat-queue', 'install:hooks', '--help']) === 0,
        'Справка после команды завершилась с ошибкой',
    );
    assertEntrypoint(str_contains($installOutput, 'install:dashboard'), 'Справка после команды не показана');

    $dashboardRoot = $temporaryRoot . '/dashboard-application';
    mkdir($dashboardRoot, 0755, true);
    file_put_contents($dashboardRoot . '/composer.json', '{}' . PHP_EOL);
    $installOutput = '';

    assertEntrypoint(
        $installCommand->run(['integrat-queue', 'install:dashboard', $dashboardRoot], dirname(__DIR__)) === 0,
        'Команда install:dashboard завершилась с ошибкой',
    );
    assertEntrypoint(is_file($dashboardRoot . '/dashboard.php'), 'Dashboard не создан');
    assertEntrypoint(is_file($dashboardRoot . '/storage/.gitignore'), 'Общий .gitignore не создан');
    assertEntrypoint(is_dir($dashboardRoot . '/storage/database'), 'Каталог database не создан');
    assertEntrypoint(!is_file($dashboardRoot . '/webhook.php'), 'Dashboard установил webhook');
    assertEntrypoint(!is_file($dashboardRoot . '/scripts/hook-worker.php'), 'Dashboard установил worker');
    assertEntrypoint(!is_file($dashboardRoot . '/hooks/example.php'), 'Dashboard установил пример хука');
    assertEntrypoint(!str_contains($installOutput, 'Запускайте scripts/hook-worker.php'), 'Dashboard вывел подсказку hooks');

    $hooksRoot = $temporaryRoot . '/hooks-application';
    mkdir($hooksRoot, 0755, true);
    file_put_contents($hooksRoot . '/composer.json', '{}' . PHP_EOL);
    $installOutput = '';

    assertEntrypoint(
        $installCommand->run(['integrat-queue', 'install:hooks', $hooksRoot], dirname(__DIR__)) === 0,
        'Команда install:hooks завершилась с ошибкой',
    );
    assertEntrypoint(is_file($hooksRoot . '/webhook.php'), 'Webhook не создан');
    assertEntrypoint(is_file($hooksRoot . '/scripts/hook-worker.php'), 'Worker не создан');
    assertEntrypoint(is_file($hooksRoot . '/hooks/example.php'), 'Пример хука не создан');
    assertEntrypoint(!is_file($hooksRoot . '/dashboard.php'), 'Hooks установили dashboard');
    assertEntrypoint(str_contains($installOutput, 'Запускайте scripts/hook-worker.php'), 'Нет подсказки запуска worker');

    $customDashboard = '<?php' . PHP_EOL . '// Пользовательский dashboard' . PHP_EOL;
    file_put_contents($dashboardRoot . '/dashboard.php', $customDashboard);
    $installOutput = '';

    assertEntrypoint(
        $installCommand->run(['integrat-queue', 'install:hooks', $dashboardRoot], dirname(__DIR__)) === 0,
        'Команда hooks не дополнила dashboard-установку',
    );
    assertEntrypoint(is_file($dashboardRoot . '/webhook.php'), 'Наборы не дополняются');
    assertEntrypoint(
        file_get_contents($dashboardRoot . '/dashboard.php') === $customDashboard,
        'Пользовательский dashboard перезаписан',
    );

    $installOutput = '';

    assertEntrypoint(
        $installCommand->run(['integrat-queue', 'install', $applicationRoot], dirname(__DIR__)) === 0,
        'Команда install завершилась с ошибкой',
    );
    assertEntrypoint($installErrors === '', 'Команда install записала ошибку');
    assertEntrypoint(str_contains($installOutput, 'scripts/hook-worker.php'), 'Новый worker не создан');
    assertEntrypoint(is_file($applicationRoot . '/dashboard.php'), 'Dashboard не создан');
    $installedWorker = file_get_contents($applicationRoot . '/scripts/hook-worker.php');
    assertEntrypoint(is_string($installedWorker), 'Не удалось прочитать созданный worker');
    assertEntrypoint(!str_contains($installedWorker, 'lockFile'), 'Установщик оставил публичный lockFile');
    assertEntrypoint(!is_dir($applicationRoot . '/storage/locks'), 'Установщик создал лишний каталог locks');

    $installOutput = '';
    assertEntrypoint(
        $installCommand->run(['integrat-queue', 'install', $applicationRoot], dirname(__DIR__)) === 0,
        'Повторный install завершился с ошибкой',
    );
    assertEntrypoint(str_contains($installOutput, 'изменений нет'), 'Повторный install изменил файлы');

    fwrite(STDOUT, "EntrypointsTest: OK\n");
} finally {
    unset(
        $html,
        $installCommand,
        $admin,
        $worker,
        $queue,
        $repository,
        $customJob,
        $hookJob,
        $failedJob,
        $installedWorker,
    );
    removeEntrypointDirectory($temporaryRoot);
}
