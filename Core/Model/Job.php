<?php

namespace Queue\Core\Model;

class Job
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    private int $id;
    private string $fromUrl;
    private string $toUrl;
    private string $status;
    private string $data;
    private string $createdAt;
    private string $updatedAt;
    private ?string $error = null;

    /**
     * Чистый фабричный метод — все данные извне
     */
    public static function create(array $server, string $rawData): self
    {
        $job = new self();
        $job->id = 0;
        // Referer у серверных вебхуков обычно отсутствует — фолбэк на IP отправителя
        $job->fromUrl = $server['HTTP_REFERER']
            ?? $server['HTTP_X_FORWARDED_FOR']
            ?? $server['REMOTE_ADDR']
            ?? '';
        $job->toUrl = self::buildCurrentUrl($server);
        $job->status = self::STATUS_PENDING;
        $job->data = $rawData ?: '{}';
        $job->createdAt = date('Y-m-d H:i:s');
        $job->updatedAt = date('Y-m-d H:i:s');

        return $job;
    }

    /**
     * Восстановление из БД
     */
    public static function fromDatabase(array $row): self
    {
        $job = new self();
        $job->id = (int) $row['id'];
        $job->fromUrl = $row['from_url'];
        $job->toUrl = $row['to_url'];
        $job->status = $row['status'];
        $job->data = $row['data'];
        $job->createdAt = $row['created_at'];
        $job->updatedAt = $row['updated_at'];
        $job->error = $row['error'] ?? null;

        return $job;
    }

    private static function buildCurrentUrl(array $server): string
    {
        $protocol = !empty($server['HTTPS']) && $server['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $server['HTTP_HOST'] ?? 'localhost';
        $uri = $server['REQUEST_URI'] ?? '';

        return $protocol . '://' . $host . $uri;
    }

    /**
     * Извлечь имя хука из URL
     */
    public function getHookName(): ?string
    {
        $query = parse_url($this->toUrl, PHP_URL_QUERY);
        if (!$query) {
            return null;
        }

        parse_str($query, $params);
        return $params['hook'] ?? null;
    }

    // Бизнес-методы (возвращают $this для цепочек)
    public function markCompleted(): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->error = null;
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    public function markFailed(?string $error = null): self
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    public function markPending(): self
    {
        $this->status = self::STATUS_PENDING;
        $this->error = null;
        $this->updatedAt = date('Y-m-d H:i:s');
        return $this;
    }

    // Проверки статуса
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from_url' => $this->fromUrl,
            'to_url' => $this->toUrl,
            'status' => $this->status,
            'data' => $this->data,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'error' => $this->error,
        ];
    }

    // Геттеры
    public function getId(): int
    {
        return $this->id;
    }
    public function getFromUrl(): string
    {
        return $this->fromUrl;
    }
    public function getToUrl(): string
    {
        return $this->toUrl;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getData(): string
    {
        return $this->data;
    }
    public function getPayload(): array
    {
        return json_decode($this->data, true) ?: [];
    }
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }
    public function getError(): ?string
    {
        return $this->error;
    }
}
