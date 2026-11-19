<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P04. Provider connections, the price list, the usage ledger and extra-token grants.
// NOT NULL times use dateTime (older MariaDB rejects several NOT NULL timestamp columns in one table).
return new class extends Migration
{
    public function up(): void
    {
        // A connection to one AI provider account. "Removing" one sets removed_at and wipes its credentials,
        // so usage history that points at it is never lost.
        Schema::create('provider_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('kind', 16); // claude | chatgpt
            $table->unsignedInteger('priority')->default(100); // lower number = tried first
            $table->boolean('is_enabled')->default(true);
            $table->string('status', 24)->default('unknown'); // unknown | available | unavailable | reauth_required | quota_exhausted
            $table->dateTime('status_checked_at')->nullable();
            $table->dateTime('unavailable_until')->nullable(); // after this the connection is tried again, in priority order
            $table->string('last_error', 200)->nullable(); // a short category, never a raw error
            $table->text('credentials')->nullable(); // encrypted; mechanism is decision D03
            $table->boolean('quota_known')->default(false); // false = the provider gave no figure; never shown as exact
            $table->unsignedBigInteger('quota_limit')->nullable();
            $table->unsignedBigInteger('quota_used')->nullable();
            $table->dateTime('quota_resets_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('removed_at')->nullable();
            $table->timestamps();
            $table->index(['is_enabled', 'removed_at', 'priority']);
        });

        // Prices are never edited: a change adds a row, so old requests keep the price they were charged at.
        Schema::create('price_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('model', 100);
            $table->decimal('input_per_million', 12, 4);
            $table->decimal('output_per_million', 12, 4);
            $table->string('currency', 3)->default('USD'); // currency is decision D05
            $table->dateTime('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['kind', 'model', 'effective_from']);
        });

        // One row per chargeable attempt. 'pending' rows reserve an estimate while a request is in flight.
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->string('attempt_id', 80)->unique(); // request id + attempt number: each attempt is recorded once
            $table->string('request_id', 64)->index();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('conversation_id')->nullable(); // conversations arrive in P05
            $table->foreignId('provider_connection_id')->constrained('provider_connections')->restrictOnDelete();
            $table->string('model', 100);
            $table->string('purpose', 16)->default('chat'); // chat | analysis (system budget, separate from employees)
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->boolean('is_estimate')->default(false); // true when the count is ours, not the provider's
            $table->string('status', 12)->default('pending'); // pending | success | failed | cancelled
            $table->string('failure', 40)->nullable();
            $table->decimal('cost', 14, 6)->nullable(); // null when no price was known
            $table->string('currency', 3)->nullable();
            $table->json('price_snapshot')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->date('work_date'); // Philippine business date the request started on
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'work_date']);
            $table->index(['purpose', 'work_date']);
            $table->index(['provider_connection_id', 'created_at']);
        });

        // Extra tokens an admin gives one person for one business day.
        Schema::create('token_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->unsignedBigInteger('tokens');
            $table->string('note', 200)->nullable();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_grants');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('price_entries');
        Schema::dropIfExists('provider_connections');
    }
};
