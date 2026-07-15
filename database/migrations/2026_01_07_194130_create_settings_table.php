<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->text('api_key')->nullable();
            $table->string('website_name')->nullable();
            $table->text('website_favicon')->nullable();

            $table->text('company_logo')->nullable();
            $table->text('company_favicon')->nullable();
            $table->string('company_name')->nullable();
            $table->text('company_about')->nullable();
            $table->text('company_address')->nullable();

            $table->text('google_analytics')->nullable();
            $table->text('google_map')->nullable();
            $table->text('google_recaptcha_sitekey')->nullable();
            $table->text('google_recaptcha_secret')->nullable();

            $table->string('data_privacy_title')->nullable();
            $table->string('data_privacy_popup_content')->nullable();
            $table->text('data_privacy_content')->nullable();

            $table->string('mobile_no')->nullable();
            $table->string('fax_no')->nullable();
            $table->string('tel_no')->nullable();
            $table->string('email')->nullable();

            $table->text('social_media_accounts')->nullable();
            $table->string('copyright')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('min_order')->default(0);

            $table->boolean('promo_is_displayed')->default(false);
            $table->boolean('review_is_allowed')->default(false);
            $table->boolean('pickup_is_allowed')->default(false);

            $table->text('delivery_note')->nullable();

            $table->boolean('min_order_is_allowed')->default(false);
            $table->boolean('flatrate_is_allowed')->default(false);
            $table->boolean('delivery_collect_is_allowed')->default(false);

            $table->text('accepted_payments')->nullable();
            $table->text('third_party_signin')->nullable();

            $table->integer('coupon_limit')->default(0);
            $table->decimal('coupon_discount_limit', 10, 2)->default(0);

            $table->integer('cart_notification_duration')->default(0);
            $table->integer('cart_product_duration')->default(0);

            $table->text('contact_us_email_layout')->nullable();
            $table->string('modal_title')->nullable();
            $table->text('modal_content')->nullable();
            $table->string('modal_status')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
