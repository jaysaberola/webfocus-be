<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'grapes_html')) {
                $table->longText('grapes_html')->nullable()->after('contents');
            }
            if (! Schema::hasColumn('pages', 'grapes_css')) {
                $table->text('grapes_css')->nullable()->after('grapes_html');
            }
            if (! Schema::hasColumn('pages', 'grapes_js')) {
                $table->text('grapes_js')->nullable()->after('grapes_css');
            }
            if (! Schema::hasColumn('pages', 'content_type')) {
                $table->string('content_type', 32)->default('tiny')->after('grapes_js');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table) {
            if (Schema::hasColumn('pages', 'grapes_html')) {
                $table->dropColumn('grapes_html');
            }
            if (Schema::hasColumn('pages', 'grapes_css')) {
                $table->dropColumn('grapes_css');
            }
            if (Schema::hasColumn('pages', 'grapes_js')) {
                $table->dropColumn('grapes_js');
            }
            if (Schema::hasColumn('pages', 'content_type')) {
                $table->dropColumn('content_type');
            }
        });
    }
};
