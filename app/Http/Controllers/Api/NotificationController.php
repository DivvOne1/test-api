<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBulkNotificationRequest;
use App\Models\Notification;
use App\Services\BulkNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly BulkNotificationService $bulkNotificationService,
    ) {
    }

    public function store(StoreBulkNotificationRequest $request): JsonResponse
    {
        $idempotencyKey = $request->input('idempotency_key')
            ?: $request->header('Idempotency-Key');

        abort_unless($idempotencyKey, 422, 'Idempotency key is required.');

        $batch = $this->bulkNotificationService->createBatch(
            channel: $request->string('channel')->toString(),
            priority: $request->string('priority')->toString(),
            message: $request->string('message')->toString(),
            recipientIds: $request->collect('recipient_ids')->map(fn ($id) => (string) $id)->all(),
            idempotencyKey: $idempotencyKey,
        );

        return response()->json([
            'batch_id' => $batch->id,
            'idempotency_key' => $batch->idempotency_key,
            'channel' => $batch->channel,
            'priority' => $batch->priority,
            'total_recipients' => $batch->total_recipients,
            'notification_ids' => $batch->notifications()->pluck('id'),
        ], 202);
    }

    public function subscriberHistory(Request $request, string $subscriberId): JsonResponse
    {
        $notifications = Notification::query()
            ->with(['events', 'batch'])
            ->where('subscriber_id', $subscriberId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'subscriber_id' => $subscriberId,
            'notifications' => $notifications->map(function (Notification $notification) {
                return [
                    'notification_id' => $notification->id,
                    'batch_id' => $notification->notification_batch_id,
                    'channel' => $notification->channel,
                    'priority' => $notification->priority,
                    'message' => $notification->message,
                    'status' => $notification->status,
                    'attempts' => $notification->attempts,
                    'provider_message_id' => $notification->provider_message_id,
                    'last_error' => $notification->last_error,
                    'events' => $notification->events->map(fn ($event) => [
                        'status' => $event->status,
                        'meta' => $event->meta,
                        'created_at' => optional($event->created_at)->toIso8601String(),
                    ]),
                    'created_at' => optional($notification->created_at)->toIso8601String(),
                    'updated_at' => optional($notification->updated_at)->toIso8601String(),
                ];
            }),
        ]);
    }
}
