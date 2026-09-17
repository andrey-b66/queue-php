<?php

declare(strict_types=1);

namespace Integrat\Queue\Tests;

use Integrat\Queue\Job;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase
{
    /**
     * create() — несохранённая незакрытая задача в статусе new с переданными source, payload и info.
     * Даты в UTC, даже если у процесса другой часовой пояс.
     */
    public function testCreateMakesNewJobWithDatesInUtc(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Moscow');

        try {
            $job = Job::create('crm', '{"order_id":42}', 'заметка');
        } finally {
            date_default_timezone_set($timezone);
        }

        $this->assertNull($job->id);
        $this->assertSame('crm', $job->source);
        $this->assertSame('{"order_id":42}', $job->payload);
        $this->assertSame(Job::STATUS_NEW, $job->status);
        $this->assertSame('заметка', $job->info);
        $this->assertNull($job->closedAt);
        $this->assertSame($job->createdAt, $job->updatedAt);
        $this->assertEqualsWithDelta(time(), strtotime($job->createdAt . ' UTC'), 5);
    }

    /** markNew() и markProcessing() открывают упавшую задачу: closedAt, result и error обнулены, info остаётся */
    public function testMarkNewAndProcessingClearPreviousRun(): void
    {
        foreach (['markNew' => Job::STATUS_NEW, 'markProcessing' => Job::STATUS_PROCESSING] as $method => $status) {
            $job = Job::create('crm', '{}', 'заметка')->markFailed('частично', 'таймаут');

            $job->$method();

            $this->assertSame($status, $job->status, $method);
            $this->assertNull($job->closedAt, $method);
            $this->assertNull($job->result, $method);
            $this->assertNull($job->error, $method);
            $this->assertSame('заметка', $job->info, $method);
        }
    }

    /**
     * markCompleted() закрывает задачу и стирает ошибку прошлого прогона. Без аргумента прежний result
     * остаётся, с аргументом — заменяется.
     */
    public function testMarkCompletedClosesJob(): void
    {
        $job = Job::create('crm', '{}')->markFailed('частично', 'таймаут');

        $job->markCompleted();

        $this->assertSame(Job::STATUS_COMPLETED, $job->status);
        $this->assertSame('частично', $job->result);
        $this->assertNull($job->error);
        $this->assertSame($job->updatedAt, $job->closedAt);

        $this->assertSame('готово', $job->markCompleted('готово')->result);
    }

    /** markFailed() закрывает задачу: статус failed, записаны result и error, closedAt равен updatedAt */
    public function testMarkFailedClosesJobWithError(): void
    {
        $job = Job::create('crm', '{}');

        $job->markFailed('частично', 'таймаут');

        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame('частично', $job->result);
        $this->assertSame('таймаут', $job->error);
        $this->assertSame($job->updatedAt, $job->closedAt);
    }
}
