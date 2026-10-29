<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Banner;
use App\Models\CmsActivityLog;
use App\Models\Coupon;
use App\Models\JobOrder;
use App\Models\LayoutPreset;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\SocialMediaAccount;
use Illuminate\Database\Eloquent\Builder;

final class CmsAuditTrailScope
{
    /** @return list<class-string> */
    public static function auditableModels(): array
    {
        return [
            LayoutPreset::class,
            Page::class,
            Menu::class,
            Article::class,
            ArticleCategory::class,
            Album::class,
            Banner::class,
            Product::class,
            ProductCategory::class,
            Coupon::class,
            JobOrder::class,
            Setting::class,
            SocialMediaAccount::class,
            CmsActivityLog::class,
        ];
    }

    public static function apply(Builder $query): Builder
    {
        $models = self::auditableModels();

        return $query->where(function (Builder $scoped) use ($models) {
            foreach ($models as $modelClass) {
                $scoped->orWhere('auditable_type', $modelClass);
                $scoped->orWhere('auditable_type', 'like', '%\\'.class_basename($modelClass));
            }
        });
    }
}
