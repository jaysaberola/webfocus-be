<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('paynamics_payment_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_transaction_id')
                ->constrained('sales_transactions')
                ->cascadeOnDelete();
            $table->string('request_id', 40)->unique();
            $table->string('response_id', 64)->nullable();
            $table->string('response_code', 12)->nullable();
            $table->string('status', 32)->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paynamics_payment_references');
    }
};
