<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'address_region')) {
                $table->string('address_region')->nullable()->after('address_country');
            }
            if (! Schema::hasColumn('users', 'shipping_region')) {
                $table->string('shipping_region')->nullable()->after('shipping_country');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'address_region')) {
                $table->dropColumn('address_region');
            }
            if (Schema::hasColumn('users', 'shipping_region')) {
                $table->dropColumn('shipping_region');
            }
        });
    }
};
