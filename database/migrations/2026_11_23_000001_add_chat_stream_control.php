<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('status', 16)->default('running');
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
        });
        Schema::table('messages', fn (Blueprint $table) => $table->string('completion_status', 16)->nullable());
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('completion_status'));
        Schema::dropIfExists('chat_requests');
    }
};
