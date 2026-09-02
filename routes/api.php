<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\CronController;
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



// Google Login
//
// The server-side Socialite redirect flow is DISABLED on purpose. It ended with
//     redirect(front_url . "/oauth-success?token=$token")
// which (a) puts a live Sanctum token in a URL, where it leaks through the Referer
// header, browser history and every proxy/access log in the path, and (b) pointed at
// /oauth-success, a page that does not exist in the frontend — so it could not have
// been in working use. Google sign-in goes through NextAuth, which calls
// /auth/google-sync below. SocialiteController::redirect/callback are left in place
// so this is reversible; if the redirect flow is ever needed, hand back a one-time
// exchange code rather than the token itself.
//
// Route::get('signin-with-google', [SocialiteController::class, 'redirect'])->name('redirect');
// Route::get('callback', [SocialiteController::class, 'callback'])->name('callback');

// Exchanges a verified Google ID token for a Sanctum token. Throttled as an auth
// endpoint: it is unauthenticated and can create accounts.
Route::post('/auth/google-sync', [SocialiteController::class, 'syncUser'])
    ->middleware('throttle:auth');

//Register Routes
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('throttle:auth');

//Email Verification Routes
Route::post('/verify-email', [RegisteredUserController::class, 'verifyEmail'])
    ->middleware('throttle:auth');
Route::post('/resend-verification-code', [RegisteredUserController::class, 'verifyEmailSendNewCode'])
    ->middleware('throttle:auth');

//Contact Form Routes
Route::post('/contact-form-submit', [ContactController::class, 'submit'])
    ->middleware('throttle:contact');

// Cron entrypoints for the URL-based production scheduler (hit by wget from the
// host cron panel). Run every enabled supplier in-process. Optionally guarded by
// CRON_TOKEN (?token=…). Recommended: sync hourly, check-orders every 5 min.
// Payment receipts moved off the public disk — they are bank/wallet screenshots
// showing the customer's name, account number and transfer reference, and were
// being served unauthenticated from public/storage/receipts/.
//
// This route is signed rather than auth:sanctum-protected because the frontend
// renders the receipt in an <img src> (paymentRow.tsx), and an <img> cannot carry
// an Authorization header. A short-lived signature is generated only while
// serialising a transfer the requesting user owns (CreditsTransfer::getFullPathAttribute),
// so a valid URL never reaches anyone else, and it stops working after 30 minutes.
Route::get('/credits-receipt/{id}', [CreditsController::class, 'receipt'])
    ->whereNumber('id')
    ->middleware('signed')
    ->name('credits.receipt');

Route::get('/cron/suppliers/sync', [CronController::class, 'suppliersSync'])
    ->middleware('throttle:cron');
Route::get('/cron/suppliers/check-orders', [CronController::class, 'suppliersCheckOrders'])
    ->middleware('throttle:cron');

// Drains queued jobs (supplier fulfillment). Required once QUEUE_CONNECTION is
// moved off `sync`, since this host runs cron over HTTP rather than a shell
// crontab and has no supervisor to keep `queue:work` alive. Run every minute.
Route::get('/cron/queue/work', [CronController::class, 'queueWork'])
    ->middleware('throttle:cron');

// Nightly audit: does every user balance still equal the sum of their ledger?
// Returns 409 when it does not, so the host's cron monitor surfaces it.
Route::get('/cron/credits/reconcile', [CronController::class, 'reconcileCredits'])
    ->middleware('throttle:cron');

// Authenticated user routes
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ORDER MATTERS. `delete-read` is a literal segment and must be registered
    // BEFORE the `{id}` wildcard: Laravel matches in registration order, so with
    // `{id}` first, DELETE /user/notifications/delete-read bound to
    // deleteNotification('delete-read') and always returned 404 — the frontend's
    // "Delete Read" button could never have worked. The whereNumber constraint below
    // makes the ordering explicit rather than incidental.
    Route::delete('/user/notifications/delete-read', [NotificationController::class, 'deleteAllRead']);

    // Delete single notification
    Route::delete('/user/notifications/{id}', [NotificationController::class, 'deleteNotification'])
        ->whereNumber('id');

    //Save Transfered User Transfer Credit Request
    Route::post('/transfer-credit-request', [CreditsController::class, 'transferCreditRequest'])
        ->middleware('throttle:uploads');

    // Same ordering rule: literal segments before the wildcard.
    Route::post('/user/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::post('/user/notifications/{id}/acknowledge', [NotificationController::class, 'acknowledgeNotification'])
        ->whereNumber('id');

    // Mark a specific notification as read. This route already existed but pointed at
    // a method that was never written, so every call 500'd.
    Route::post('/user/notifications/{id}/read', [NotificationController::class, 'markAsRead'])
        ->whereNumber('id');
});

// Locale-aware auth routes (duplicate endpoints with {locale} prefix so models translate correctly)
Route::middleware('locale')->prefix('{locale}')->group(function () {
    // Login with locale
    Route::post('/login', [SessionController::class, 'store'])
        ->middleware('throttle:auth');
});

Route::middleware('auth:sanctum', 'locale')->prefix('{locale}')->group(function () {

    //Save Order Route (authenticated; price computed server-side)
    Route::post('/save-order', [OrderController::class, 'saveOrder'])
        ->middleware('throttle:write');

    //KYC document submission (ID front/back + selfie)
    Route::post('/kyc/submit', [\App\Http\Controllers\KycController::class, 'submit'])
        ->middleware('throttle:uploads');

    //Get User Orders
    Route::get('/user/orders', [OrderController::class, 'getUserOrders']);

    // One order, scoped to its owner (404, not 403, if it isn't theirs — see
    // OrderController::getUserOrder). Backs the order status page's poll-while-pending.
    Route::get('/user/orders/{id}', [OrderController::class, 'getUserOrder'])->whereNumber('id');

    //Get User Credits
    Route::get('/user/credits', [CreditsController::class, 'getUserCredits']);

    /*
     | Get User Profile (locale-aware).
     |
     | `user_types` is loaded explicitly because this is the ONLY place the storefront
     | learns which price tier the signed-in user is on. The product page resolves the
     | tier price client-side from the variation's `price_variations` array, so with the
     | relation missing it matched nothing and every user saw the default price. It used
     | to arrive via `public $with = ['user_types.priceVariations']` on the auth model,
     | which was removed because it ran on EVERY authenticated request (App\Models\User:68).
     |
     | Load `user_types` only — NOT `user_types.priceVariations`. That second level is
     | what dragged the whole price table for every product into this response.
     */
    Route::get('/user/profile', function (Request $request) {
        $user = $request->user()->loadMissing('user_types');

        return response()->json([
            'user' => $user,
            'orders' => $user->orders,
            'credits' => $user->credits,
            'credits_balance' => $user->credits_balance,
            'total_purchases' => $user->total_purchases,
            'received_amount' => $user->received_amount,
        ]);
    });

    // Get credit notifications for the authenticated user
    Route::get('/user/notifications/credits', [NotificationController::class, 'getCreditNotifications']);

    //Get all user notifications
    Route::get('/user/notifications', [NotificationController::class, 'getAllNotifications']);

    // Polling endpoint (doesn't mark as read)
    Route::get('/user/notifications/poll', [NotificationController::class, 'getNotificationsForPolling']);

    // Logout (locale-aware)
    Route::post('/logout', [SessionController::class, 'destroy']);

    //update user profile
    Route::put('/user/update', [SessionController::class, 'updateProfile']);

    //Change User Password
    Route::put('/change-password', [SessionController::class, 'changePassword']);
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

    //Password Reset Routes
    Route::post('/forgot-password', [RegisteredUserController::class, 'forgotPasswordSendEmail'])
        ->middleware('throttle:auth');
    Route::post('/reset-password', [RegisteredUserController::class, 'resetPasswordSendEmail'])
        ->middleware('throttle:auth');
});
