<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_batches', function (Blueprint $table) {
            $table->string('request_fingerprint', 64)->nullable();
        });

        $batches = DB::table('notification_batches')
            ->select(['id', 'channel', 'priority', 'message'])
            ->get();

        foreach ($batches as $batch) {
            $recipientIds = DB::table('notifications')
                ->where('notification_batch_id', $batch->id)
                ->pluck('subscriber_id')
                ->map(static fn ($recipientId) => (string) $recipientId)
                ->sort()
                ->values()
                ->all();

            $fingerprint = hash(
                'sha256',
                json_encode([
                    'channel' => $batch->channel,
                    'priority' => $batch->priority,
                    'message' => $batch->message,
                    'recipient_ids' => $recipientIds,
                ], JSON_THROW_ON_ERROR),
            );

            DB::table('notification_batches')
                ->where('id', $batch->id)
                ->update(['request_fingerprint' => $fingerprint]);
        }
    }

    public function down(): void
    {
        Schema::table('notification_batches', function (Blueprint $table) {
            $table->dropColumn('request_fingerprint');
        });
    }
};
