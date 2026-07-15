<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('album_id')->nullable()->index();

            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('alt')->nullable();

            $table->text('image_path')->nullable();

            $table->string('button_text')->nullable();
            $table->text('url')->nullable();

            $table->integer('order')->default(0)->index();

            $table->unsignedBigInteger('user_id')->index();

            $table->timestamps();
            $table->softDeletes();

            // Optional foreign keys
            // $table->foreign('album_id')->references('id')->on('albums')->nullOnDelete();
            // $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
