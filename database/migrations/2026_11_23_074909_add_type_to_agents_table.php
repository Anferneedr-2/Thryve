<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** chat = an agent employees talk to (every agent so far); report = a background analysis agent nobody chats with. */
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('type', 10)->default('chat')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
