<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per recipient. dedupe_key makes "the same alert for the same window" happen once per person
        // (NULLs are allowed many times, so un-keyed notifications are never blocked).
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 64);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at', 'id']);
        });

        // Delivery attempts per channel. Email failures are recorded here and never affect the in-app notification.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16); // queued | sent | failed | skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 200)->nullable(); // a short category, never a raw exception or credentials
            $table->dateTime('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['notification_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
    }
};
