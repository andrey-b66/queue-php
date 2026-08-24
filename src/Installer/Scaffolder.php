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
    private const COMMON_FILES = [
        'storage/.gitignore' => 'storage/gitignore.stub',
    ];

    private const COMPONENT_FILES = [
        'dashboard' => [
            'dashboard.php' => 'dashboard.php.stub',
        ],
        'hooks' => [
            'webhook.php'             => 'webhook.php.stub',
            'scripts/hook-worker.php' => 'scripts/hook-worker.php.stub',
            'hooks/example.php'       => 'hooks/example.php.stub',
        ],
    ];

    /**
     * Каталоги, которые создаются пустыми (БД и lock-файл worker создаст сам).
     */
    private const DIRS = [
        'storage/database',
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
     * @param list<'dashboard'|'hooks'> $components
     * @return string[] относительные пути созданных файлов
     */
    public function run(array $components = ['dashboard', 'hooks']): array
    {
        $created = [];
        $files = [];

        foreach ($components as $component) {
            if (!isset(self::COMPONENT_FILES[$component])) {
                throw new RuntimeException('Неизвестный компонент установки: ' . $component);
            }

            $files += self::COMPONENT_FILES[$component];
        }

        $files += self::COMMON_FILES;

        foreach (self::DIRS as $dir) {
            $this->makeDir($this->projectRoot . '/' . $dir);
        }

        foreach ($files as $target => $stub) {
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

        if (file_exists($destination)) {
            return false;
        }

        if (!is_file($source)) {
            throw new RuntimeException('Заготовка не найдена: ' . $source);
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
