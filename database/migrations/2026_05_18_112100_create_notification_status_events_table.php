<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_status_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id');
            $table->string('status', 16);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('notification_id')
                ->references('id')
                ->on('notifications')
                ->cascadeOnDelete();
            $table->index(['notification_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_status_events');
    }
};
