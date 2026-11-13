<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 7 foundations only: no production routes, HR writers or email link flows.
 * Applied by the bundle exclusively to the isolated kfos_test database.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kfos_first_admin_bootstrap_events', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary(); // Exactly one initial bootstrap, ID=1
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('employee_master_id')->constrained('employees')->restrictOnDelete();
            $table->string('official_employee_id', 100);
            $table->string('operator_reference', 150);
            $table->string('authorization_reference', 150);
            $table->timestampTz('created_at');
        });

        // Recovery enrollment data only. Do not infer expiry/old-address policy.
        Schema::create('kfos_admin_recovery_contacts', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->string('verified_company_email', 254)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('pending_company_email', 254)->nullable();
            $table->timestampTz('pending_requested_at')->nullable();
            $table->timestampsTz();
        });

        // This records durable state, but by itself does NOT integrate the
        // original HR status writer, token issuer, or pending-event reconciler.
        Schema::create('kfos_account_security_epochs', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('epoch')->default(0);
            $table->boolean('revocation_pending')->default(false);
            $table->string('original_hr_event_reference', 150)->nullable();
            $table->timestampTz('barrier_set_at')->nullable();
            $table->timestampTz('barrier_cleared_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kfos_account_security_epochs');
        Schema::dropIfExists('kfos_admin_recovery_contacts');
        Schema::dropIfExists('kfos_first_admin_bootstrap_events');
    }
};
