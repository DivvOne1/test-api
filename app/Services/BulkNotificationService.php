<?php

namespace App\Services;

use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\NotificationStatusEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
        $requestFingerprint = $this->makeFingerprint($channel, $priority, $message, $recipientIds);

        try {
            $batch = DB::transaction(function () use (
                $channel,
                $priority,
                $message,
                $recipientIds,
                $idempotencyKey,
                $requestFingerprint,
                &$notificationsToPublish
            ) {
                $existingBatch = NotificationBatch::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingBatch) {
                    $this->ensureMatchingFingerprint($existingBatch, $requestFingerprint);

                    return $existingBatch->load('notifications');
                }

                $batch = NotificationBatch::query()->create([
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $requestFingerprint,
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
            $existingBatch = NotificationBatch::query()
                ->where('idempotency_key', $idempotencyKey)
                ->with('notifications')
                ->firstOrFail();

            $this->ensureMatchingFingerprint($existingBatch, $requestFingerprint);

            return $existingBatch;
        }

        foreach ($notificationsToPublish as $notification) {
            $this->rabbitMqClient->publish(
                payload: json_encode(['notification_id' => $notification->id], JSON_THROW_ON_ERROR),
                priority: NotificationPriority::from($priority)->amqpPriority(),
            );
        }

        return $batch;
    }

    private function makeFingerprint(
        string $channel,
        string $priority,
        string $message,
        array $recipientIds,
    ): string {
        $normalizedRecipientIds = array_map(
            static fn ($recipientId): string => (string) $recipientId,
            $recipientIds,
        );

        sort($normalizedRecipientIds);

        return hash(
            'sha256',
            json_encode([
                'channel' => $channel,
                'priority' => $priority,
                'message' => $message,
                'recipient_ids' => $normalizedRecipientIds,
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function ensureMatchingFingerprint(NotificationBatch $batch, string $requestFingerprint): void
    {
        if ($batch->request_fingerprint !== $requestFingerprint) {
            throw new ConflictHttpException('Idempotency key was already used with different request payload.');
        }
    }
}
