<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Accounts are created by admins only. Users are deactivated, never deleted, so history is kept:
// every table that references users uses restrictOnDelete.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('username', 64)->unique();
            $table->string('name');
            $table->string('email')->nullable()->unique(); // optional until D07 settles email requirements
            $table->string('password');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
