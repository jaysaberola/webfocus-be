<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('category_id')->index();
            $table->string('slug')->unique();

            $table->date('date')->nullable();
            $table->string('name');

            $table->longText('contents')->nullable();
            $table->longText('json')->nullable();

            $table->text('styles')->nullable();
            $table->text('teaser')->nullable();

            $table->string('status')->default('draft');
            $table->boolean('is_featured')->default(false);

            $table->text('image_url')->nullable();
            $table->text('thumbnail_url')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_keyword')->nullable();
            $table->text('meta_description')->nullable();

            $table->unsignedBigInteger('user_id')->index();

            $table->timestamps();
            $table->softDeletes();

            // Foreign keys (optional but recommended)
            $table->foreign('category_id')
                ->references('id')
                ->on('article_categories')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
