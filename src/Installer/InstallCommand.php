<?php

declare(strict_types=1);

namespace Integrat\Queue\Installer;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Явная установка стартовых файлов пакета в приложение.
 */
final class InstallCommand
{
    private Closure $stdout;
    private Closure $stderr;

    public function __construct(
        private readonly string $packageRoot,
        private readonly string $autoloadPath,
        ?callable $stdout = null,
        ?callable $stderr = null,
    ) {
        $this->stdout = $stdout !== null
            ? Closure::fromCallable($stdout)
            : static fn (string $message): int|false => fwrite(STDOUT, $message);
        $this->stderr = $stderr !== null
            ? Closure::fromCallable($stderr)
            : static fn (string $message): int|false => fwrite(STDERR, $message);
    }

    /**
     * @param string[] $arguments Полный массив argv, включая имя executable.
     */
    public function run(array $arguments, ?string $workingDirectory = null): int
    {
        $command = $arguments[1] ?? null;

        if ($command === null || $command === 'help' || $command === '--help' || $command === '-h') {
            $this->writeHelp();
            return 0;
        }

        if ($command !== 'install') {
            $this->error("Неизвестная команда: {$command}\n\n");
            $this->writeHelp(true);
            return 64;
        }

        if (count($arguments) > 3) {
            $this->error("Слишком много аргументов.\n\n");
            $this->writeHelp(true);
            return 64;
        }

        $workingDirectory ??= getcwd() ?: '.';
        $requestedRoot = $arguments[2] ?? $workingDirectory;

        try {
            $projectRoot = $this->resolveProjectRoot($requestedRoot, $workingDirectory);
            $vendorPath = $this->relativePath($projectRoot, dirname($this->autoloadPath));

            $scaffolder = new Scaffolder(
                $this->packageRoot . '/resources/stubs',
                $projectRoot,
                ['{{VENDOR}}' => $vendorPath],
            );

            $created = $scaffolder->run();
        } catch (Throwable $exception) {
            $this->error("integrat/queue: {$exception->getMessage()}\n");
            return 1;
        }

        if ($created === []) {
            $this->write("integrat/queue: все стартовые файлы уже существуют, изменений нет.\n");
            return 0;
        }

        $this->write("integrat/queue: созданы стартовые файлы:\n");
        foreach ($created as $path) {
            $this->write("  - {$path}\n");
        }
        $this->write("Настройте cron для scripts/cron-queue-worker.php.\n");

        return 0;
    }

    private function resolveProjectRoot(string $requestedRoot, string $workingDirectory): string
    {
        if (!$this->isAbsolutePath($requestedRoot)) {
            $requestedRoot = rtrim($workingDirectory, "/\\") . '/' . $requestedRoot;
        }

        $projectRoot = realpath($requestedRoot);

        if ($projectRoot === false || !is_dir($projectRoot)) {
            throw new RuntimeException("Каталог проекта не найден: {$requestedRoot}");
        }

        if (!is_file($projectRoot . '/composer.json')) {
            throw new RuntimeException("В каталоге нет composer.json: {$projectRoot}");
        }

        return rtrim(str_replace('\\', '/', $projectRoot), '/');
    }

    private function relativePath(string $fromDirectory, string $toDirectory): string
    {
        $from = $this->pathParts($fromDirectory);
        $to = $this->pathParts($toDirectory);

        if ($from['root'] !== $to['root']) {
            throw new RuntimeException('Каталог vendor должен находиться на том же диске, что и проект');
        }

        $common = 0;
        $max = min(count($from['parts']), count($to['parts']));

        while ($common < $max && $this->samePathPart($from['parts'][$common], $to['parts'][$common])) {
            $common++;
        }

        $parts = array_fill(0, count($from['parts']) - $common, '..');
        $parts = array_merge($parts, array_slice($to['parts'], $common));

        return $parts === [] ? '.' : implode('/', $parts);
    }

    /**
     * @return array{root: string, parts: string[]}
     */
    private function pathParts(string $path): array
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            throw new RuntimeException("Путь не найден: {$path}");
        }

        $normalized = str_replace('\\', '/', $resolved);
        $root = '';

        if (preg_match('/^[A-Za-z]:/', $normalized, $matches) === 1) {
            $root = strtolower($matches[0]);
            $normalized = substr($normalized, 2);
        } elseif (str_starts_with($normalized, '/')) {
            $root = '/';
        }

        return [
            'root' => $root,
            'parts' => array_values(array_filter(explode('/', trim($normalized, '/')), 'strlen')),
        ];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function samePathPart(string $left, string $right): bool
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    private function writeHelp(bool $error = false): void
    {
        $message = <<<TXT
integrat/queue — автономная SQLite-очередь

Использование:
  integrat-queue install [путь-к-проекту]

Команда создаёт только отсутствующие стартовые файлы и никогда
не перезаписывает существующие файлы приложения.

TXT;

        $error ? $this->error($message) : $this->write($message);
    }

    private function write(string $message): void
    {
        ($this->stdout)($message);
    }

    private function error(string $message): void
    {
        ($this->stderr)($message);
    }
}
