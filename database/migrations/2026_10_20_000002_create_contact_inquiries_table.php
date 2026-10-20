<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('inquiry_type');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('contact_number', 40);
            $table->text('message');
            $table->json('preferred_services')->nullable();
            $table->string('status', 40)->default('new')->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_inquiries');
    }
};
