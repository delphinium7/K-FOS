<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kfos_remembered_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('session_version_at_issue');
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'revoked_at', 'expires_at'], 'kfos_remember_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kfos_remembered_devices');
    }
};
