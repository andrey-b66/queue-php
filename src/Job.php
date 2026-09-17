<?php

declare(strict_types=1);

namespace Integrat\Queue;

class Job
{
    /**
     * Формат хранения всех дат: и в полях объекта, и в колонках таблицы.
     * Время всегда в UTC, независимо от date.timezone процесса: веб и крон
     * часто настроены по-разному, а пишут в одну базу.
     */
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Все статусы — в порядке жизни задачи */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /** null — задача ещё не сохранена */
    public ?int $id = null;

    /** Источник задачи: стабильный ярлык, по которому подбирается обработчик */
    public string $source;
    public string $payload;
    public string $status;
    public string $createdAt;
    public string $updatedAt;
    public ?string $closedAt = null;

    /** Необязательные поля: заполняются только если вызывающий этого хочет */
    public ?string $info = null;
    public ?string $result = null;
    public ?string $error = null;

    private function __construct()
    {
    }

    /**
     * Новая задача. result и error у неё появятся только после прогона — их ставят
     * markCompleted() и markFailed().
     */
    public static function create(string $source, string $payload, ?string $info = null): self
    {
        $job = new self();
        $now = gmdate(self::DATE_FORMAT);

        $job->source = $source;
        $job->payload = $payload;
        $job->status = self::STATUS_NEW;
        $job->createdAt = $now;
        $job->updatedAt = $now;
        $job->info = $info;

        return $job;
    }

    /**
     * Вернуть задачу в очередь: result и error прошлого прогона обнуляются, info остаётся.
     */
    public function markNew(): self
    {
        return $this->reopen(self::STATUS_NEW);
    }

    /**
     * Взять задачу в работу: result и error прошлого прогона обнуляются, info остаётся.
     */
    public function markProcessing(): self
    {
        return $this->reopen(self::STATUS_PROCESSING);
    }

    /**
     * Закрыть задачу как выполненную: error стирается, result без аргумента остаётся прежним.
     */
    public function markCompleted(?string $result = null): self
    {
        return $this->close(self::STATUS_COMPLETED, $result, null);
    }

    /**
     * Закрыть задачу как упавшую: error заменяется переданным (без аргумента — стирается),
     * result без аргумента остаётся прежним.
     */
    public function markFailed(?string $result = null, ?string $error = null): self
    {
        return $this->close(self::STATUS_FAILED, $result, $error);
    }

    /**
     * Восстановление из БД
     */
    public static function fromDatabase(array $row): self
    {
        $job = new self();

        $job->id = (int) $row['id'];
        $job->source = (string) $row['source'];
        $job->payload = (string) $row['payload'];
        $job->status = (string) $row['status'];
        $job->createdAt = (string) $row['created_at'];
        $job->updatedAt = (string) $row['updated_at'];
        $job->closedAt = $row['closed_at'];
        $job->info = $row['info'];
        $job->result = $row['result'];
        $job->error = $row['error'];

        return $job;
    }

    /**
     * Следы прошлого прогона к открытой задаче не относятся. Info не трогаем —
     * это заметка вызывающего, а не след прогона.
     */
    private function reopen(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = gmdate(self::DATE_FORMAT);
        $this->closedAt = null;
        $this->result = null;
        $this->error = null;

        return $this;
    }

    private function close(string $status, ?string $result, ?string $error): self
    {
        $this->status = $status;
        $this->updatedAt = gmdate(self::DATE_FORMAT);
        $this->closedAt = $this->updatedAt;
        $this->error = $error;

        if ($result !== null) {
            $this->result = $result;
        }

        return $this;
    }
}
