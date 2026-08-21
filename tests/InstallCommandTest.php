<?php

declare(strict_types=1);

use Integrat\Queue\Installer\InstallCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$temporaryRoot = rtrim(sys_get_temp_dir(), "/\\")
    . '/integrat-queue-install-'
    . bin2hex(random_bytes(6));

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTestDirectory(string $directory): void
{
    $temporaryRoot = str_replace('\\', '/', rtrim(sys_get_temp_dir(), "/\\"));
    $normalized = str_replace('\\', '/', $directory);

    if (!str_starts_with($normalized, $temporaryRoot . '/integrat-queue-install-')) {
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
    mkdir($temporaryRoot . '/custom-vendor', 0755, true);
    mkdir($temporaryRoot . '/hooks', 0755, true);
    file_put_contents($temporaryRoot . '/composer.json', "{}\n");
    file_put_contents($temporaryRoot . '/custom-vendor/autoload.php', "<?php\n");
    file_put_contents($temporaryRoot . '/hooks/example.php', "<?php // user file\n");

    $stdout = '';
    $stderr = '';
    $command = new InstallCommand(
        packageRoot: dirname(__DIR__),
        autoloadPath: $temporaryRoot . '/custom-vendor/autoload.php',
        stdout: static function (string $message) use (&$stdout): void {
            $stdout .= $message;
        },
        stderr: static function (string $message) use (&$stderr): void {
            $stderr .= $message;
        },
    );

    $exitCode = $command->run(['integrat-queue', 'install'], $temporaryRoot);

    assertTrue($exitCode === 0, 'install должен завершаться с кодом 0');
    assertTrue($stderr === '', 'install не должен писать ошибку');
    assertTrue(is_file($temporaryRoot . '/webhook.php'), 'webhook.php не создан');
    assertTrue(is_file($temporaryRoot . '/queue.php'), 'queue.php не создан');
    assertTrue(is_file($temporaryRoot . '/scripts/cron-queue-worker.php'), 'worker не создан');
    assertTrue(is_dir($temporaryRoot . '/storage/database'), 'storage/database не создан');
    assertTrue(is_dir($temporaryRoot . '/storage/locks'), 'storage/locks не создан');
    assertTrue(
        str_contains(
            (string) file_get_contents($temporaryRoot . '/webhook.php'),
            'use Integrat\\Queue\\Queue;',
        ),
        'webhook.php содержит неверный namespace главного API',
    );
    assertTrue(
        str_contains(
            (string) file_get_contents($temporaryRoot . '/scripts/cron-queue-worker.php'),
            'use Integrat\\Queue\\Worker\\CronQueueWorker;',
        ),
        'worker содержит неверный namespace',
    );
    assertTrue(
        file_get_contents($temporaryRoot . '/hooks/example.php') === "<?php // user file\n",
        'существующий пользовательский файл был перезаписан',
    );
    assertTrue(
        str_contains(
            (string) file_get_contents($temporaryRoot . '/webhook.php'),
            "__DIR__ . '/custom-vendor/autoload.php'",
        ),
        'нестандартный vendor-dir подставлен неверно',
    );

    $stdout = '';
    $secondExitCode = $command->run(['integrat-queue', 'install'], $temporaryRoot);
    assertTrue($secondExitCode === 0, 'повторный install должен завершаться с кодом 0');
    assertTrue(str_contains($stdout, 'изменений нет'), 'повторный install должен сообщить об отсутствии изменений');

    $invalidExitCode = $command->run(['integrat-queue', 'install'], $temporaryRoot . '/missing');
    assertTrue($invalidExitCode === 1, 'неверный каталог должен завершаться с кодом 1');

    $unknownExitCode = $command->run(['integrat-queue', 'unknown'], $temporaryRoot);
    assertTrue($unknownExitCode === 64, 'неизвестная команда должна завершаться с кодом 64');

    fwrite(STDOUT, "InstallCommandTest: OK\n");
} finally {
    removeTestDirectory($temporaryRoot);
}
