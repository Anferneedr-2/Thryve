<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable()->after('user_id');
            $table->index(['conversation_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_requests', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'status', 'expires_at']);
            $table->dropColumn('conversation_id');
        });
    }
};
