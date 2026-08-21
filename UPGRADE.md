# Обновление integrat/queue

## С версии 1.x на 2.0

Версия 2.0 меняет структуру исходников и публичные имена классов. Формат SQLite-базы и таблицы `jobs` не менялся, поэтому переносить данные или пересоздавать БД не нужно.

### 1. Обновите пакет

```bash
composer require integrat/queue:^2.0 -W
```

### 2. Замените импорты в коде приложения

| 1.x | 2.0 |
| --- | --- |
| `Queue\Core\Model\Job` | `Integrat\Queue\Job` |
| `Queue\Core\Service\QueueService` | `Integrat\Queue\Queue` |
| `Queue\Core\Repository\SqliteRepository` | `Integrat\Queue\Storage\SqliteJobRepository` |
| `Queue\Core\Admin\QueueDashboard` | `Integrat\Queue\Admin\QueueDashboard` |
| `Queue\Hook\HookExecutor` | `Integrat\Queue\Hook\HookExecutor` |
| `Queue\Worker\CronQueueWorker` | `Integrat\Queue\Worker\CronQueueWorker` |

Также замените имена переменных и создание главного объекта, если использовали пример из документации:

```php
use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;

$queue = new Queue(
    new SqliteJobRepository(__DIR__ . '/storage/database/queue.sqlite')
);
```

В первую очередь проверьте созданные ранее файлы:

- `webhook.php`;
- `queue.php`;
- `scripts/cron-queue-worker.php`;
- собственные обработчики, напрямую использующие классы пакета.

Команда `vendor/bin/integrat-queue install` намеренно не перезаписывает существующие файлы, поэтому она не заменит старые импорты автоматически.

### 3. Удалите разрешение старого Composer-плагина

Пакет теперь имеет тип `library`. Запись `integrat/queue` в `config.allow-plugins` больше не нужна и может быть удалена.

### 4. Проверьте приложение

```bash
vendor/bin/integrat-queue install
php scripts/cron-queue-worker.php
```

Повторный `install` должен сообщить, что все стартовые файлы уже существуют. Вебхук, админка и cron-воркер после замены импортов продолжают использовать прежнюю SQLite-базу.
