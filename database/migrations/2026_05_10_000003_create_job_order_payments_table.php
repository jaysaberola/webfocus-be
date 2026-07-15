<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_order_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('job_order_id');
            $table->string('payment_method', 80);
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->timestamps();

            $table->index('job_order_id');
            $table->index('payment_method');

            $table->foreign('job_order_id')
                ->references('id')
                ->on('job_orders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_payments');
    }
};
