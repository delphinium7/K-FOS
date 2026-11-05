<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')
                ->nullable()
                ->unique()
                ->constrained('employees')
                ->restrictOnDelete();

            $table->string('login_id')->unique();

            $table->boolean('is_active')->default(true);

            $table->boolean('is_system_admin')->default(false);

            $table->boolean('is_development_only')->default(false);

            $table->unsignedBigInteger('session_version')
                ->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);

            $table->dropColumn([
                'employee_id',
                'login_id',
                'is_active',
                'is_system_admin',
                'is_development_only',
                'session_version',
            ]);
        });
    }
};
