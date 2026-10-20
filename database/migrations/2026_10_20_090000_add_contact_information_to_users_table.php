<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'contact_information')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('contact_information')->nullable()->after('gen_info_sheet');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'contact_information')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('contact_information');
        });
    }
};
