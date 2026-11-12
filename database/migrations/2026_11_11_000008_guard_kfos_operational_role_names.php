<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Includes ACTIVE, DISABLED and universal role names. No role data is modified.
        DB::statement("CREATE UNIQUE INDEX kfos_roles_normalized_name_unique
            ON kfos_roles ((lower(regexp_replace(btrim(name), '[[:space:]]+', ' ', 'g'))))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS kfos_roles_normalized_name_unique');
    }
};
