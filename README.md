# integrat/queue

Очередь задач на SQLite: приём заданий, хранение, разбор и встроенная веб-админка.
Без внешних сервисов — нужен только PHP и файл базы.

- PHP 7.4 и выше (работает и на 8.x)
- Единственное расширение — `ext-pdo_sqlite`
- Ноль зависимостей в рантайме

## Установка

```bash
composer require integrat/queue
```

## Быстрый старт

```php
use Integrat\Queue\Job;
use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;

$repository = new SqliteJobRepository(__DIR__ . '/storage/jobs.sqlite');
$queue = new Queue($repository);

// Положить задачу в очередь
$job = $queue->push(Job::create('crm', json_encode(['order_id' => 42])));

echo $job->id;      // 1
echo $job->status;  // new
```

Файл базы и папку под него репозиторий создаёт сам. Таблица `jobs` тоже создаётся
при первом обращении.

## Разбор очереди

Задачи отдаются в порядке поступления — сначала самые старые:

```php
$jobs = $queue->find(['status' => Job::STATUS_NEW], 1, 100);

foreach ($jobs as $job) {
    $queue->update($job->markProcessing());

    try {
        $data = json_decode($job->payload, true);
        $answer = $crm->send($data);

        $queue->update($job->markCompleted($answer));
    } catch (Throwable $e) {
        $queue->update($job->markFailed(null, $e->getMessage()));
    }
}
```

Переходы задаёт сама задача, сохраняет — очередь. Каждый `mark*`-метод возвращает
задачу, поэтому смену статуса и запись удобно делать одним выражением, как выше, —
тогда про `update()` нельзя забыть, он в той же строке.

Если писать в два шага, помните: без `update()` статус поменяется только в памяти,
а в базе останется прежним.

## Задача

`Job::create()` принимает источник и payload; остальные три поля необязательные.

```php
Job::create(
    'crm',                          // source — ярлык, по которому подбирают обработчик
    json_encode(['order_id' => 42]) // payload — любая строка, обычно JSON
);

// со всеми полями
Job::create('crm', $payload, 'повторная отправка', null, null);
```

| Поле | Тип | Кто заполняет |
|---|---|---|
| `id` | `?int` | БД при вставке |
| `source` | `string` | вы |
| `payload` | `string` | вы |
| `status` | `string` | `mark*`-методы |
| `createdAt` | `string` | при создании |
| `updatedAt` | `string` | `mark*`-методы |
| `closedAt` | `?string` | `mark*`-методы |
| `info` | `?string` | вы, по желанию |
| `result` | `?string` | вы, по желанию |
| `error` | `?string` | вы, по желанию |

Статусы: `Job::STATUS_NEW`, `STATUS_PROCESSING`, `STATUS_COMPLETED`, `STATUS_FAILED`.

### Переходы

```php
$job->markProcessing();                   // в работу; result и error обнуляются
$job->markCompleted('готово');            // выполнено
$job->markFailed('частично', 'таймаут');  // провалено
```

Аргументы у всех трёх необязательные и идут в том же порядке, что и поля:
`result`, затем `error`. Каждый метод возвращает саму задачу, так что вызов
можно сразу передать в `update()`.

`info` не трогается ни одним переходом — это ваша заметка, а не след прогона.

## Выборка

```php
$queue->findById(42);                      // ?Job

$queue->find(['status' => 'failed']);      // Job[]
$queue->find(['source' => 'crm'], 2, 100); // страница 2 по 100 штук
```

Фильтры комбинируются через `AND`, пустые значения игнорируются:

| Ключ | Значение | Что делает |
|---|---|---|
| `id` | `int` | точное совпадение |
| `status` | `string` | точное совпадение |
| `source` | `string` | точное совпадение |
| `search` | `string` | подстрока в id, source, payload, status, info, result, error |
| `created_from` | `string` | `created_at >= значения` |
| `created_to` | `string` | `created_at <= значения` |
| `sort` | `ASC` / `DESC` | по умолчанию `ASC` — сначала самые старые |

Даты — строки в том же формате, в каком лежат в базе (`Y-m-d H:i:s`).
Сутки целиком задаются явно:

```php
$queue->find([
    'created_from' => '2026-09-11 00:00:00',
    'created_to'   => '2026-09-11 23:59:59',
]);
```

> Фильтр с неизвестным ключом не находит ничего. Опечатка вроде `['statuss' => 'new']`
> вернёт пустой список, а не всю таблицу.

## Удаление

```php
$queue->delete(42);  // bool
```

## Веб-админка

Отдельный файл, доступный из браузера:

```php
<?php
// public/queue-admin.php

use Integrat\Queue\Admin\QueueAdmin;
use Integrat\Queue\Admin\QueueDashboard;
use Integrat\Queue\Storage\SqliteJobRepository;

require __DIR__ . '/../vendor/autoload.php';

// Проверка прав — на вашей стороне, см. ниже
if (!$app->currentUser()->isAdmin()) {
    http_response_code(403);
    exit;
}

$repository = new SqliteJobRepository(__DIR__ . '/../storage/jobs.sqlite');

(new QueueDashboard(new QueueAdmin($repository)))->handle();
```

Умеет: фильтры по статусу, источнику, датам и подстроке; постраничный вывод;
массовую смену статуса и удаление отмеченных; удаление старых закрытых задач.

Стили по умолчанию встраиваются в страницу — это работает независимо от того,
доступна ли папка `vendor/` из веба. Если хотите отдавать CSS отдельным файлом,
чтобы он кешировался браузером, передайте его URL вторым аргументом:

```php
new QueueDashboard($admin, '/assets/queue-dashboard.css');
```

### Доступ

**`handle()` не проверяет права.** Кто открыл страницу — тот и хозяйничает,
включая массовое удаление. Закрывайте точку входа сами: проверкой своей сессии
перед вызовом, как в примере выше, либо basic-аутентификацией на веб-сервере.

Защиты от CSRF в формах тоже нет — она имеет смысл только вместе с
аутентификацией, поэтому оставлена на вашей стороне.

## Обслуживание

Удалить закрытые задачи старше 30 дней:

```php
$admin = new QueueAdmin($repository);
$deleted = $admin->deleteOldRecords(30);
```

То же самое доступно кнопкой внизу админки.

Задачи в статусах `new` и `processing` очистка **не трогает**: задача, зависшая
в `processing`, — повод разобраться, а не мусор. Найти такие можно фильтром:

```php
$stuck = $queue->find(['status' => Job::STATUS_PROCESSING]);
```

## Массовые операции

```php
$admin->setStatusMany([1, 2, 3], Job::STATUS_NEW); // вернуть в очередь
$admin->deleteMany([4, 5]);
$admin->countFiltered(['status' => 'failed']);
$admin->getSources();                              // список источников
```

При возврате в `new` или `processing` прошлые `result` и `error` обнуляются —
задача пойдёт на новый прогон, старые следы к ней не относятся.

## Что библиотека не делает

- **Не запускает воркер.** Разбор очереди — ваш скрипт по крону или демон.
- **Не захватывает задачи атомарно.** Два параллельных воркера возьмут одну и ту же
  задачу: `find(['status' => 'new'])` и `markProcessing()` — это два шага, а не один.
  Для одного воркера это неважно; для нескольких нужен свой захват.
- **Не возвращает зависшие задачи в очередь.** Если процесс умер между
  `markProcessing()` и `markCompleted()`, задача останется в `processing`
  до вашего вмешательства — так задумано, чтобы сбой был виден.

## Разработка

```bash
composer cs-check   # проверить стиль (PSR-12)
composer cs-fix     # поправить
```
