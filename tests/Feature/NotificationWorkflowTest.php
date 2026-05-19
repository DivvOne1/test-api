<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\RabbitMqClient;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class NotificationWorkflowTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::flushdb();
        $client = app(RabbitMqClient::class);
        $channel = $client->channel();
        $channel->queue_purge(config('notifications.queue'));
        $channel->queue_purge(config('notifications.retry_queue'));
        $client->close();
    }

    public function test_bulk_request_is_idempotent(): void
    {
        $payload = [
            'channel' => 'sms',
            'priority' => 'marketing',
            'message' => 'Promo message',
            'recipient_ids' => ['user-1', 'user-2'],
            'idempotency_key' => 'bulk-1',
        ];

        $firstResponse = $this->postJson('/api/notifications/bulk', $payload);
        $secondResponse = $this->postJson('/api/notifications/bulk', $payload);

        $firstResponse->assertAccepted();
        $secondResponse->assertAccepted();
        $this->assertSame(
            $firstResponse->json('batch_id'),
            $secondResponse->json('batch_id')
        );
        $this->assertDatabaseCount('notification_batches', 1);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_worker_updates_status_history_for_successful_delivery(): void
    {
        $response = $this->postJson('/api/notifications/bulk', [
            'channel' => 'email',
            'priority' => 'transactional',
            'message' => 'Access code 1234',
            'recipient_ids' => ['subscriber-1'],
            'idempotency_key' => 'delivery-1',
        ]);

        $response->assertAccepted();

        Artisan::call('notifications:consume', ['--once' => true]);

        $notification = Notification::query()->firstOrFail()->load('events');

        $this->assertSame(NotificationStatus::Delivered->value, $notification->status);
        $this->assertSame(
            ['queued', 'sent', 'delivered'],
            $notification->events->pluck('status')->all()
        );

        $historyResponse = $this->getJson('/api/subscribers/subscriber-1/notifications');
        $historyResponse->assertOk()
            ->assertJsonPath('notifications.0.status', NotificationStatus::Delivered->value);
    }

    public function test_transactional_notifications_preempt_marketing_traffic(): void
    {
        $this->postJson('/api/notifications/bulk', [
            'channel' => 'sms',
            'priority' => 'marketing',
            'message' => 'Weekly promo',
            'recipient_ids' => ['marketing-user'],
            'idempotency_key' => 'priority-marketing',
        ])->assertAccepted();

        $this->postJson('/api/notifications/bulk', [
            'channel' => 'sms',
            'priority' => 'transactional',
            'message' => 'Critical OTP',
            'recipient_ids' => ['critical-user'],
            'idempotency_key' => 'priority-transactional',
        ])->assertAccepted();

        Artisan::call('notifications:consume', ['--once' => true]);

        $critical = Notification::query()->where('subscriber_id', 'critical-user')->firstOrFail();
        $marketing = Notification::query()->where('subscriber_id', 'marketing-user')->firstOrFail();

        $this->assertSame(NotificationStatus::Delivered->value, $critical->status);
        $this->assertSame(NotificationStatus::Queued->value, $marketing->status);

        Artisan::call('notifications:consume', ['--once' => true]);

        $this->assertSame(
            NotificationStatus::Delivered->value,
            $marketing->fresh()->status
        );
    }

    public function test_temporary_failures_are_retried_and_eventually_delivered(): void
    {
        $this->postJson('/api/notifications/bulk', [
            'channel' => 'email',
            'priority' => 'transactional',
            'message' => 'Retry me',
            'recipient_ids' => ['subscriber-retry'],
            'idempotency_key' => 'retry-1',
        ])->assertAccepted();

        Artisan::call('notifications:consume', ['--once' => true]);

        $notification = Notification::query()->firstOrFail();
        $this->assertSame(NotificationStatus::Queued->value, $notification->status);
        $this->assertSame(1, $notification->attempts);

        sleep(2);
        Artisan::call('notifications:consume', ['--once' => true]);

        $notification = $notification->fresh()->load('events');
        $this->assertSame(NotificationStatus::Delivered->value, $notification->status);
        $this->assertSame(2, $notification->attempts);
        $this->assertSame(
            ['queued', 'sent', 'delivered'],
            $notification->events->pluck('status')->all()
        );
    }
}
