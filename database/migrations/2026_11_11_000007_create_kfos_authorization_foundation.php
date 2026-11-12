<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kfos_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 80);
            $table->string('kind', 20); // operational or universal
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE kfos_roles ADD CONSTRAINT kfos_roles_kind_check CHECK (kind IN ('operational', 'universal'))");

        Schema::create('kfos_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 160);
            $table->string('category', 30); // business or security
            $table->boolean('is_protected')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE kfos_permissions ADD CONSTRAINT kfos_permissions_category_check CHECK (category IN ('business', 'security'))");

        Schema::create('kfos_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('kfos_roles')->restrictOnDelete();
            $table->foreignId('permission_id')->constrained('kfos_permissions')->restrictOnDelete();
            $table->unique(['role_id', 'permission_id'], 'kfos_role_permission_unique');
            $table->timestampsTz();
        });

        // A user may hold only ONE current operational role.
        Schema::create('kfos_operational_role_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('kfos_roles')->restrictOnDelete();
            $table->timestampsTz();
        });

        // Separate universal membership; does NOT copy the legacy is_system_admin flag.
        Schema::create('kfos_system_administrators', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::table('kfos_roles')->insert([
            'code' => 'system_administrator', 'name' => 'System Administrator',
            'kind' => 'universal', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([
            ['security.roles.create', 'Add Role'],
            ['security.roles.assign', 'Assign Employee Role'],
            ['security.roles.edit_permissions', 'Edit Role Permissions'],
            ['security.accounts.status', 'Manage Employee Account Status'],
        ] as [$code, $name]) {
            DB::table('kfos_permissions')->insert([
                'code' => $code, 'name' => $name, 'category' => 'security',
                'is_protected' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kfos_system_administrators');
        Schema::dropIfExists('kfos_operational_role_assignments');
        Schema::dropIfExists('kfos_role_permissions');
        Schema::dropIfExists('kfos_permissions');
        Schema::dropIfExists('kfos_roles');
    }
};
