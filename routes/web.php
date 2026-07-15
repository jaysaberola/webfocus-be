<?php

use Illuminate\Support\Facades\Route;
use Alexusmai\LaravelFileManager\Controllers\FileManagerController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/file-manager-ui', function () {
    return view('admin.file-manager');
});

Route::group([
    'prefix' => 'file-manager',
], function () {
    Route::get('/', [FileManagerController::class, 'index']);
    Route::any('/{any}', [FileManagerController::class, 'handle'])
        ->where('any', '.*');
});