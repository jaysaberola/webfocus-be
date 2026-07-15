<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('job_order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_type', 30)->default('product')->index();
            $table->string('name', 180);
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->boolean('is_miscellaneous')->default(false)->index();
            $table->timestamps();

            $table->index('job_order_id');
            $table->index('product_id');

            $table->foreign('job_order_id')
                ->references('id')
                ->on('job_orders')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_items');
    }
};
