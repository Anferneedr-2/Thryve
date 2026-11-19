<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('deleted_at')->nullable(); // soft delete: hidden from the owner, kept for the retention rules (D08)
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'deleted_at', 'last_message_at'], 'conversations_owner_recent');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // user | assistant
            $table->longText('content');
            $table->string('status', 20)->default('ok'); // ok | failed
            $table->string('request_id', 64)->nullable(); // links the answer to its usage_records rows
            $table->string('rating', 10)->nullable(); // up | down
            $table->dateTime('superseded_at')->nullable(); // replaced by an edit or a regenerate; history is kept
            $table->timestamps();
            $table->index(['conversation_id', 'superseded_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
