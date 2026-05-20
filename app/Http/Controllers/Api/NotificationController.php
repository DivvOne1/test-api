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

    public function subscriberHistory(Request $request): JsonResponse
    {
        $subscriberId = (string) $request->user()->getAuthIdentifier();
        $perPage = min(max($request->integer('per_page', 50), 1), 100);

        $notifications = Notification::query()
            ->with('batch')
            ->where('subscriber_id', $subscriberId)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'subscriber_id' => $subscriberId,
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
            'notifications' => $notifications->getCollection()->map(function (Notification $notification) {
                return [
                    'notification_id' => $notification->id,
                    'batch_id' => $notification->notification_batch_id,
                    'channel' => $notification->channel,
                    'priority' => $notification->priority,
                    'status' => $notification->status,
                    'attempts' => $notification->attempts,
                    'created_at' => optional($notification->created_at)->toIso8601String(),
                    'updated_at' => optional($notification->updated_at)->toIso8601String(),
                ];
            }),
        ]);
    }
}
