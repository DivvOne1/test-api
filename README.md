# Notification Service

Микросервис массовых уведомлений на `Laravel 10`, который поддерживает:

- массовую отправку SMS и Email;
- приоритеты `transactional` и `marketing`;
- статусы `queued`, `sent`, `delivered`, `dropped`;
- retry при временных ошибках провайдера;
- идемпотентность на уровне API;
- интеграционные тесты с реальными `PostgreSQL + RabbitMQ + Redis`.

## Архитектура

- `Laravel API` принимает массовый запрос и сохраняет `batch + notifications + status events`.
- `RabbitMQ` хранит персистентную очередь и использует приоритеты сообщений.
- `Worker` читает сообщения из `notifications.send` и вызывает provider stubs.
- `Redis` используется для дедупликации провайдерских вызовов и имитации exactly-once на уровне бизнес-логики.
- `PostgreSQL` хранит текущий статус уведомлений и их историю.

## Быстрый старт

1. Убедись, что установлен Docker и Docker Compose.
2. Запусти проект:

```bash
docker compose up --build
```

3. API будет доступен по адресу:

```text
http://localhost:8000
```

4. RabbitMQ Management UI:

```text
http://localhost:15672
```

Логин и пароль RabbitMQ по умолчанию: `guest / guest`.

Примечание: миграции выполняет контейнер `app`. Контейнер `worker` ждет, пока схема БД уже будет подготовлена, чтобы избежать гонки на старте.

## Как запустить тесты

После старта инфраструктуры выполни:

```bash
docker compose stop app worker
docker compose --profile tests run --rm tests
```

Тесты проверяют:

- идемпотентность массового запроса;
- полную цепочку доставки до статуса `delivered`;
- приоритетную обработку `transactional` уведомлений;
- retry-механику при временных ошибках провайдера.

После прогона тестов сервисы можно вернуть командой:

```bash
docker compose up -d app worker
```

## API

### POST `/api/notifications/bulk`

Запускает массовую отправку уведомлений.

Пример запроса:

```json
{
  "channel": "sms",
  "priority": "transactional",
  "message": "Your OTP is 1234",
  "recipient_ids": ["1", "2"],
  "idempotency_key": "otp-batch-001"
}
```

Альтернативно `idempotency_key` можно передать заголовком `Idempotency-Key`.

Пример ответа:

```json
{
  "batch_id": "6d8c7a77-3f61-4fe2-94f2-9f3b5f7dd3a7",
  "idempotency_key": "otp-batch-001",
  "channel": "sms",
  "priority": "transactional",
  "total_recipients": 2,
  "notification_ids": [
    "8e996baf-5c31-4cdc-8b06-6f2f2803e19c",
    "028ca66e-a78d-4f9f-b3b6-5d2356f35e8f"
  ]
}
```

### GET `/api/subscribers/notifications`

Возвращает пагинированную историю и текущий статус уведомлений текущего аутентифицированного пользователя.

Требует `Authorization: Bearer <token>`.

Пример:

```bash
curl \
  -H "Authorization: Bearer <token>" \
  "http://localhost:8000/api/subscribers/notifications?per_page=20"
```

При повторном использовании одного `idempotency_key`:

- тот же payload -> вернется существующий batch;
- другой payload -> `409 Conflict`.

## Auth For Local Testing

Для demo API использует `Laravel Sanctum`.

Создай тестового пользователя и token:

```bash
docker compose exec app php artisan tinker
```

```php
$user = \App\Models\User::firstOrCreate(
    ['email' => 'user-1@example.com'],
    ['name' => 'User 1', 'password' => bcrypt('password')]
);

$token = $user->createToken('local')->plainTextToken;
```

Используй полученный token:

```text
Authorization: Bearer <token>
```

Для ручной проверки истории используй `recipient_ids`, совпадающие с `users.id`.
Например, если token создан для пользователя с `id = 1`, то в bulk-запросе передавай `"recipient_ids": ["1"]`.

Unexpected worker errors are rejected without requeue to avoid poison-message loops. In production they should be routed to DLQ for investigation.

## Swagger / OpenAPI

OpenAPI-описание лежит в репозитории:

- [`public/docs/openapi.yaml`](/C:/Users/DivvOne/PhpstormProjects/test-api/public/docs/openapi.yaml)

После запуска через Docker файл будет доступен по ссылке:

```text
http://localhost:8000/docs/openapi.yaml
```

## Поведение provider stubs

Для демонстрации сценариев:

- получатель с `invalid` в `subscriber_id` приводит к `dropped`;
- получатель с `retry` в `subscriber_id` один раз падает временной ошибкой и затем доставляется успешно.

## Основные директории

- `app/Http/Controllers/Api` — HTTP API.
- `app/Services` — бизнес-логика, RabbitMQ и обработка доставки.
- `app/Providers/Notifications` — SMS/Email заглушки.
- `app/Console/Commands` — worker-команда.
- `tests/Feature` — интеграционные тесты.
