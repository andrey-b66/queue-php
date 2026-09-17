# integrat/queue

Очередь задач на SQLite с воркером и веб-админкой. Внешние сервисы не нужны: только PHP и файл базы.

- PHP 7.4 или 8.x, расширения `pdo_sqlite` и `json`
- Без зависимостей

## Установка

```bash
composer require integrat/queue
```

## Как это устроено

Сайт кладёт задачу в очередь и сразу отвечает. Воркер, запущенный кроном, выполняет новые задачи
обработчиками, зарегистрированными для их `source`. В админке видно, что пришло, что выполнено
и что упало.

Файл базы, папку под него и таблицу очередь создаёт сама. Сайт и воркер пишут в один файл,
поэтому запускайте воркер от того же пользователя, что и PHP сайта. Держите файл базы вне папки,
доступной из веба: в нём лежат данные задач.

## Приём задач

```php
<?php
// public/webhooks/order-created.php — адрес для вебхука

use Integrat\Queue\Job;
use Integrat\Queue\Queue;

require __DIR__ . '/../../vendor/autoload.php';

$queue = new Queue(__DIR__ . '/../../storage/jobs.sqlite');

$queue->push(Job::create(
    'order-created',                   // source: по нему воркер выберет обработчик
    file_get_contents('php://input'),  // payload: данные строкой, например {"order_id": 42}
    'IP ' . $_SERVER['REMOTE_ADDR']    // info: необязательная заметка, видна в админке
));

// Ответ сразу, не дожидаясь выполнения задачи
http_response_code(202);
```

## Пример обработчика

```php
<?php
// worker.php — разбирает очередь. Например, крон запускает его раз в минуту:
//
//   * * * * * php /path/to/worker.php

use App\ApiClient;
use App\Handlers\SendInvoice;
use App\Handlers\SyncOrder;
use Integrat\Queue\Job;
use Integrat\Queue\Queue;
use Integrat\Queue\Worker;

require __DIR__ . '/vendor/autoload.php';

// Только из консоли: открытый в браузере файл ничего не запустит
if (PHP_SAPI !== 'cli') {
    exit;
}

$api = new ApiClient(getenv('API_TOKEN'));
$worker = new Worker(new Queue(__DIR__ . '/storage/jobs.sqlite'), __DIR__ . '/storage/worker.lock');

// source задачи => обработчик
$handlers = [
    'order-created' => new SyncOrder($api),
    'invoice'       => new SendInvoice($api),
    'crm'           => fn (Job $job) => sendOrderToCrm(json_decode($job->payload, true)),
    'mail'          => fn (Job $job) => sendMail(json_decode($job->payload, true)),
];

foreach ($handlers as $source => $handler) {
    $worker->register($source, $handler);
}

$worker->run();
```

`ApiClient`, `SyncOrder`, `SendInvoice`, `sendOrderToCrm()` и `sendMail()` — ваш код. Обработчик —
объект с методом `__invoke(Job $job)` или функция; то, что он вернёт, запишется в `result`.

`run()` выполняет новые задачи в порядке поступления и завершается:

- обработчик вернул строку, число или `null` — задача `completed`, значение записано в `result`;
- обработчик бросил исключение — задача `failed`, текст исключения записан в `error`;
- для `source` задачи нет обработчика — задача тоже `failed`.

Если прошлый запуск ещё работает, новый видит файл блокировки и сразу выходит, так что две копии
воркера одну задачу не возьмут.

## Статусы

| Статус | Что значит |
|---|---|
| `new` | задача ждёт воркера |
| `processing` | воркер её выполняет |
| `completed` | выполнена, в `result` — что вернул обработчик |
| `failed` | упала, в `error` — текст ошибки |

## Выборка

```php
$queue->findById(42);                                         // ?Job
$queue->find(['status' => Job::STATUS_FAILED], 1, 20, true);  // Job[]: 20 последних упавших
$queue->count(['status' => Job::STATUS_FAILED]);              // сколько всего упавших
$queue->find(['errorContains' => 'Таймаут']);                 // Job[]: в ошибке есть «Таймаут»
$queue->find([], 1, 20, true, 'closedAt');                    // Job[]: 20 последних закрытых
$queue->listSources();                                        // string[]: источники по алфавиту
```

`find()` и `count()` отбирают задачи, которые подходят под все заданные условия. Пустая строка
и `null` значат «условие не задано», неизвестное условие — `InvalidArgumentException`.

| Условие | Что проверяет |
|---|---|
| `status` | статус, точное совпадение |
| `source` | источник, точное совпадение |
| `createdFrom`, `createdTo` | `created_at` не раньше и не позже, включительно |
| `infoContains`, `resultContains`, `errorContains` | текст в `info`, `result` или `error` |
| `payloadContains` | текст в `payload` |

После фильтра `find()` принимает `$page = 1`, `$limit = 50`, `$newestFirst = false` и `$sortBy = 'id'`:
по умолчанию задачи идут в порядке поступления. Сортировать можно ещё по `createdAt`, `updatedAt`
и `closedAt`, неизвестное поле — `InvalidArgumentException`. Задачи с одинаковым временем идут по id,
поэтому при листании ни одна не теряется и не повторяется. Задачи без `closedAt` при сортировке
по нему оказываются в начале списка «сначала старые» и в конце списка «сначала новые».

Даты — строки `Y-m-d H:i:s` в UTC: библиотека пишет время в UTC независимо от `date.timezone`.
Текст ищется с учётом регистра. Он находится и в том виде, в каком его экранирует `json_encode()`
(кириллица, слеши, JSON строкой), но пробелы ищутся буквально: `"id": 42`, скопированное
из админки, не найдёт записанное `"id":42`.

## Повтор и удаление

```php
$queue->mark([1, 2, 3], Job::STATUS_NEW);  // вернуть в очередь
$queue->delete([4, 5]);
$queue->deleteOldRecords(30);              // закрытые больше 30 дней назад
```

`mark()` и `delete()` возвращают, сколько задач изменено или удалено. Возврат в `new` стирает
`result` и `error` прошлого прогона. `deleteOldRecords()` считает срок от закрытия и не трогает
задачи в `new` и `processing`. Массовые операции идут по одной задаче и без транзакции:
не запускайте их над задачами, которые в этот момент выполняет воркер.

## Веб-админка

```php
<?php
// public/queue-admin.php

use Integrat\Queue\Dashboard;
use Integrat\Queue\Queue;

require __DIR__ . '/../vendor/autoload.php';

// Проверка прав — на вашей стороне, см. «Доступ»
if (!$app->currentUser()->isAdmin()) {
    http_response_code(403);
    exit;
}

$queue = new Queue(__DIR__ . '/../storage/jobs.sqlite');

(new Dashboard($queue))->handle();
```

Фильтры по статусу, источнику, периоду и тексту в info, result, error и payload в любом сочетании;
сортировка по id, created_at, updated_at и closed_at кликом по заголовку столбца; постраничный вывод,
массовая смена статуса и удаление, очистка старых закрытых задач.
Стили встроены в страницу, отдельно публиковать ничего не нужно; в браузере должен работать
JavaScript.

Время по умолчанию показывается в UTC. Чтобы видеть местное время и выбирать даты фильтра по нему,
передайте часовой пояс:

```php
new Dashboard($queue, 'Europe/Moscow');
```

### Доступ

**`handle()` и `process()` не проверяют права.** Кто открыл страницу, тот может и удалить все
задачи. Закрывайте адрес сами: проверкой сессии перед вызовом, как в примере, или
basic-аутентификацией на веб-сервере.

### Во фреймворке

`handle()` сам отправляет ответ. Во фреймворке, где контроллер возвращает объект ответа, используйте
`process()`: он принимает данные запроса и возвращает статус, заголовки и HTML. Пример для Laravel:

```php
use Illuminate\Http\Request;
use Integrat\Queue\Dashboard;
use Integrat\Queue\Queue;

// Проверка прав — на вашей стороне, см. «Доступ»
Route::match(['get', 'post'], '/queue-admin', function (Request $request) {
    $dashboard = new Dashboard(new Queue(storage_path('queue/jobs.sqlite')));

    $response = $dashboard->process(
        $request->query->all(),
        $request->request->all(),
        $request->server->all(),
        $request->cookies->all()
    );

    return response($response['body'], $response['status'], $response['headers']);
})->middleware('auth');
```

У админки своя защита от CSRF, поэтому её маршрут нужно добавить в исключения CSRF-проверки
фреймворка, иначе фреймворк отклонит все действия в админке.

## Ошибки

Библиотека бросает стандартные исключения:

| Класс | Когда |
|---|---|
| `PDOException` | сбой базы: файл не открылся, база занята дольше 5 секунд, нет прав на запись |
| `RuntimeException` | не удалось создать папку под базу или открыть файл блокировки воркера |
| `InvalidArgumentException` | неизвестный статус, условие фильтра или поле сортировки, страница или лимит меньше 1, второй обработчик для того же `source` |
| `Exception` | неизвестный часовой пояс админки — сразу в конструкторе `Dashboard` |

Исключение из обработчика воркер не выбрасывает, а записывает в `error` задачи. Ошибку действия
в админке (смена статуса, удаление, очистка) страница показывает сообщением. Из `handle()`
и `process()` выбрасывается только ошибка базы при загрузке страницы.

## Ограничения

- **Один воркер за раз.** Задачи берутся в работу без атомарного захвата, поэтому параллельный
  запуск воркера не пускает файл блокировки. Не разбирайте ту же очередь другим способом, пока
  работает воркер.
- **Прерванная задача остаётся в `processing`.** Если процесс воркера упал или был убит посреди
  задачи, она не вернётся в очередь сама — так сбой видно. Вернуть её можно `mark()`
  или кнопкой в админке.
- **Нет автоповтора.** Упавшая задача остаётся в `failed`, пока её не вернут в очередь.

## Разработка

```bash
composer test       # тесты
composer cs-check   # проверить стиль (PSR-12)
composer cs-fix     # поправить стиль
composer demo       # демо-стенд: http://localhost:8000, админка — /admin.php
```
