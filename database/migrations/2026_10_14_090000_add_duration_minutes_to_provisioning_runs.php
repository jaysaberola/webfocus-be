<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('provisioning_runs', 'duration_minutes')) {
                $table->unsignedSmallInteger('duration_minutes')->nullable()->after('duration_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_runs', function (Blueprint $table) {
            if (Schema::hasColumn('provisioning_runs', 'duration_minutes')) {
                $table->dropColumn('duration_minutes');
            }
        });
    }
};
