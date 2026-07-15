<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_transaction_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sales_transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');
            $table->string('item_type', 50)->default('product');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->timestamps();

            $table->index('sales_transaction_id');
            $table->index('product_id');

            $table->foreign('sales_transaction_id')
                ->references('id')
                ->on('sales_transactions')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_transaction_items');
    }
};
