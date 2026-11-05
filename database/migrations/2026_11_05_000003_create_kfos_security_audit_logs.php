<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('target_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('event_type', 100);

            $table->string('result', 30);

            $table->jsonb('details')->nullable();

            $table->boolean('is_shared_dev_identity')
                ->default(false);

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_audit_logs');
    }
};
