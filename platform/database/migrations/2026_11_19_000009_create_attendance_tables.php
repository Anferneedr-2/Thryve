<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Attendance (P03). Everything is stored in UTC. NOT NULL columns use dateTime, because older MariaDB (10.4) rejects several
// NOT NULL timestamp columns in one table; work_date is the Philippine (Asia/Manila) business date.
return new class extends Migration
{
    public function up(): void
    {
        // One row per sign-in period. A session ends on logout, browser close, timeout, replacement or deactivation.
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('last_activity_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'ended_at']);
        });

        // The time ledger: non-overlapping active/idle stretches, already split at Philippine midnight.
        Schema::create('attendance_intervals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->string('kind', 8); // active | idle
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->unsignedInteger('seconds');
            $table->index(['user_id', 'work_date']);
        });

        // Official daily record: exactly one per user per Philippine date.
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('computed_active_seconds')->default(0);
            $table->unsignedInteger('computed_idle_seconds')->default(0);
            $table->timestamp('computed_first_in_at')->nullable();
            $table->timestamp('computed_last_out_at')->nullable();
            // Admin corrections replace the computed value; NULL means "use the computed one".
            $table->unsignedInteger('active_seconds_override')->nullable();
            $table->timestamp('first_in_override')->nullable();
            $table->timestamp('last_out_override')->nullable();
            $table->boolean('is_corrected')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
            $table->index('work_date');
        });

        // Who changed what, and when. No notification is ever sent for these.
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_day_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // the employee the record belongs to
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16); // create | override
            $table->json('before')->nullable();
            $table->json('after');
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_intervals');
        Schema::dropIfExists('work_sessions');
    }
};
