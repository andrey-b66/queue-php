<?php

declare(strict_types=1);

namespace Integrat\Queue;

/**
 * Воркер: выполняет новые задачи очереди обработчиками, зарегистрированными по source.
 *
 * Задачи берутся в работу без атомарного захвата, поэтому одновременно работает один
 * воркер: пока он держит файл блокировки, другой запуск сразу завершается. Так воркер
 * можно запускать кроном хоть каждую минуту — две копии одну задачу не возьмут.
 */
final class Worker
{
    private Queue $queue;
    private string $lockFile;

    /** @var array<string, callable> */
    private array $handlers = [];

    public function __construct(Queue $queue, string $lockFile)
    {
        $this->queue = $queue;
        $this->lockFile = $lockFile;
    }

    /**
     * Обработчик задач с этим source. Получает задачу и возвращает её result: строку, число
     * или null. Исключение из обработчика переводит задачу в failed, его текст попадает в error.
     *
     * @param callable(Job): (string|int|float|bool|null) $handler
     * @throws \InvalidArgumentException если для этого source обработчик уже есть
     */
    public function register(string $source, callable $handler): void
    {
        if (isset($this->handlers[$source])) {
            throw new \InvalidArgumentException("Обработчик для source «{$source}» уже зарегистрирован");
        }

        $this->handlers[$source] = $handler;
    }

    /**
     * Выполнить новые задачи в порядке поступления, включая пришедшие во время работы,
     * и завершиться. Задача, для source которой нет обработчика, уходит в failed.
     * Если уже работает другой воркер, сразу выходит.
     *
     * @throws \RuntimeException если не удалось открыть файл блокировки
     * @throws \PDOException если база недоступна
     */
    public function run(): void
    {
        $lock = @fopen($this->lockFile, 'c');

        if ($lock === false) {
            throw new \RuntimeException(
                "Не удалось открыть файл блокировки воркера {$this->lockFile}: " . (error_get_last()['message'] ?? '')
            );
        }

        // Блокировку снимает система, когда процесс завершается, даже аварийно
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return;
        }

        try {
            do {
                $jobs = $this->queue->find(['status' => Job::STATUS_NEW], 1, 100);

                foreach ($jobs as $job) {
                    $this->process($job);
                }
            } while ($jobs !== []);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function process(Job $job): void
    {
        $this->queue->update($job->markProcessing());

        try {
            $handler = $this->handlers[$job->source] ?? null;

            if ($handler === null) {
                throw new \RuntimeException("Нет обработчика для source «{$job->source}»");
            }

            $result = $handler($job);

            // Число от обработчика — тоже результат: пишется строкой, как при вызове без strict_types
            $this->queue->update($job->markCompleted(is_scalar($result) ? (string) $result : $result));
        } catch (\Throwable $exception) {
            $this->queue->update($job->markFailed(null, $exception->getMessage()));
        }
    }
}
