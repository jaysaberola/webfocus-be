<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_transactions', 'client_owner_id')) {
                $table->unsignedBigInteger('client_owner_id')->nullable()->after('user_id');
                $table->index('client_owner_id');
                $table->foreign('client_owner_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });

        $customers = DB::table('users')
            ->whereNotNull('owner_id')
            ->pluck('owner_id', 'id');

        foreach ($customers as $customerId => $ownerId) {
            DB::table('sales_transactions')
                ->where('customer_id', $customerId)
                ->whereNull('client_owner_id')
                ->update(['client_owner_id' => $ownerId]);
        }
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('sales_transactions', 'client_owner_id')) {
                $table->dropForeign(['client_owner_id']);
                $table->dropColumn('client_owner_id');
            }
        });
    }
};
