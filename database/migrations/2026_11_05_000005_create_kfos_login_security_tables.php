<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kfos_login_security_states', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('restricted_at')->nullable();
            $table->timestampTz('restored_at')->nullable();
            $table->foreignId('restored_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('kfos_login_restriction_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('restricted_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['resolved_at', 'restricted_at'], 'kfos_login_restriction_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kfos_login_restriction_notifications');
        Schema::dropIfExists('kfos_login_security_states');
    }
};
