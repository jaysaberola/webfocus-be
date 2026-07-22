<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->string('reference_key', 100)->nullable()->after('customer_id');
            $table->unique(['customer_id', 'reference_key'], 'customer_notifications_customer_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropUnique('customer_notifications_customer_reference_unique');
            $table->dropColumn('reference_key');
        });
    }
};
