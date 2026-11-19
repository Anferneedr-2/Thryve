<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete(); // null until the message is sent
            $table->string('original_name', 255);
            $table->string('kind', 10); // txt | md | csv | docx | xlsx (pdf, png, jpg wait for decision D04)
            $table->unsignedBigInteger('size');
            $table->string('storage_key', 120)->unique(); // random name on the private disk; the original name is never a path
            $table->char('sha256', 64);
            $table->longText('extracted_text'); // what Hermes receives: text pulled out on our server
            $table->boolean('truncated')->default(false);
            $table->boolean('generated')->default(false); // files produced by the assistant (P06.03, needs D04)
            $table->timestamps();
            $table->index(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
