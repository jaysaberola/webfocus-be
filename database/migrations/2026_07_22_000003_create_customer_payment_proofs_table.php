<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sales_transaction_id')->nullable()->constrained('sales_transactions')->nullOnDelete();
            $table->string('proof_no', 40)->unique();
            $table->string('invoice_id', 120);
            $table->string('file_path');
            $table->string('file_name');
            $table->string('status', 40)->default('Pending Review')->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_proofs');
    }
};
