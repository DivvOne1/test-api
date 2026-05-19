<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_batch_id');
            $table->string('subscriber_id');
            $table->string('channel', 16);
            $table->string('priority', 32);
            $table->text('message');
            $table->string('status', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();

            $table->foreign('notification_batch_id')
                ->references('id')
                ->on('notification_batches')
                ->cascadeOnDelete();
            $table->unique(['notification_batch_id', 'subscriber_id']);
            $table->index(['subscriber_id', 'created_at']);
            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
