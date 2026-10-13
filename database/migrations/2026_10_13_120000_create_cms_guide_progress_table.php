<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_guide_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('guide_id', 80);
            $table->string('status', 20)->default('in_progress');
            $table->unsignedSmallInteger('step_index')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'guide_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_guide_progress');
    }
};
