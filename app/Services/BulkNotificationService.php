<?php

namespace App\Services;

use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\NotificationStatusEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BulkNotificationService
{
    public function __construct(
        private readonly RabbitMqClient $rabbitMqClient,
    ) {
    }

    public function createBatch(
        string $channel,
        string $priority,
        string $message,
        array $recipientIds,
        string $idempotencyKey,
    ): NotificationBatch {
        $notificationsToPublish = [];

        try {
            $batch = DB::transaction(function () use (
                $channel,
                $priority,
                $message,
                $recipientIds,
                $idempotencyKey,
                &$notificationsToPublish
            ) {
                $existingBatch = NotificationBatch::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingBatch) {
                    return $existingBatch->load('notifications');
                }

                $batch = NotificationBatch::query()->create([
                    'idempotency_key' => $idempotencyKey,
                    'channel' => $channel,
                    'priority' => $priority,
                    'message' => $message,
                    'total_recipients' => count($recipientIds),
                ]);

                $notifications = collect($recipientIds)->map(function (string $subscriberId) use ($batch, $channel, $priority, $message) {
                    return Notification::query()->create([
                        'notification_batch_id' => $batch->id,
                        'subscriber_id' => $subscriberId,
                        'channel' => $channel,
                        'priority' => $priority,
                        'message' => $message,
                        'status' => NotificationStatus::Queued->value,
                    ]);
                });

                $events = $notifications->map(fn (Notification $notification) => [
                    'notification_id' => $notification->id,
                    'status' => NotificationStatus::Queued->value,
                    'meta' => json_encode(['source' => 'api'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ])->all();

                NotificationStatusEvent::query()->insert($events);

                $notificationsToPublish = $notifications->all();

                return $batch->load('notifications');
            });
        } catch (QueryException) {
            return NotificationBatch::query()
                ->where('idempotency_key', $idempotencyKey)
                ->with('notifications')
                ->firstOrFail();
        }

        foreach ($notificationsToPublish as $notification) {
            $this->rabbitMqClient->publish(
                payload: json_encode(['notification_id' => $notification->id], JSON_THROW_ON_ERROR),
                priority: NotificationPriority::from($priority)->amqpPriority(),
            );
        }

        return $batch;
    }
}
