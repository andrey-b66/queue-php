# Queue

Автономный движок очереди для PHP: приём заданий → хранение в SQLite → фоновая обработка через файловые обработчики (хуки). В комплекте — встроенная веб-админка очереди.

Пакет самодостаточный и переносимый: **никаких сторонних зависимостей**, только PHP 8.1+ и `ext-pdo_sqlite`. Про конкретные интеграции ничего не знает — что делать с заданием, решает твой код в хуке.

Пакет ставится Composer'ом (`integrat/queue`). Публичные классы находятся в namespace `Integrat\Queue\`, PSR-4 указывает на каталог `src/`.

---

## Зачем это нужно

Приём и обработка развязаны очередью: точка приёма мгновенно отвечает `200`, а реальная работа идёт в фоне по крону. Это защищает от повторных доставок, таймаутов и лимитов внешних API — если обработка упала, задание останется в очереди со статусом `failed` и его можно переотправить.

- **Очередь на SQLite** — файл БД создаётся сам, миграций не требует.
- **Файловые хуки** — обработчик = один PHP-файл, никакой регистрации.
- **Фоновый воркер** по крону с защитой от параллельного запуска (`flock`).
- **Встроенная админка**: просмотр, поиск, фильтры, массовые действия над отмеченными заданиями.

---

## Состав пакета

```
vendor/integrat/queue/
├── src/
│   ├── Job.php                      # задание очереди
│   ├── Queue.php                    # главный публичный API
│   ├── Storage/
│   │   └── SqliteJobRepository.php  # хранение заданий в SQLite
│   ├── Worker/CronQueueWorker.php   # один cron-воркер и lock-файл
│   ├── Hook/HookExecutor.php        # диспетчер файловых хуков
│   ├── Admin/QueueDashboard.php     # логика и HTML админки
│   └── Installer/                   # реализация `integrat-queue install`
├── resources/
│   ├── QueueDashboard.css           # стили админки
│   └── stubs/                       # webhook, воркер, хук и админка
├── bin/integrat-queue               # явная CLI-команда установки файлов приложения
├── tests/
├── composer.json
└── README.md
```

---

## Требования

- **PHP 8.1+**
- Расширение **`pdo_sqlite`**
- Доступ к крону (для фонового воркера)

---

## Установка

```bash
composer require integrat/queue
vendor/bin/integrat-queue install
```

Автозагрузка идёт через общий `vendor/autoload.php` проекта.

`integrat/queue` — обычная Composer-библиотека. Она ничего не записывает в проект скрыто во время `composer install` / `composer update` и не требует настройки `allow-plugins`.

Команда `install` запускается явно из корня приложения. При нестандартном рабочем каталоге можно передать путь к проекту:

```bash
vendor/bin/integrat-queue install /path/to/project
```

### Обновление с версии 1.x

Версия 2.0 использует новый namespace `Integrat\Queue\` и более короткие имена публичных классов. `install` не перезаписывает файлы приложения, поэтому существующие `webhook.php`, `queue.php` и worker нужно обновить вручную по таблице в [UPGRADE.md](UPGRADE.md). После этого можно запустить `vendor/bin/integrat-queue install`: он создаст только недостающие файлы. Старую запись `integrat/queue` из `config.allow-plugins` можно удалить — пакет больше не является Composer-плагином.

---

## Файлы проекта

Пакет — это движок. Чтобы им пользоваться, в проекте нужны **четыре вещи**: точка приёма, воркер по крону, файлы-хуки и (опционально) админка.

Целевая раскладка:

```
├── webhook.php                      # 1. приём вебхуков → очередь
├── queue.php                        # 4. админка очереди (опционально)
├── hooks/
│   └── <имя>.php                    # 3. твои обработчики
├── scripts/
│   └── cron-queue-worker.php        # 2. воркер (запускается кроном)
└── storage/
    ├── database/queue.sqlite        # БД очереди (создаётся автоматически)
    └── locks/                       # файлы блокировок воркера (создаётся автоматически)
```

Собрать её можно двумя способами — запустить CLI-команду пакета или создать файлы руками. Оба приводят к одному результату: **команда кладёт ровно тот код, что расписан в разделе «Ручная установка»**, ничего сверх того.

**Ничего не будет перезаписано.** Команда создаёт только те файлы, которых ещё нет. Свои правки можно не бояться потерять: повторный `integrat-queue install` оставит их без изменений.

---

### Установка командой

После `composer require` явно запусти команду в корне приложения:

```bash
vendor/bin/integrat-queue install
```

Она развернёт рабочий набор и напечатает список созданных файлов:

```
webhook.php                       # точка приёма
queue.php                         # админка
scripts/cron-queue-worker.php     # воркер для крона
hooks/example.php                 # пример хука (?hook=example)
storage/database/                 # каталог под БД
storage/locks/                    # каталог под локи воркера
```

Путь к `vendor/autoload.php` подставляется по фактическому расположению — если в проекте переопределён `config.vendor-dir`, файлы всё равно получатся рабочими.

Остаётся сделать две вещи:

1. **Настроить крон** на `scripts/cron-queue-worker.php` — команда в шаге 2 ниже.
2. **Написать свои хуки** в `hooks/` по образцу `hooks/example.php` — контракт в шаге 3 ниже.

После этого очередь работает. Разбор каждого созданного файла — в следующем разделе.

---

### Ручная установка

Нужна, если CLI-команду использовать нельзя или файлы хочется разложить иначе. Ниже — полный код всех файлов; это же содержимое создаёт `integrat-queue install`.

Везде предполагается, что подключён composer-автолоадер проекта — `vendor/autoload.php`.

#### 1. Точка приёма — `webhook.php` (в корне)

Кладёт тело входящего запроса в очередь и сразу отвечает `200`. Имя хука берётся из URL: `?hook=<имя>`.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;

// Сырое тело запроса (JSON или form-data)
$rawData = file_get_contents('php://input') ?: '{}';
if (!empty($_POST)) {
    $rawData = json_encode($_POST);
}

$queue = new Queue(
    new SqliteJobRepository(__DIR__ . '/storage/database/queue.sqlite')
);

// $_SERVER нужен, чтобы сохранить источник и извлечь ?hook=<имя> из URL
$queue->push($_SERVER, $rawData);

http_response_code(200);
echo 'OK';
```

Внешняя система шлёт POST на `https://<хост>/webhook.php?hook=<имя>`.

#### 2. Воркер — `scripts/cron-queue-worker.php` + крон

Разбирает очередь пачками до конца, каждое задание помечает `completed` или `failed`. Одна упавшая задача не мешает остальным. `flock` не даёт двум запускам работать одновременно.

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Integrat\Queue\Worker\CronQueueWorker;

$worker = new CronQueueWorker(
    projectRoot: __DIR__ . '/../',
    databaseFile: __DIR__ . '/../storage/database/queue.sqlite',
    lockFile: __DIR__ . '/../storage/locks/cron-queue-worker.lock',
    batchSize: 50,
);

if (!$worker->acquireLock()) {
    exit(0); // предыдущий воркер ещё работает или lock-файл недоступен
}

try {
    $worker->run();
} finally {
    $worker->releaseLock();
}
```

Крон — запускать регулярно, например каждую минуту:

```cron
* * * * * /usr/bin/php /path/to/project/scripts/cron-queue-worker.php >/dev/null 2>&1
```

#### 3. Хуки — `hooks/<имя>.php`

Обработчик = один PHP-файл. Внутри доступна переменная **`$payload`** — раскодированное тело вебхука (массив). Это весь контракт.

```php
<?php
/** @var array $payload Данные вебхука из очереди */

error_log('Пришли данные: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));

// ... любая обработка $payload: вызвать свой класс, сходить во внешний API и т.д. ...
```

Имя в `?hook=` и имя файла должны **совпадать**: `?hook=order` → `hooks/order.php`. Разрешены только простые имена (буквы, цифры, `-`, `_`) — движок защищён от обхода каталога.

> **Не объявляй `const`/`function` на верхнем уровне файла хука.** За один прогон воркер может подключить один и тот же файл несколько раз (несколько заданий одного хука) — повторное `require` даст фатал переобъявления. Используй локальные переменные и замыкания.

#### 4. Админка — `queue.php` (в корне, опционально)

Тонкий лаунчер поверх `QueueDashboard`: таблица заданий, поиск, фильтры по статусу/хуку/датам, массовые действия над отмеченными заданиями.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Integrat\Queue\Admin\QueueDashboard;
use Integrat\Queue\Queue;
use Integrat\Queue\Storage\SqliteJobRepository;

$queue = new Queue(
    new SqliteJobRepository(__DIR__ . '/storage/database/queue.sqlite')
);

// Второй аргумент — папка hooks/: из неё берётся список хуков для фильтра.
// Третий (необязательный) — URL стилей; без него CSS встраивается в страницу.
(new QueueDashboard($queue, __DIR__ . '/hooks'))->handle();
```

Открывается по `https://<хост>/queue.php`.

**Массовые действия.** В таблице у каждой строки есть чекбокс, в шапке — «отметить все на странице». Отмеченные задания обрабатываются пачкой через панель «С отмеченными»: отдельные кнопки «Пометить pending» / «Пометить completed» / «Пометить failed» и «Удалить». Перед выполнением показывается подтверждение, после — счётчик затронутых заданий; фильтры и страница сохраняются. Других действий в таблице нет: переотправка одной задачи — это та же отметка `pending`.

> Смена статуса на `pending` или `completed` очищает поле `error` — оно относилось к прошлому прогону. Отметка `pending` возвращает задание воркеру, то есть равнозначна массовой переотправке (в том числе для `completed`-заданий).

> **CSS админки.** По умолчанию стили встраиваются прямо в страницу из файла пакета — работает всегда, даже если `vendor/` закрыт от веба, настраивать нечего. Если хочешь отдавать CSS отдельным файлом (чтобы он кешировался браузером), скопируй `resources/QueueDashboard.css` в веб-доступную папку и передай URL третьим аргументом:
>
> ```php
> (new QueueDashboard($queue, __DIR__ . '/hooks', '/assets/queue-dashboard.css'))->handle();
> ```

#### 5. База данных

Отдельно создавать не нужно: `SqliteJobRepository` при первом обращении **сам** создаёт файл БД и таблицу `jobs` (`CREATE TABLE IF NOT EXISTS`), а также каталог под неё. От тебя требуется лишь:

- передать путь к файлу (в примерах — `storage/database/queue.sqlite`);
- обеспечить папке `storage/` права на запись.

Для первой установки миграции не нужны. Если структура таблицы изменится в будущей версии пакета, способ обновления схемы будет указан в инструкции к этой версии.

---

## API — `Queue`

Единая точка работы с очередью. Конструктор: `new Queue(SqliteJobRepository $repository)`.

| Метод | Назначение |
| --- | --- |
| `push(array $server, string $rawData): Job` | Положить задание в очередь (статус `pending`). |
| `getPending(int $limit = 50): Job[]` | Ожидающие задания (для воркера). |
| `getFailed(int $limit = 50): Job[]` | Проваленные задания. |
| `markCompleted(Job $job): ?Job` | Пометить `completed`. |
| `markFailed(Job $job, ?string $error = null): ?Job` | Пометить `failed` с причиной. |
| `retry(int $jobId): void` | Вернуть задание в `pending`. |
| `retryAllFailed(int $limit = 50): int` | Переотправить все проваленные, вернуть их число. |
| `setStatusMany(int[] $jobIds, string $status): int` | Массово проставить статус, вернуть число изменённых. |
| `deleteMany(int[] $jobIds): int` | Массово удалить задания, вернуть число удалённых. |
| `getAll(int $page = 1, int $limit = 50): array` | Все задания (массивы) с пагинацией. |
| `findFiltered(array $filters, int $page = 1, int $limit = 50): array` | Список с фильтрами `status`/`hook`/`q`/`created_from`/`created_to`. |
| `deleteOldRecords(int $daysToKeep = 30): int` | Удалить задания старше N дней. |

Статусы задания: `pending` → `completed` / `failed`. Полезные геттеры `Job`: `getId()`, `getStatus()`, `getPayload()` (раскодированное тело как массив), `getHookName()` (имя хука из URL), `getError()`.

### Использование без хуков

Хуки — основной, но не единственный сценарий. Очередь и хранилище — общего назначения: можно класть задания через `push()` и разбирать их своим кодом, вызывая `getPending()` / `markCompleted()` / `markFailed()` напрямую, без `HookExecutor`.
