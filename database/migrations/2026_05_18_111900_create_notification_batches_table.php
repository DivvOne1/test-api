<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key')->unique();
            $table->string('channel', 16);
            $table->string('priority', 32);
            $table->text('message');
            $table->unsignedInteger('total_recipients');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_batches');
    }
};
