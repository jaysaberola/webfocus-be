<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('jo_no', 50)->unique();

            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_type', 20)->default('existing')->index();
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_email', 150)->nullable();
            $table->string('customer_contact', 60)->nullable();

            $table->string('source', 120)->nullable()->index();
            $table->string('category', 60)->default('Order')->index();
            $table->string('status', 60)->default('Open Date')->index();

            $table->dateTime('order_date')->nullable()->index();
            $table->dateTime('date_needed')->nullable()->index();

            $table->string('delivery_type', 80)->nullable()->index();
            $table->string('delivery_location', 150)->nullable();
            $table->text('delivery_address')->nullable();
            $table->decimal('delivery_charge', 12, 2)->default(0);

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->unsignedInteger('total_quantity')->default(0);

            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('user_id');

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
        Schema::dropIfExists('job_orders');
    }
};
