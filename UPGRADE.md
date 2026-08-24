# Обновление integrat/queue

## С версии 1.x на 2.0

Версия 2.0 несовместима с 1.x: изменились namespace, публичный API, статусы и схема таблицы `jobs`.

### 1. Обновите пакет

```bash
composer require integrat/queue:^2.0 -W
```

Пакет теперь имеет тип `library`. Старую запись `integrat/queue` из `config.allow-plugins` можно удалить.

### 2. Используйте новую SQLite-базу

Автоматической миграции старых полей `from_url`, `to_url`, `data` нет. Текущая таблица содержит:

```text
id, queue_name, source, payload, status,
created_at, updated_at, closed_at, error
```

Перед обновлением при необходимости сделайте резервную копию старой базы, затем удалите её или укажите новый путь. Новая таблица создастся автоматически.

### 3. Обновите публичные классы

| 1.x | 2.0 |
| --- | --- |
| `Queue\Core\Model\Job` | `Integrat\Queue\Job` |
| `Queue\Core\Service\QueueService` | `Integrat\Queue\Queue` |
| `Queue\Core\Repository\SqliteRepository` | `Integrat\Queue\Storage\SqliteJobRepository` |
| `Queue\Core\Admin\QueueDashboard` | `Integrat\Queue\Admin\QueueDashboard` + `QueueAdmin` |
| `Queue\Worker\CronQueueWorker` | `Integrat\Queue\Hook\HookWorker` |
| прямое использование `HookExecutor` | `HookWorker::run()` |

Статус `pending` заменён на `new`; также появился промежуточный статус `processing`.

### 4. Обновите файлы приложения

Проверьте созданные ранее файлы:

- `webhook.php` теперь создаёт `Job` и принимает `?queue=<имя>&source=<источник>`;
- `queue.php` заменён на `dashboard.php`, который создаёт `QueueAdmin` и передаёт его в `QueueDashboard`;
- `scripts/cron-queue-worker.php` заменён на `scripts/hook-worker.php`;
- `HookWorker` сам создаёт lock-файл рядом с SQLite-базой, поэтому аргумент `lockFile` больше не передаётся;
- hook-файлы получают декодированный JSON либо исходную строку в `$payload`.

Команда установки намеренно не перезаписывает существующие файлы, поэтому обновить их нужно вручную по примерам из README. После этого можно создать только нужный набор или оба сразу:

```bash
vendor/bin/integrat-queue install:dashboard # только dashboard.php
vendor/bin/integrat-queue install:hooks     # webhook, пример хука и worker
vendor/bin/integrat-queue install           # оба набора
php scripts/hook-worker.php
```
