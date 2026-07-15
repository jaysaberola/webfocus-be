<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Short + indexed fields
            $table->string('code', 50)->unique();
            $table->string('name', 120);

            // Longer text
            $table->text('description')->nullable();

            // Enum is fine here
            $table->enum('discount_type', ['fixed', 'percent'])->default('fixed');

            // Money value
            $table->decimal('discount_value', 12, 2)->default(0);

            // Usage tracking
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);

            // Validity period
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();

            // Status (short string)
            $table->string('status', 20)->default('active')->index();

            // Owner
            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign key
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};