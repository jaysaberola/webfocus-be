<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_addons', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->unique();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->text('description')->nullable();
            $table->string('label')->nullable();
            $table->enum('plan_type', ['cloud', 'shared', 'dedicated', 'baremetal', 'universal']);
            $table->string('billing')->default('yr');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['plan_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_addons');
    }
};
