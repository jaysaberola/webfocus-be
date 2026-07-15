<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('category_id')->nullable()->index();

            $table->string('slug')->unique();
            $table->string('name');

            $table->decimal('price', 12, 2)->nullable();
            $table->longText('description')->nullable();

            // Store the path returned by Storage::disk('public')->putFile / store()
            $table->text('image_url')->nullable();

            $table->string('status')->default('active');

            $table->unsignedBigInteger('user_id')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('category_id')
                ->references('id')
                ->on('product_categories')
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
