# Queue

Автономная очередь для PHP 8.1+: хранение заданий в SQLite, обработка файловыми хуками и встроенная веб-админка. Runtime-зависимостей, кроме `ext-pdo_sqlite`, нет.

Пакет предоставляет три отдельные точки входа:

| Сценарий | Публичный класс | Назначение | Установка |
| --- | --- | --- | --- |
| Dashboard | `Integrat\Queue\Admin\QueueDashboard` | Просмотр, фильтры и массовые действия | `install:dashboard` |
| Готовая обработка хуками | `Integrat\Queue\Hook\HookWorker` | Полная цепочка `new → processing → hook → completed/failed` | `install:hooks` |
| Кастомная работа | `Integrat\Queue\Queue` | Пользователь сам управляет заданиями | Не требуется |

## Требования

- PHP 8.1 или новее;
- расширение `pdo_sqlite`;
- права на запись в каталог с SQLite-базой.

## Установка

```bash
composer require integrat/queue
```

После установки выберите нужный набор файлов:

| Команда | Создаваемые файлы |
| --- | --- |
| `vendor/bin/integrat-queue install:dashboard` | `dashboard.php` |
| `vendor/bin/integrat-queue install:hooks` | `webhook.php`, `hooks/example.php`, `scripts/hook-worker.php` |
| `vendor/bin/integrat-queue install` | Dashboard и hooks вместе |

Каждая команда также создаёт общие `storage/.gitignore` и `storage/database/`. Существующие файлы никогда не перезаписываются, поэтому команды можно безопасно запускать повторно и комбинировать.

Для кастомной работы через `Queue` генерация файлов не нужна. Если команда запускается не из корня приложения, добавьте путь последним аргументом:

```bash
vendor/bin/integrat-queue install:hooks /path/to/project
```

## 1. Готовая обработка хуками

### Приём задания

Сгенерированный `webhook.php` принимает тело запроса и кладёт его в очередь. Имя очереди определяет PHP-файл, который будет вызван worker'ом:

```text
POST /webhook.php?queue=example&source=amoCRM
```

Минимальный код:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Integrat\Queue\Job;
use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;

$payload = file_get_contents('php://input') ?: '{}';
$queueName = (string) ($_GET['queue'] ?? '');
$source = (string) ($_GET['source'] ?? 'unknown');

$queue = new Queue(
    new SqliteJobRepository(__DIR__ . '/storage/database/queue.sqlite')
);

$queue->push(Job::create($queueName, $source, $payload));

http_response_code(200);
echo 'OK';
```

### Файл хука

Для очереди `example` worker запускает `hooks/example.php`. В файле доступна переменная `$payload`:

```php
<?php

/** @var mixed $payload */

error_log(json_encode($payload, JSON_UNESCAPED_UNICODE));
```

Корректный JSON автоматически декодируется. Если тело не является JSON, `$payload` содержит исходную строку. В имени очереди разрешены буквы, цифры, `_` и `-`.

Хук по желанию может вернуть результат выполнения — он сохранится в поле `result` задачи и будет виден в дашборде:

```php
<?php

/** @var mixed $payload */

return 'Создано сделок: 12';
```

Возврат необязателен: файл без `return` оставляет `result` пустым. Массив или объект сохраняются как JSON.

Не объявляй глобальные функции или константы в hook-файле: за один запуск он может подключаться несколько раз.

### Запуск worker'а

Пользователь работает только с одной точкой входа — `HookWorker`. Поиск файла и выполнение хука скрыты внутри:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Integrat\Queue\Hook\HookWorker;

$worker = new HookWorker(
    databaseFile: __DIR__ . '/../storage/database/queue.sqlite',
    hooksDirectory: __DIR__ . '/../hooks',
    batchSize: 50,
);

$processed = $worker->run();
```

`run()` возвращает количество обработанных заданий. `flock` не позволяет двум worker-процессам разбирать очередь одновременно. Lock-файл создаётся и используется библиотекой автоматически рядом с БД: `queue.sqlite.hook-worker.lock`.

Worker можно запускать вручную, кнопкой, планировщиком или cron:

```cron
* * * * * /usr/bin/php /path/to/project/scripts/hook-worker.php >/dev/null 2>&1
```

## 2. Dashboard

Команда `install:dashboard` создаёт веб-точку входа `dashboard.php`. Административные операции отделены от пользовательского `Queue` и находятся в `QueueAdmin`, а страницу отображает `QueueDashboard`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Integrat\Queue\Admin\QueueAdmin;
use Integrat\Queue\Admin\QueueDashboard;
use Integrat\Queue\Storage\SqliteJobRepository;

$admin = new QueueAdmin(
    new SqliteJobRepository(__DIR__ . '/storage/database/queue.sqlite')
);

(new QueueDashboard($admin))->handle();
```

Dashboard показывает поля новой модели, фильтрует по статусу, имени очереди и датам. Строковый поиск охватывает источник, payload, ошибку, результат и остальные основные поля. Доступны массовая смена статуса и удаление.

По умолчанию CSS встраивается из ресурсов пакета. Для отдельного CSS-файла передай URL вторым аргументом:

```php
(new QueueDashboard($admin, '/assets/queue-dashboard.css'))->handle();
```

## 3. Кастомная работа

`Queue` ничего не знает об HTML и файлах хуков. Он принимает и возвращает объекты `Job`:

```php
use Integrat\Queue\Job;
use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;

$queue = new Queue(new SqliteJobRepository($databaseFile));

$job = $queue->push(Job::create(
    queueName: 'amo-events',
    source: 'amoCRM',
    payload: '{"contact_id":42}',
));

$newJobs = $queue->findNew(page: 1, limit: 50);

$queue->markProcessing($job);

try {
    // Пользовательская обработка.
    $queue->markCompleted($job, 'Создано сделок: 12');
} catch (Throwable $exception) {
    $queue->markFailed($job, $exception->getMessage());
}

$queue->delete($job);
```

Поле `result` — необязательное. Оно живёт рядом с `error`: ошибка описывает провал, результат — что именно сделала успешная (или частично успешная) обработка. Заполнять его можно при завершении задачи или сразу при создании:

```php
Job::create('amo-events', 'amoCRM', '{"contact_id":42}', result: 'Поставлено в план');

$queue->markCompleted($job, 'Создано сделок: 12');
$queue->markFailed($job, $exception->getMessage(), 'Успели обработать 3 из 10');
```

Возврат задачи в `new` или `processing` через дашборд очищает `result` — задача будет выполняться заново.

Основные методы `Queue`:

| Метод | Назначение |
| --- | --- |
| `push(Job $job): Job` | Добавить задание |
| `findById(int $jobId): ?Job` | Получить задание по ID |
| `findByQueueName(string $name, int $page = 1, int $limit = 50): Job[]` | Задания конкретной очереди |
| `findNewByQueueName(string $name, int $page = 1, int $limit = 50): Job[]` | Только новые задания конкретной очереди, от старых к новым |
| `findNew(...)`, `findProcessing(...)`, `findCompleted(...)`, `findFailed(...)` | Выборки по статусу |
| `markProcessing(Job $job): ?Job` | Начать обработку |
| `markCompleted(Job $job, ?string $result = null): ?Job` | Завершить успешно, по желанию записав результат |
| `markFailed(Job $job, ?string $error = null, ?string $result = null): ?Job` | Завершить с ошибкой |
| `delete(int\|Job $job): bool` | Удалить одно задание |

## Статусы

```text
new → processing → completed
                 ↘ failed
```

- `new` — ожидает обработки;
- `processing` — обработка началась;
- `completed` — успешно завершено;
- `failed` — завершено с ошибкой.

## SQLite

`SqliteJobRepository` сам создаёт каталог, файл БД и таблицу `jobs`:

```text
id, queue_name, source, payload, status,
created_at, updated_at, closed_at, error, result
```

Текущая версия рассчитана на новую чистую схему. Автоматическая миграция старых таблиц с `from_url`, `to_url` и `data` не выполняется.

## Структура пакета

```text
src/
├── Job.php
├── Queue.php
├── Storage/SqliteJobRepository.php
├── Hook/
│   ├── HookWorker.php
│   └── HookExecutor.php
├── Admin/
│   ├── QueueAdmin.php
│   └── QueueDashboard.php
└── Installer/

resources/
├── QueueDashboard.css
└── stubs/

bin/integrat-queue
```

Инструкция по несовместимым изменениям находится в [UPGRADE.md](UPGRADE.md).
