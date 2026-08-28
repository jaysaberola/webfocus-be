<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('audits')) {
            return;
        }

        // Editor HTML can exceed TEXT (64KB). LONGTEXT is idempotent if already applied.
        DB::statement('ALTER TABLE `audits` MODIFY `old_values` LONGTEXT NULL');
        DB::statement('ALTER TABLE `audits` MODIFY `new_values` LONGTEXT NULL');
    }

    public function down(): void
    {
        // Keep LONGTEXT on rollback — shrinking would truncate existing audit rows.
    }
};
