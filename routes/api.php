<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\GeneralController;
use App\Http\Controllers\HomepageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\auth\RegisteredUserController;
use App\Http\Controllers\auth\SocialiteController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SubcategoryController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

//Google Login
Route::get('signin-with-google', [SocialiteController::class, 'redirect'])->name('redirect');
Route::get('callback', [SocialiteController::class, 'callback'])->name('callback');

//Register Routes
Route::post('/register', [RegisteredUserController::class, 'store']);

//Email Verification Routes
Route::post('/verify-email', [RegisteredUserController::class, 'verifyEmail']);
Route::post('/resend-verification-code', [RegisteredUserController::class, 'verifyEmailSendNewCode']);

//Login Routes
Route::post('/login', [SessionController::class, 'store']);

//Logout Routes
// Route::post('/logout', [SessionController::class, 'destroy']);
Route::middleware('auth:sanctum')->post('/logout', [SessionController::class, 'destroy']);

//Password Reset Routes
Route::post('/forgot-password', [RegisteredUserController::class, 'forgotPasswordSendEmail']);
Route::post('/reset-password', [RegisteredUserController::class, 'resetPasswordSendEmail']);

//Contact Form Routes
Route::post('/contact-form-submit', [ContactController::class, 'submit']);

Route::middleware('locale')->prefix('{locale}')->group(function () {
    //General Route
    Route::get('/general', [GeneralController::class, 'index']);

    //Homepage Route
    Route::get('/home', [HomepageController::class, 'index']);

    //Category Route
    Route::get('/categories', [CategoryController::class, 'index']);

    //Subcategory Route
    Route::get('/categories/{slug}', [SubcategoryController::class, 'index']);

    //Product Route
    Route::get('/categories/{slug}/{subcategory_slug}', [ProductController::class, 'index']);

    //About Page Route
    Route::get('/about', [AboutController::class, 'index']);

    //Contact Page Route
    Route::get('/contact', [ContactController::class, 'index']);
});
