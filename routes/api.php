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
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SubcategoryController;
use App\Http\Controllers\NotificationController;

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



//Google Login
Route::get('signin-with-google', [SocialiteController::class, 'redirect'])->name('redirect');
Route::get('callback', [SocialiteController::class, 'callback'])->name('callback');
Route::post('/auth/google-sync', [SocialiteController::class, 'syncUser']);

//Register Routes
Route::post('/register', [RegisteredUserController::class, 'store']);

//Email Verification Routes
Route::post('/verify-email', [RegisteredUserController::class, 'verifyEmail']);
Route::post('/resend-verification-code', [RegisteredUserController::class, 'verifyEmailSendNewCode']);

// Route::post('/login', [SessionController::class, 'store']);

//Password Reset Routes
Route::post('/forgot-password', [RegisteredUserController::class, 'forgotPasswordSendEmail']);
Route::post('/reset-password', [RegisteredUserController::class, 'resetPasswordSendEmail']);

//Contact Form Routes
Route::post('/contact-form-submit', [ContactController::class, 'submit']);

//Save Order Route
Route::post('/save-order', [OrderController::class, 'saveOrder']);


// Authenticated user routes
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });



    //Save Transfered User Transfer Credit Request
    Route::post('/transfer-credit-request', [CreditsController::class, 'transferCreditRequest']);

    // Get credit notifications for the authenticated user
    Route::get('/user/notifications/credits', [NotificationController::class, 'getCreditNotifications']);

    // Optional: Get all user notifications
    Route::get('/user/notifications', [NotificationController::class, 'getAllNotifications']);

    // Optional: Mark specific notification as read
    Route::post('/user/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
});

// Locale-aware auth routes (duplicate endpoints with {locale} prefix so models translate correctly)
Route::middleware('locale')->prefix('{locale}')->group(function () {
    // Login with locale
    Route::post('/login', [SessionController::class, 'store']);
});

Route::middleware('auth:sanctum', 'locale')->prefix('{locale}')->group(function () {

    //Get User Orders
    Route::get('/user/orders', [OrderController::class, 'getUserOrders']);

    //Get User Credits
    Route::get('/user/credits', [CreditsController::class, 'getUserCredits']);

    // Get User Profile (locale-aware)
    Route::get('/user/profile', function (Request $request) {
        return response()->json([
            'user' => $request->user(),
            'orders' => $request->user()->orders,
            'credits' => $request->user()->credits,
            'credits_balance' => $request->user()->credits_balance,
            'total_purchases' => $request->user()->total_purchases,
            'received_amount' => $request->user()->received_amount,
        ]);
    });

    // Logout (locale-aware)
    Route::post('/logout', [SessionController::class, 'destroy']);

    //update user profile
    Route::put('/user/update', [SessionController::class, 'updateProfile']);
});


//Global api Routes
Route::middleware('locale')->prefix('{locale}')->group(function () {

    //Get Dashboard Settings
    Route::get('/dashboard-settings', [DashboardController::class, 'index']);

    //General Route
    Route::get('/general', [GeneralController::class, 'index']);

    //Homepage Route
    Route::get('/home', [HomepageController::class, 'index']);

    //Category Route
    Route::get('/categories', [CategoryController::class, 'index']);

    //Subcategory Route
    Route::get('/categories/{slug}', [SubcategoryController::class, 'index']);

    //Products Route
    Route::get('/categories/{category_slug}/{subcategory_slug}', [ProductController::class, 'index']);

    //Single Product Route
    Route::get('/categories/{category_slug}/{subcategory_slug}/{slug}', [ProductController::class, 'SingleProduct']);

    //About Page Route
    Route::get('/about', [AboutController::class, 'index']);

    //Contact Page Route
    Route::get('/contact', [ContactController::class, 'index']);

    //Search Route
    Route::get('/search', [SearchController::class, 'index']);

    //Get Credits
    Route::get('/credit-types', [CreditsController::class, 'getCredits']);

    //Get single Credit Type
    Route::get('/credit-types/{slug}', [CreditsController::class, 'getSingleCreditType']);
});
