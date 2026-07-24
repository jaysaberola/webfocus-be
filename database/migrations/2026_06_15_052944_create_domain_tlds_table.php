<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_tlds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('domain_category_id')
                ->constrained('domain_categories')
                ->cascadeOnDelete();

            $table->string('tld')->unique();
            // example: com, net, org, ph, com.ph, edu.ph

            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_tlds');
    }
};