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
  "recipient_ids": ["user-1", "user-2"],
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

### GET `/api/subscribers/{subscriberId}/notifications`

Возвращает историю и текущий статус всех уведомлений подписчика.

Пример:

```bash
curl http://localhost:8000/api/subscribers/user-1/notifications
```

## Swagger / OpenAPI

OpenAPI-описание лежит в репозитории:

- [`public/docs/openapi.yaml`](/C:/Users/DivvOne/Documents/New%20project%206/public/docs/openapi.yaml)

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
