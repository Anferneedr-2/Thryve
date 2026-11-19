<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One acknowledgment per user per policy version. Policy text itself is D11 (not stored here).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('policy_version', 32);
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->unique(['user_id', 'policy_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgments');
    }
};
