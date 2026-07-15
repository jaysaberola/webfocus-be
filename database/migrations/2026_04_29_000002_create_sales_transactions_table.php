<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Transaction reference (short + unique)
            $table->string('transaction_no', 50)->unique();

            // Customer info
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_email', 150)->nullable();

            // Financials
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);

            // Status fields (short + indexed)
            $table->string('payment_status', 20)->default('pending')->index();
            $table->string('order_status', 20)->default('pending')->index();

            // Notes
            $table->text('notes')->nullable();

            // Transaction date
            $table->timestamp('transacted_at')->nullable()->index();

            // User (who processed)
            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('customer_id');
            $table->index('user_id');

            // Foreign keys
            $table->foreign('customer_id')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_transactions');
    }
};