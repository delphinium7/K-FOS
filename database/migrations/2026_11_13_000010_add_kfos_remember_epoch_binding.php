<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Step 8: nullable while opt-in activation is OFF. Old grants fail closed once ON. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('kfos_remembered_devices', static function (Blueprint $table): void {
            $table->unsignedBigInteger('security_epoch_at_issue')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kfos_remembered_devices', static function (Blueprint $table): void {
            $table->dropColumn('security_epoch_at_issue');
        });
    }
};
