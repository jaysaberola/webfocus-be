<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('parent_page_id')->nullable()->index();
            $table->unsignedBigInteger('album_id')->nullable()->index();

            $table->string('slug')->unique();
            $table->string('name');
            $table->string('label')->nullable();

            $table->longText('contents')->nullable();
            $table->longText('json')->nullable();

            $table->text('styles')->nullable();

            $table->string('status')->default('draft')->index();
            $table->string('page_type')->nullable()->index();

            $table->text('image_url')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_keyword')->nullable();
            $table->text('meta_description')->nullable();

            $table->unsignedBigInteger('user_id')->index();

            $table->string('template')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Optional foreign keys
            // $table->foreign('parent_page_id')->references('id')->on('pages')->nullOnDelete();
            // $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
