<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('industry')->nullable()->after('owner_id');
            $table->string('tax_classification')->nullable()->after('industry');
            $table->string('tin_number')->nullable()->after('tax_classification');
            $table->string('other_numbers')->nullable()->after('tin_number');
            $table->string('currency', 10)->nullable()->default('PHP')->after('other_numbers');
            $table->string('workdrive_folder_url')->nullable()->after('currency');
            $table->string('workdrive_folder_id')->nullable()->after('workdrive_folder_url');
            $table->string('client_classification')->nullable()->after('workdrive_folder_id');
            $table->string('client_type')->nullable()->after('client_classification');
            $table->string('contact_person')->nullable()->after('client_type');
            $table->string('website')->nullable()->after('contact_person');
            $table->string('ownership')->nullable()->after('website');
            $table->string('billing_in_charge')->nullable()->after('ownership');
            $table->decimal('exchange_rate', 12, 4)->nullable()->default(1)->after('billing_in_charge');
            $table->string('address_country')->nullable()->after('address_zip');
            $table->text('shipping_street')->nullable()->after('address_country');
            $table->text('shipping_city')->nullable()->after('shipping_street');
            $table->text('shipping_province')->nullable()->after('shipping_city');
            $table->string('shipping_zip')->nullable()->after('shipping_province');
            $table->string('shipping_country')->nullable()->after('shipping_zip');
            $table->string('bir_certificate')->nullable()->after('shipping_country');
            $table->string('business_permit')->nullable()->after('bir_certificate');
            $table->string('sec_dti_registration')->nullable()->after('business_permit');
            $table->string('valid_id_signatories')->nullable()->after('sec_dti_registration');
            $table->string('gen_info_sheet')->nullable()->after('valid_id_signatories');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'industry',
                'tax_classification',
                'tin_number',
                'other_numbers',
                'currency',
                'workdrive_folder_url',
                'workdrive_folder_id',
                'client_classification',
                'client_type',
                'contact_person',
                'website',
                'ownership',
                'billing_in_charge',
                'exchange_rate',
                'address_country',
                'shipping_street',
                'shipping_city',
                'shipping_province',
                'shipping_zip',
                'shipping_country',
                'bir_certificate',
                'business_permit',
                'sec_dti_registration',
                'valid_id_signatories',
                'gen_info_sheet',
            ]);
        });
    }
};
