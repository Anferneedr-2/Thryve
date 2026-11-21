<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Append-only by convention; only Super Admin may edit/delete (policy D11, enforced in P02.06/P08.05).
// actor_username is a snapshot so the entry stays readable if accounts change.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_username', 64)->nullable();
            $table->string('action', 100)->index();
            $table->string('target_type', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('metadata')->nullable(); // must never contain secrets or passwords
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['target_type', 'target_id']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
