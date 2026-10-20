<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_owner_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->string('encoded_by')->nullable();
            $table->string('lead_source')->nullable();
            $table->string('lead_status')->nullable();
            $table->string('currency', 8)->default('PHP');
            $table->string('workdrive_folder_url')->nullable();
            $table->string('workdrive_folder_id')->nullable();
            $table->string('salutation')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('title')->nullable();
            $table->string('company');
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->string('website')->nullable();
            $table->string('interested_in')->nullable();
            $table->text('interested_specify')->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_province')->nullable();
            $table->string('address_zip')->nullable();
            $table->string('address_country')->nullable();
            $table->text('description')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('lead_owner_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
};
