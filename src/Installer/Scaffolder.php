<?php

declare(strict_types=1);

namespace Integrat\Queue\Installer;

use RuntimeException;

/**
 * Копирует заготовки из stubs/ в корень проекта.
 *
 * Правило одно: уже существующий файл не трогаем — правки пользователя важнее заготовки.
 */
final class Scaffolder
{
    /**
     * Путь назначения (относительно корня проекта) => файл-заготовка в stubs/.
     */
    private const FILES = [
        'webhook.php'                      => 'webhook.php.stub',
        'queue.php'                        => 'queue.php.stub',
        'scripts/cron-queue-worker.php'    => 'scripts/cron-queue-worker.php.stub',
        'hooks/example.php'                => 'hooks/example.php.stub',
        'storage/.gitignore'               => 'storage/gitignore.stub',
    ];

    /**
     * Каталоги, которые создаются пустыми (БД и локи движок наполняет сам).
     */
    private const DIRS = [
        'storage/database',
        'storage/locks',
    ];

    /**
     * @param array<string, string> $replacements плейсхолдеры вида {{NAME}} в заготовках
     */
    public function __construct(
        private readonly string $stubDir,
        private readonly string $projectRoot,
        private readonly array $replacements = [],
    ) {
    }

    /**
     * @return string[] относительные пути созданных файлов
     */
    public function run(): array
    {
        $created = [];

        foreach (self::DIRS as $dir) {
            $this->makeDir($this->projectRoot . '/' . $dir);
        }

        foreach (self::FILES as $target => $stub) {
            if ($this->copyStub($stub, $target)) {
                $created[] = $target;
            }
        }

        return $created;
    }

    private function copyStub(string $stub, string $target): bool
    {
        $source      = $this->stubDir . '/' . $stub;
        $destination = $this->projectRoot . '/' . $target;

        if (file_exists($destination) || !is_file($source)) {
            return false;
        }

        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new RuntimeException("Не удалось прочитать заготовку: {$source}");
        }

        $contents = strtr($contents, $this->replacements);

        $this->makeDir(\dirname($destination));

        if (file_put_contents($destination, $contents) === false) {
            throw new RuntimeException("Не удалось создать файл: {$destination}");
        }

        return true;
    }

    private function makeDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать каталог: {$dir}");
        }
    }
}
