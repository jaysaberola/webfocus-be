<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_services', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_services', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('renew_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_services', function (Blueprint $table) {
            if (Schema::hasColumn('customer_services', 'expires_at')) {
                $table->dropColumn('expires_at');
            }
        });
    }
};
