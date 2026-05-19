<?php

namespace App\Services;

use App\Contracts\NotificationProvider;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationStatusEvent;
use App\Providers\Notifications\StubEmailProvider;
use App\Providers\Notifications\StubSmsProvider;
use Illuminate\Support\Facades\DB;

class NotificationProcessingService
{
    public function __construct(
        private readonly StubSmsProvider $smsProvider,
        private readonly StubEmailProvider $emailProvider,
        private readonly RabbitMqClient $rabbitMqClient,
    ) {
    }

    public function process(string $notificationId): void
    {
        $notification = DB::transaction(function () use ($notificationId) {
            $notification = Notification::query()->lockForUpdate()->findOrFail($notificationId);

            if (in_array($notification->status, [
                NotificationStatus::Delivered->value,
                NotificationStatus::Dropped->value,
            ], true)) {
                return $notification;
            }

            $notification->attempts++;
            $notification->save();

            return $notification->fresh();
        });

        if (in_array($notification->status, [
            NotificationStatus::Delivered->value,
            NotificationStatus::Dropped->value,
        ], true)) {
            return;
        }

        try {
            $result = $this->providerFor($notification)->send($notification);
            $this->markAsSent($notification, $result);
            $this->markAsDelivered($notification, $result);
        } catch (ProviderTemporaryException $exception) {
            $this->handleTemporaryFailure($notification, $exception);
        } catch (ProviderPermanentException $exception) {
            $this->markAsDropped($notification, $exception->getMessage());
        }
    }

    private function providerFor(Notification $notification): NotificationProvider
    {
        return NotificationChannel::from($notification->channel) === NotificationChannel::Sms
            ? $this->smsProvider
            : $this->emailProvider;
    }

    private function markAsSent(Notification $notification, ProviderResult $result): void
    {
        DB::transaction(function () use ($notification, $result) {
            $notification->forceFill([
                'status' => NotificationStatus::Sent->value,
                'provider_message_id' => $result->providerMessageId,
                'last_error' => null,
                'sent_at' => now(),
            ])->save();

            NotificationStatusEvent::query()->create([
                'notification_id' => $notification->id,
                'status' => NotificationStatus::Sent->value,
                'meta' => [
                    'provider_message_id' => $result->providerMessageId,
                    'provider_meta' => $result->meta,
                ],
                'created_at' => now(),
            ]);
        });
    }

    private function markAsDelivered(Notification $notification, ProviderResult $result): void
    {
        DB::transaction(function () use ($notification, $result) {
            $notification->forceFill([
                'status' => NotificationStatus::Delivered->value,
                'provider_message_id' => $result->providerMessageId,
                'delivered_at' => now(),
            ])->save();

            NotificationStatusEvent::query()->create([
                'notification_id' => $notification->id,
                'status' => NotificationStatus::Delivered->value,
                'meta' => [
                    'provider_message_id' => $result->providerMessageId,
                    'provider_meta' => $result->meta,
                ],
                'created_at' => now(),
            ]);
        });
    }

    private function handleTemporaryFailure(Notification $notification, ProviderTemporaryException $exception): void
    {
        $maxAttempts = (int) config('notifications.max_attempts');

        if ($notification->attempts >= $maxAttempts) {
            $this->markAsDropped($notification, $exception->getMessage());

            return;
        }

        $notification->forceFill([
            'last_error' => $exception->getMessage(),
        ])->save();

        $this->rabbitMqClient->retry(
            payload: json_encode(['notification_id' => $notification->id], JSON_THROW_ON_ERROR),
            priority: NotificationPriority::from($notification->priority)->amqpPriority(),
            attempt: $notification->attempts,
        );
    }

    private function markAsDropped(Notification $notification, string $error): void
    {
        DB::transaction(function () use ($notification, $error) {
            $notification->forceFill([
                'status' => NotificationStatus::Dropped->value,
                'last_error' => $error,
                'dropped_at' => now(),
            ])->save();

            NotificationStatusEvent::query()->create([
                'notification_id' => $notification->id,
                'status' => NotificationStatus::Dropped->value,
                'meta' => ['error' => $error],
                'created_at' => now(),
            ]);
        });
    }
}
