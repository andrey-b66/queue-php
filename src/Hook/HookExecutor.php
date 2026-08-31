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
 * Хук по желанию может вернуть результат через `return` — он попадёт в поле
 * `result` задачи.
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

    /**
     * @return string|null Результат, возвращённый хуком, либо null
     */
    public function handle(Job $job): ?string
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

        return $this->run($hookFile, $this->decodePayload($job->payload));
    }

    /**
     * Выполняет файл хука в изолированной области видимости —
     * ему доступен только $payload.
     */
    private function run(string $__hookFile, mixed $payload): ?string
    {
        $result = (static function () use ($__hookFile, $payload): mixed {
            return require $__hookFile;
        })();

        return $this->normalizeResult($result);
    }

    /**
     * Файл без `return` отдаёт из require единицу — считаем, что хук
     * результата не сообщил. Массивы и объекты сохраняем как JSON.
     */
    private function normalizeResult(mixed $result): ?string
    {
        if ($result === null || is_bool($result) || $result === 1) {
            return null;
        }

        if (is_scalar($result)) {
            return (string) $result;
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
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
