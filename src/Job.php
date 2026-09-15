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

    public static function create(
        string $source,
        string $payload,
        ?string $info = null,
        ?string $result = null,
        ?string $error = null
    ): self {
        $job = new self();
        $now = gmdate(self::DATE_FORMAT);

        $job->source = $source;
        $job->payload = $payload;
        $job->status = self::STATUS_NEW;
        $job->createdAt = $now;
        $job->updatedAt = $now;
        $job->info = $info;
        $job->result = $result;
        $job->error = $error;

        return $job;
    }

    /**
     * Задача возвращается в очередь и будет выполняться заново: следы прошлого
     * прогона к ней больше не относятся, поэтому result и error обнуляются.
     * Info не трогаем — это заметка вызывающего, а не след прогона.
     */
    public function markNew(): self
    {
        $this->status = self::STATUS_NEW;
        $this->updatedAt = gmdate(self::DATE_FORMAT);
        $this->closedAt = null;
        $this->result = null;
        $this->error = null;
        return $this;
    }

    /**
     * Задача уходит в работу: следы прошлого прогона больше не актуальны,
     * поэтому result и error обнуляются. Info не трогаем — это заметка
     * вызывающего, а не след прогона.
     */
    public function markProcessing(): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->updatedAt = gmdate(self::DATE_FORMAT);
        $this->closedAt = null;
        $this->result = null;
        $this->error = null;
        return $this;
    }

    public function markCompleted(?string $result = null): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->error = null;
        $this->updatedAt = gmdate(self::DATE_FORMAT);
        $this->closedAt = $this->updatedAt;

        if ($result !== null) {
            $this->result = $result;
        }

        return $this;
    }

    public function markFailed(?string $result = null, ?string $error = null): self
    {
        $this->status = self::STATUS_FAILED;
        $this->updatedAt = gmdate(self::DATE_FORMAT);
        $this->closedAt = $this->updatedAt;
        $this->error = $error;

        if ($result !== null) {
            $this->result = $result;
        }

        return $this;
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
        $job->closedAt = $row['closed_at'] ?? null;
        $job->info = $row['info'] ?? null;
        $job->result = $row['result'] ?? null;
        $job->error = $row['error'] ?? null;

        return $job;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'payload' => $this->payload,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'closed_at' => $this->closedAt,
            'info' => $this->info,
            'result' => $this->result,
            'error' => $this->error,
        ];
    }
}
