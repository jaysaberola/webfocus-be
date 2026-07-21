<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('id');
            $table->json('metadata')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['slug', 'metadata']);
        });

        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
