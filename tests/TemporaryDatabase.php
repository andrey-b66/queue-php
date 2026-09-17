<?php

declare(strict_types=1);

namespace Integrat\Queue\Tests;

use Integrat\Queue\Queue;

/**
 * Очередь на настоящей SQLite-базе: у каждого теста своя пустая база во временной папке.
 */
trait TemporaryDatabase
{
    private string $dbDir;
    private Queue $queue;

    private function createDatabase(): void
    {
        $this->dbDir = sys_get_temp_dir() . '/integrat-queue-test-' . bin2hex(random_bytes(6));
        $this->queue = new Queue($this->dbDir . '/jobs.sqlite');
    }

    /**
     * Вызывать, когда других ссылок на очередь уже нет: открытый файл базы Windows удалить не даст.
     */
    private function removeDatabase(): void
    {
        unset($this->queue);

        foreach (glob($this->dbDir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dbDir);
    }
}
