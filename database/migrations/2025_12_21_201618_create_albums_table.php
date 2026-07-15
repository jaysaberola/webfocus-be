<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            $table->integer('transition_in')->nullable();
            $table->integer('transition_out')->nullable();
            $table->integer('transition')->nullable();

            $table->string('type')->nullable();
            $table->string('banner_type')->nullable();

            $table->unsignedBigInteger('user_id')->index();

            $table->timestamps();
            $table->softDeletes();

            // Optional foreign key
            // $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('albums');
    }
};
