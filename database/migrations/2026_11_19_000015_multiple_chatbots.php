<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P04B. Each conversation belongs to an agent; extra agents are given to individual employees; provider connections
// leave the product (their table and the usage history pointing at them are kept, nothing new is written to them).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('description', 200)->nullable()->after('profile'); // shown to employees when they pick a chatbot
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
        // Conversations from before agents existed move to the default agent, if there is one.
        if ($default = DB::table('agents')->where('is_default', true)->whereNull('removed_at')->value('id')) {
            DB::table('conversations')->whereNull('agent_id')->update(['agent_id' => $default]);
        }

        // The default agent is open to everyone; every other agent is assigned per employee.
        Schema::create('agent_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');
            $table->unique(['agent_id', 'user_id']);
        });

        // New usage rows have no provider connection. Old rows keep theirs.
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropForeign(['provider_connection_id']);
        });
        DB::statement('ALTER TABLE usage_records MODIFY provider_connection_id BIGINT UNSIGNED NULL');
        Schema::table('usage_records', function (Blueprint $table) {
            $table->foreign('provider_connection_id')->references('id')->on('provider_connections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_user');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
