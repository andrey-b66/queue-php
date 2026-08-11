<?php

namespace Queue\Hook;

use Queue\Core\Model\Job;

/**
 * Диспетчер хуков.
 *
 * Имя хука из задачи → файл hooks/{имя}.php, который выполняется с доступным
 * $payload. Что делать внутри — решает сам файл хука: вызвать сервис, встроить
 * сторонний код, что угодно. Никаких обязательных интерфейсов и шаблонов.
 */
class HookExecutor
{
    private string $hooksDir;

    public function __construct(string $projectRoot)
    {
        $this->hooksDir = rtrim($projectRoot, "/\\") . '/hooks';
    }

    public function handle(Job $job): void
    {
        $hookName = $job->getHookName();

        if (!$hookName) {
            throw new \RuntimeException("Не удалось извлечь имя хука из URL: {$job->getToUrl()}");
        }

        // Имя приходит из URL — разрешаем только простые имена (без обхода каталога)
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $hookName)) {
            throw new \RuntimeException("Недопустимое имя хука: {$hookName}");
        }

        $hookFile = "{$this->hooksDir}/{$hookName}.php";

        if (!is_file($hookFile)) {
            throw new \RuntimeException("Файл хука не найден: {$hookFile}");
        }

        $this->run($hookFile, $job->getPayload());
    }

    /**
     * Выполняет файл хука в изолированной области видимости —
     * ему доступен только $payload.
     *
     * @param array<string, mixed> $payload
     */
    private function run(string $__hookFile, array $payload): void
    {
        (static function () use ($__hookFile, $payload): void {
            require $__hookFile;
        })();
    }
}
