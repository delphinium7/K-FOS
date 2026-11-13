<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 9: original-event correlation ledger, test-only migration until release approval. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kfos_original_hr_revocation_events', static function (Blueprint $table): void {
            $table->id();
            $table->string('event_reference', 100)->unique();
            $table->foreignId('employee_master_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('account_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('epoch_before');
            $table->unsignedBigInteger('epoch_after')->nullable();
            $table->unsignedBigInteger('session_version_before');
            $table->unsignedBigInteger('session_version_after')->nullable();
            $table->string('state', 40); // prepared | committed_pending_proof
            $table->unsignedInteger('remembered_grants_revoked')->default(0);
            $table->timestampTz('prepared_at');
            $table->timestampTz('committed_at')->nullable();
            $table->timestampsTz();
            $table->index(['account_user_id', 'prepared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kfos_original_hr_revocation_events');
    }
};
