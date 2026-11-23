<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per conversation, message version and rubric version. A row is a stored result (valid), a failure that
     * may be retried (failed) or a conversation the agent could not assess (unassessable). Failed work is never a score.
     */
    public function up(): void
    {
        Schema::create('conversation_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // the conversation's owner, kept here so reports need no join
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rubric_version', 10);
            $table->char('version_hash', 64); // the current messages that were analyzed
            $table->unsignedInteger('message_count');
            $table->unsignedInteger('employee_message_count');
            $table->string('status', 16); // valid | failed | unassessable
            $table->string('error', 32)->nullable(); // a short category, never raw provider text
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('task_label', 120)->nullable();
            $table->json('topic_ids')->nullable();
            $table->unsignedTinyInteger('clarity')->nullable();
            $table->unsignedTinyInteger('context')->nullable();
            $table->unsignedTinyInteger('specifics')->nullable();
            $table->unsignedTinyInteger('refinement')->nullable(); // empty when the conversation had a single employee message
            $table->unsignedTinyInteger('verification')->nullable();
            $table->unsignedTinyInteger('dimensions_scored')->nullable(); // how many scores the average uses (5, or 4 without refinement)
            $table->decimal('average', 3, 2)->nullable(); // computed here, never taken from the agent
            $table->string('tip', 300)->nullable();
            $table->string('request_id', 64)->nullable(); // links to the usage ledger row
            $table->dateTime('analyzed_at')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'version_hash', 'rubric_version'], 'analyses_one_per_version');
            $table->index(['user_id', 'status', 'analyzed_at']);
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_analyses');
    }
};
