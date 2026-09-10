<?php

namespace Integrat\Queue;

class Job
{
    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** null — задача ещё не сохранена */
    public ?int $id = null;

    /** Тип задачи: стабильный ярлык, по которому подбирается обработчик */
    public string $type;
    public string $source;
    public string $payload;
    public string $status;
    public string $createdAt;
    public string $updatedAt;
    public ?string $closedAt = null;
    public ?string $error = null;
    public ?string $result = null;

    public static function create(
        string $type,
        string $source,
        string $payload,
        ?string $result = null,
    ): self {
        $job = new self();

        $job->type = $type;
        $job->source = $source;
        $job->payload = $payload;
        $job->status = self::STATUS_NEW;
        $job->createdAt = date('Y-m-d H:i:s');
        $job->updatedAt = date('Y-m-d H:i:s');
        $job->result = $result;

        return $job;
    }

    public function markProcessing(): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->error = null;
        $this->updatedAt = date('Y-m-d H:i:s');
        $this->closedAt = null;
        return $this;
    }

    public function markCompleted(?string $result = null): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->error = null;
        $this->updatedAt = date('Y-m-d H:i:s');
        $this->closedAt = date('Y-m-d H:i:s');

        if ($result !== null) {
            $this->result = $result;
        }

        return $this;
    }

    public function markFailed(?string $error = null, ?string $result = null): self
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->updatedAt = date('Y-m-d H:i:s');
        $this->closedAt = date('Y-m-d H:i:s');

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
        $job->type = (string) $row['type'];
        $job->source = (string) $row['source'];
        $job->payload = (string) $row['payload'];
        $job->status = (string) $row['status'];
        $job->createdAt = (string) $row['created_at'];
        $job->updatedAt = (string) $row['updated_at'];
        $job->closedAt = $row['closed_at'] ?? null;
        $job->error = $row['error'] ?? null;
        $job->result = $row['result'] ?? null;

        return $job;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'payload' => $this->payload,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'closed_at' => $this->closedAt,
            'error' => $this->error,
            'result' => $this->result,
        ];
    }
}
