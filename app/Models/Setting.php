<?php

namespace App\Models;

use OwenIt\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Setting extends Model implements AuditableContract
{
    use HasFactory, SoftDeletes, Auditable;

    protected $fillable = [
        'api_key',
        'website_name',
        'website_favicon',
        'company_logo',
        'company_favicon',
        'company_name',
        'company_about',
        'company_address',
        'google_analytics',
        'google_map',
        'google_recaptcha_sitekey',
        'google_recaptcha_secret',
        'data_privacy_title',
        'data_privacy_popup_content',
        'data_privacy_content',
        'mobile_no',
        'fax_no',
        'tel_no',
        'email',
        'social_media_accounts',
        'copyright',
        'user_id',
        'min_order',
        'promo_is_displayed',
        'review_is_allowed',
        'pickup_is_allowed',
        'delivery_note',
        'min_order_is_allowed',
        'flatrate_is_allowed',
        'delivery_collect_is_allowed',
        'accepted_payments',
        'third_party_signin',
        'coupon_limit',
        'coupon_discount_limit',
        'cart_notification_duration',
        'cart_product_duration',
        'contact_us_email_layout',
        'modal_title',
        'modal_content',
        'modal_status',
        'nav_alignment',
    ];

    protected $casts = [
        'promo_is_displayed' => 'boolean',
        'review_is_allowed' => 'boolean',
        'pickup_is_allowed' => 'boolean',
        'min_order_is_allowed' => 'boolean',
        'flatrate_is_allowed' => 'boolean',
        'delivery_collect_is_allowed' => 'boolean',
        'coupon_discount_limit' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
