<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_activity_logs', function (Blueprint $table) {
            $table->id();

            $table->string('log_by')->nullable();
            $table->string('activity_type')->nullable();
            $table->string('dashboard_activity')->nullable();

            $table->mediumText('activity_desc')->nullable();

            $table->dateTime('activity_date')->nullable();

            $table->string('db_table')->nullable();

            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->text('reference')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_activity_logs');
    }
};
