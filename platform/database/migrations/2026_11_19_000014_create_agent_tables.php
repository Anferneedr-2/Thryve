<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('base_url', 255); // the Hermes server, for example https://ai.example.com
            $table->string('profile', 64)->nullable(); // Hermes profile served at {base_url}/p/{profile}; also the model name
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            // Key this system sends to the agent (the agent's API_SERVER_KEY). Encrypted: it must be readable to be sent.
            $table->text('outbound_key')->nullable();
            $table->string('outbound_fingerprint', 12)->nullable();
            // A replacement key waiting for the admin to install it on the VPS (rotation step 1).
            $table->text('pending_key')->nullable();
            $table->string('pending_fingerprint', 12)->nullable();
            // Key the agent sends to this system. Only a hash is kept.
            $table->char('inbound_key_hash', 64)->nullable()->unique();
            $table->string('inbound_fingerprint', 12)->nullable();
            $table->dateTime('inbound_last_seen_at')->nullable();
            $table->string('status', 20)->default('never_tested'); // never_tested | working | slow | key_rejected | profile_missing | unreachable | revoked
            $table->dateTime('last_checked_at')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->dateTime('removed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agent_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12); // ping | chat | verify
            $table->string('category', 24); // ok | unauthorized | profile_missing | unavailable | timeout | ... (never raw text)
            $table->boolean('ok');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('tokens')->nullable(); // only for the one-message test
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('created_at');
            $table->index(['agent_id', 'id']);
        });

        Schema::table('usage_records', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('provider_connection_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
        });
        Schema::dropIfExists('agent_checks');
        Schema::dropIfExists('agents');
    }
};
