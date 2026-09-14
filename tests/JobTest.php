<?php

declare(strict_types=1);

namespace Integrat\Queue\Tests;

use Integrat\Queue\Job;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase
{
    public function testCreateMakesNewJob(): void
    {
        $job = Job::create('crm', '{"order_id":42}', 'заметка');

        $this->assertNull($job->id);
        $this->assertSame('crm', $job->source);
        $this->assertSame('{"order_id":42}', $job->payload);
        $this->assertSame(Job::STATUS_NEW, $job->status);
        $this->assertSame('заметка', $job->info);
        $this->assertSame($job->createdAt, $job->updatedAt);
        $this->assertNull($job->closedAt);
    }

    public function testDatesAreInUtc(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Moscow');

        try {
            $job = Job::create('crm', '{}');
        } finally {
            date_default_timezone_set($timezone);
        }

        $this->assertEqualsWithDelta(time(), strtotime($job->createdAt . ' UTC'), 5);
    }

    public function testMarkProcessingClearsPreviousRun(): void
    {
        $job = Job::create('crm', '{}', 'заметка')->markFailed('частично', 'таймаут');

        $job->markProcessing();

        $this->assertSame(Job::STATUS_PROCESSING, $job->status);
        $this->assertNull($job->closedAt);
        $this->assertNull($job->result);
        $this->assertNull($job->error);
        $this->assertSame('заметка', $job->info);
    }

    public function testMarkCompletedClosesJob(): void
    {
        $job = Job::create('crm', '{}')->markFailed(null, 'таймаут');

        $job->markCompleted('готово');

        $this->assertSame(Job::STATUS_COMPLETED, $job->status);
        $this->assertSame('готово', $job->result);
        $this->assertNull($job->error);
        $this->assertSame($job->updatedAt, $job->closedAt);
    }

    public function testMarkCompletedWithoutResultKeepsPreviousResult(): void
    {
        $job = Job::create('crm', '{}', null, 'было');

        $job->markCompleted();

        $this->assertSame('было', $job->result);
    }

    public function testMarkFailedClosesJobWithError(): void
    {
        $job = Job::create('crm', '{}');

        $job->markFailed('частично', 'таймаут');

        $this->assertSame(Job::STATUS_FAILED, $job->status);
        $this->assertSame('частично', $job->result);
        $this->assertSame('таймаут', $job->error);
        $this->assertSame($job->updatedAt, $job->closedAt);
    }

    public function testFromDatabaseRestoresToArray(): void
    {
        $row = [
            'id' => '7',
            'source' => 'crm',
            'payload' => '{}',
            'status' => Job::STATUS_COMPLETED,
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:05:00',
            'closed_at' => '2026-09-01 10:05:00',
            'info' => null,
            'result' => 'готово',
            'error' => null,
        ];

        $job = Job::fromDatabase($row);

        $this->assertSame(7, $job->id);
        $this->assertSame(['id' => 7] + $row, $job->toArray());
    }
}
