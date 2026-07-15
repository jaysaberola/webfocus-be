<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Modify audit value columns to LONGTEXT to allow large payloads from editors
        // Using raw statements to avoid requiring doctrine/dbal for column type changes.
        DB::statement("ALTER TABLE `audits` MODIFY `old_values` LONGTEXT NULL");
        DB::statement("ALTER TABLE `audits` MODIFY `new_values` LONGTEXT NULL");
    }

    public function down(): void
    {
        // Revert to TEXT (may truncate large values if rolled back)
        DB::statement("ALTER TABLE `audits` MODIFY `old_values` TEXT NULL");
        DB::statement("ALTER TABLE `audits` MODIFY `new_values` TEXT NULL");
    }
};
