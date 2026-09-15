<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('paynamics_payment_references', function (Blueprint $table) {
            $table->string('payment_method', 80)->nullable()->after('response_code');
        });
    }

    public function down(): void
    {
        Schema::table('paynamics_payment_references', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
