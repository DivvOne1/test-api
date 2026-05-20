<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use App\Services\RabbitMqClient;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use PhpAmqpLib\Message\AMQPMessage;
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
        $this->authenticate();

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

    public function test_reusing_idempotency_key_with_different_payload_returns_conflict(): void
    {
        $this->authenticate();

        $firstPayload = [
            'channel' => 'sms',
            'priority' => 'marketing',
            'message' => 'Promo message',
            'recipient_ids' => ['user-1', 'user-2'],
            'idempotency_key' => 'bulk-1',
        ];

        $secondPayload = [
            'channel' => 'email',
            'priority' => 'transactional',
            'message' => 'OTP',
            'recipient_ids' => ['user-9'],
            'idempotency_key' => 'bulk-1',
        ];

        $this->postJson('/api/notifications/bulk', $firstPayload)->assertAccepted();

        $this->postJson('/api/notifications/bulk', $secondPayload)
            ->assertStatus(409);
    }

    public function test_worker_updates_status_history_for_successful_delivery(): void
    {
        $user = $this->authenticate();

        $response = $this->postJson('/api/notifications/bulk', [
            'channel' => 'email',
            'priority' => 'transactional',
            'message' => 'Access code 1234',
            'recipient_ids' => [(string) $user->getAuthIdentifier()],
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

        $historyResponse = $this->getJson('/api/subscribers/notifications');
        $historyResponse->assertOk()
            ->assertJsonPath('subscriber_id', (string) $user->getAuthIdentifier())
            ->assertJsonPath('notifications.0.status', NotificationStatus::Delivered->value)
            ->assertJsonMissingPath('notifications.0.message')
            ->assertJsonMissingPath('notifications.0.provider_message_id')
            ->assertJsonMissingPath('notifications.0.last_error')
            ->assertJsonPath('pagination.per_page', 50);
    }

    public function test_subscriber_history_requires_authentication(): void
    {
        $this->getJson('/api/subscribers/notifications')
            ->assertUnauthorized();
    }

    public function test_subscriber_history_supports_pagination_for_authenticated_user(): void
    {
        $user = $this->authenticate();
        $subscriberId = (string) $user->getAuthIdentifier();

        foreach (range(1, 3) as $index) {
            $batch = NotificationBatch::query()->create([
                'idempotency_key' => "history-{$index}",
                'request_fingerprint' => hash('sha256', "history-{$index}"),
                'channel' => 'sms',
                'priority' => 'marketing',
                'message' => "Message {$index}",
                'total_recipients' => 1,
            ]);

            Notification::query()->create([
                'notification_batch_id' => $batch->id,
                'subscriber_id' => $subscriberId,
                'channel' => 'sms',
                'priority' => 'marketing',
                'message' => "Message {$index}",
                'status' => NotificationStatus::Queued->value,
            ]);
        }

        $this->getJson('/api/subscribers/notifications?per_page=2')
            ->assertOk()
            ->assertJsonPath('subscriber_id', $subscriberId)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonCount(2, 'notifications');
    }

    public function test_transactional_notifications_preempt_marketing_traffic(): void
    {
        $this->authenticate();

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
        $this->authenticate();

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

    public function test_invalid_recipient_is_marked_as_dropped(): void
    {
        $user = $this->authenticate();

        $this->postJson('/api/notifications/bulk', [
            'channel' => 'sms',
            'priority' => 'transactional',
            'message' => 'Invalid destination',
            'recipient_ids' => [(string) $user->getAuthIdentifier()],
            'idempotency_key' => 'dropped-1',
        ])->assertAccepted();

        Notification::query()->update(['subscriber_id' => 'subscriber-invalid']);

        Artisan::call('notifications:consume', ['--once' => true]);

        $notification = Notification::query()->firstOrFail()->load('events');

        $this->assertSame(NotificationStatus::Dropped->value, $notification->status);
        $this->assertSame(
            ['queued', 'dropped'],
            $notification->events->pluck('status')->all()
        );
    }

    public function test_invalid_queue_payload_is_discarded_instead_of_requeued_forever(): void
    {
        $client = app(RabbitMqClient::class);
        $channel = $client->channel();

        $channel->basic_publish(
            new AMQPMessage('{broken json', [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
            ]),
            config('notifications.exchange'),
            config('notifications.routing_key'),
        );

        Artisan::call('notifications:consume', ['--once' => true]);

        $this->assertNull($channel->basic_get(config('notifications.queue'), true));

        $client->close();
    }

    private function authenticate(): User
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['notifications.read', 'notifications.write']);

        return $user;
    }
}
