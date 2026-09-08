<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_transaction_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_transaction_id')->constrained('sales_transactions')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('version')->default(1);
            $table->string('kind', 32)->default('proposal');
            $table->string('file_path');
            $table->string('file_name');
            $table->timestamps();

            $table->index(['sales_transaction_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_transaction_proposals');
    }
};
