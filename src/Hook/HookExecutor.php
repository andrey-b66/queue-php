<?php

namespace Integrat\Queue\Hook;

use Integrat\Queue\Job;
use JsonException;

/**
 * Внутренний исполнитель одного файлового хука.
 *
 * Имя хука из задачи → файл hooks/{имя}.php, который выполняется с доступным
 * $payload. Что делать внутри — решает сам файл хука: вызвать сервис, встроить
 * сторонний код, что угодно. Никаких обязательных интерфейсов и шаблонов.
 *
 * @internal Используйте HookWorker как публичную точку входа.
 */
final class HookExecutor
{
    private string $hooksDir;

    public function __construct(string $hooksDirectory)
    {
        $this->hooksDir = rtrim($hooksDirectory, "/\\");
    }

    public function handle(Job $job): void
    {
        $hookName = $job->queueName;

        if (!$hookName) {
            throw new \RuntimeException("Не удалось извлечь имя хука, задача: {$job->id}");
        }

        // Имя очереди определяет файл — запрещаем обход каталога.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $hookName)) {
            throw new \RuntimeException("Недопустимое имя хука: {$hookName}");
        }

        $hookFile = "{$this->hooksDir}/{$hookName}.php";

        if (!is_file($hookFile)) {
            throw new \RuntimeException("Файл хука не найден: {$hookFile}");
        }

        $this->run($hookFile, $this->decodePayload($job->payload));
    }

    /**
     * Выполняет файл хука в изолированной области видимости —
     * ему доступен только $payload.
     */
    private function run(string $__hookFile, mixed $payload): void
    {
        (static function () use ($__hookFile, $payload): void {
            require $__hookFile;
        })();
    }

    private function decodePayload(string $payload): mixed
    {
        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $payload;
        }
    }
}
