<?php

use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AlbumController;
use App\Http\Controllers\Api\OptionController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\JobOrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SalesTransactionController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ServiceCategoryController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Page\PageController;
use App\Http\Controllers\Api\AuditTrailController;
use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\LayoutPresetController;
use App\Http\Controllers\Api\WebsiteSettingController;
use App\Http\Controllers\Api\ArticleCategoryController;
use App\Http\Controllers\Api\Page\PublicPageController;
use App\Http\Controllers\Api\PublicDomainController;
use App\Http\Controllers\Api\PermissionMatrixController;


Route::post('/login', [AuthController::class, 'login']);
Route::post('/customer-login', [AuthController::class, 'customerLogin']);
Route::post('/register-customer', [AuthController::class, 'registerCustomer']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AccountController::class, 'me']);
    Route::post('/user/profile', [AccountController::class, 'updateProfile']);
    Route::put('/user/email', [AccountController::class, 'updateEmail']);
    Route::put('/user/password', [AccountController::class, 'updatePassword']);

    // dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // pages
    Route::get('/pages', [PageController::class, 'index']);
    Route::post('/pages', [PageController::class, 'store']);
    Route::get('/pages/{id}', [PageController::class, 'show']);
    Route::put('/pages/{id}', [PageController::class, 'update']);
    Route::patch('/pages/{id}', [PageController::class, 'update']);
    Route::delete('/pages/{id}', [PageController::class, 'destroy']);
    Route::post('/pages/restore', [PageController::class, 'restore']);
    Route::get('/pages-menu', [PageController::class, 'pages_menu']);

    // albums
    Route::apiResource('albums', AlbumController::class);
    Route::post('/albums/restore', [AlbumController::class, 'restore']);
    Route::post('/albums/{id}/restore', [AlbumController::class, 'restoreById']);

    // fetch animations
    Route::get('/options', [OptionController::class, 'index']);

    // menus
    Route::apiResource('menus', MenuController::class);
    Route::patch('/menus/{menu}/activate', [MenuController::class, 'setActive']);
    Route::post('/menus/restore', [MenuController::class, 'restore']);
    Route::post('/menus/{id}/restore', [MenuController::class, 'restoreById']);

    // article categories
    Route::get('/article-categories', [ArticleCategoryController::class, 'index']);
    Route::post('/article-categories', [ArticleCategoryController::class, 'store']);
    Route::post('/article-categories/delete', [ArticleCategoryController::class, 'delete']);
    Route::post('/article-categories/restore', [ArticleCategoryController::class, 'restore']);
    Route::post('/article-categories/{id}/restore', [ArticleCategoryController::class, 'restoreById']);
    Route::get('/article-categories/{category}', [ArticleCategoryController::class, 'show']);
    Route::put('/article-categories/{category}', [ArticleCategoryController::class, 'update']);

    // Compatibility endpoint used by some frontends
    // e.g. GET /api/categories?type=product
    Route::get('/categories', function (Request $request) {
        $type = $request->input('type');

        if ($type === 'product') {
            return app(ProductCategoryController::class)->index($request);
        }

        if ($type === 'service') {
            return app(ServiceCategoryController::class)->index($request);
        }

        if ($type === 'article') {
            return app(ArticleCategoryController::class)->index($request);
        }

        return response()->json([
            'message' => 'Unknown category type',
            'allowed' => ['product', 'service', 'article'],
        ], 404);
    });

    // articles
    Route::get('/articles', [ArticleController::class, 'index']);
    Route::get('/fetch-article-categories', [ArticleController::class, 'fetch_categories']);
    Route::post('/articles', [ArticleController::class, 'store']);
    Route::get('/articles/{article}', [ArticleController::class, 'show']);
    Route::post('/articles/{article}', [ArticleController::class, 'update']);
    Route::delete('/articles/{article}', [ArticleController::class, 'destroy']);
    Route::post('/articles/restore', [ArticleController::class, 'restore']);
    Route::post('/articles/{id}/restore', [ArticleController::class, 'restoreById']);
    Route::post('/articles/{article}/duplicate', [ArticleController::class, 'duplicate']);

    // products
    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::get('/fetch-product-categories', [ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);
    Route::get('/product-categories/{category}', [ProductCategoryController::class, 'show']);
    Route::put('/product-categories/{category}', [ProductCategoryController::class, 'update']);
    Route::patch('/product-categories/{category}', [ProductCategoryController::class, 'update']);
    Route::delete('/product-categories/{category}', [ProductCategoryController::class, 'destroy']);
    Route::post('/product-categories/restore', [ProductCategoryController::class, 'restore']);
    Route::post('/product-categories/{id}/restore', [ProductCategoryController::class, 'restoreById']);

    Route::apiResource('coupons', CouponController::class);
    Route::apiResource('sales-transactions', SalesTransactionController::class)
        ->parameters(['sales-transactions' => 'salesTransaction']);
    Route::apiResource('job-orders', JobOrderController::class)
        ->parameters(['job-orders' => 'jobOrder']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::patch('/products/{product}', [ProductController::class, 'update']);
    Route::post('/products/{product}', [ProductController::class, 'update']); // ← multipart _method=PUT spoof
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::post('/products/restore', [ProductController::class, 'restore']);
    Route::post('/products/{id}/restore', [ProductController::class, 'restoreById']);

    // services
    Route::get('/service-categories', [ServiceCategoryController::class, 'index']);
    Route::get('/fetch-service-categories', [ServiceCategoryController::class, 'index']);
    Route::post('/service-categories', [ServiceCategoryController::class, 'store']);
    Route::get('/service-categories/{category}', [ServiceCategoryController::class, 'show']);
    Route::put('/service-categories/{category}', [ServiceCategoryController::class, 'update']);
    Route::patch('/service-categories/{category}', [ServiceCategoryController::class, 'update']);
    Route::post('/service-categories/{category}', [ServiceCategoryController::class, 'handlePostAction']);
    Route::delete('/service-categories/{category}', [ServiceCategoryController::class, 'destroy']);

    Route::get('/services', [ServiceController::class, 'index']);
    Route::post('/services', [ServiceController::class, 'store']);
    Route::post('/services/bulk-delete', [ServiceController::class, 'bulkDelete']);
    Route::post('/services/bulk-status', [ServiceController::class, 'bulkStatus']);
    Route::get('/services/{service}', [ServiceController::class, 'show']);
    Route::put('/services/{service}', [ServiceController::class, 'update']);
    Route::patch('/services/{service}', [ServiceController::class, 'update']);
    Route::post('/services/{service}', [ServiceController::class, 'handlePostAction']);
    Route::delete('/services/{service}', [ServiceController::class, 'destroy']);
    Route::post('/services/{id}/restore', [ServiceController::class, 'restoreById']);

    // customers
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);
    Route::patch('/customers/{customer}', [CustomerController::class, 'update']);

    // users
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/fetch_roles', [UserController::class, 'fetch_roles']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);

    // audit
    Route::get('/audit-trails', [AuditTrailController::class, 'index']);

    // website settings
    Route::get('/website-settings', [WebsiteSettingController::class, 'show']);

    Route::post('/website-settings/website', [WebsiteSettingController::class, 'updateWebsite']);
    Route::post('/website-settings/contact', [WebsiteSettingController::class, 'updateContact']);
    Route::post('/website-settings/privacy', [WebsiteSettingController::class, 'updatePrivacy']);

    Route::get('/website-settings/social', [WebsiteSettingController::class, 'getSocials']);
    Route::post('/website-settings/social', [WebsiteSettingController::class, 'updateSocials']);

    // roles
    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{role}', [RoleController::class, 'update']);
    Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

    // access right
    Route::get('/permissions/matrix', [PermissionMatrixController::class, 'index']);
    Route::post('/permissions/sync', [PermissionMatrixController::class, 'sync']);

    // ai assistant
    Route::post('/ai/page-assistant', [AiAssistantController::class, 'generate']);

    // layout presets
    Route::prefix('layout-presets')->group(function () {
        Route::get('/', [LayoutPresetController::class, 'index']);
        Route::post('/', [LayoutPresetController::class, 'store']);
        Route::put('/{id}', [LayoutPresetController::class, 'update']);
        Route::delete('/{id}', [LayoutPresetController::class, 'destroy']);
        Route::post('/restore', [LayoutPresetController::class, 'restore']);
        Route::post('/{id}/restore', [LayoutPresetController::class, 'restoreById']);
    });

    // File Manager
    Route::prefix('file-manager')->group(function () {
        Route::get('initialize',         [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'initialize']);
        Route::get('content',            [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'content']);
        Route::get('tree',               [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'tree']);
        Route::get('select-disk',        [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'selectDisk']);
        Route::post('upload',            [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'upload']);
        Route::post('create-directory',  [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'createDirectory']);
        Route::post('rename',            [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'rename']);
        Route::post('delete',            [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'delete']);
        Route::post('paste',             [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'paste']);
        Route::get('download',           [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'download']);
        Route::get('thumbnails',         [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'thumbnails']);
        Route::get('preview',            [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'preview']);
        Route::get('url',                [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'url']);
        Route::post('zip',               [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'zip']);
        Route::post('unzip',             [\Alexusmai\LaravelFileManager\Controllers\FileManagerController::class, 'unzip']);
    });

});

//public
Route::get('/public-products', [ProductController::class, 'index']);
Route::get('/public/products', [ProductController::class, 'index']);
Route::get('/public-product-categories', [ProductCategoryController::class, 'index']);
Route::get('/public/product-categories', [ProductCategoryController::class, 'index']);
Route::get('/public/pages/{slug}', [PublicPageController::class, 'show']);
Route::get('/public/domains/check', [PublicDomainController::class, 'check']);
Route::get('/public/menus/active', [PublicPageController::class, 'active']);
Route::get('/public/footer', [PublicPageController::class, 'footer']);

// Public search routes
// Back-compat aliases for frontend helpers expecting /search and /public/search
Route::get('/search', [SearchController::class, 'quickSearch']);
Route::get('/public/search', [SearchController::class, 'quickSearch']);

Route::get('/search/pages', [SearchController::class, 'searchPages']);
Route::get('/search/global', [SearchController::class, 'globalSearch']);
Route::get('/search/quick', [SearchController::class, 'quickSearch']);

Route::prefix('public-articles')->group(function () {
    Route::get('/', [PublicPageController::class, 'public_articles']);
    Route::get('/{slug}', [PublicPageController::class, 'public_articles_show']);
});
Route::get('/public-article-categories', [PublicPageController::class, 'public_article_categories']);
Route::get('/public-articles-archive', [PublicPageController::class, 'archive']);

Route::post('/contact', [PublicPageController::class, 'send']);
