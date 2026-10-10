<?php

use App\Http\Controllers\Api\AgroSyncController;
use App\Http\Middleware\ApiCors;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\RequireRole;
use Illuminate\Support\Facades\Route;

Route::middleware(ApiCors::class)->prefix('v1')->group(function () {
    Route::options('/{any}', fn () => response('', 204))->where('any', '.*');

    // ---------------------------------------------------------------
    // Public endpoints
    // ---------------------------------------------------------------
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/auth/login', [AgroSyncController::class, 'login']);
        Route::post('/auth/send-otp', [AgroSyncController::class, 'sendOtp']);
        Route::post('/auth/verify-otp', [AgroSyncController::class, 'verifyOtp']);
        Route::post('/auth/register-role', [AgroSyncController::class, 'registerRoleUser']);
    });

    Route::middleware('throttle:public')->group(function () {
        Route::get('/products', [AgroSyncController::class, 'products']);
        Route::get('/crop-prices', [AgroSyncController::class, 'cropPrices']);
        Route::get('/weather', [AgroSyncController::class, 'weatherForecast']);
    });

    // Payment gateway redirects land here in the browser, so they cannot carry a
    // bearer token. Each handler verifies the payment with the gateway itself.
    Route::middleware('throttle:public')->group(function () {
        Route::get('/payments/sslcommerz/mock-gateway', [AgroSyncController::class, 'sslCommerzMockGateway']);
        Route::match(['get', 'post'], '/payments/sslcommerz/success', [AgroSyncController::class, 'sslCommerzSuccess']);
        Route::match(['get', 'post'], '/payments/sslcommerz/fail', [AgroSyncController::class, 'sslCommerzFail']);
        Route::match(['get', 'post'], '/payments/sslcommerz/cancel', [AgroSyncController::class, 'sslCommerzCancel']);
        Route::get('/payments/stripe/mock-gateway', [AgroSyncController::class, 'stripeMockGateway']);
        Route::match(['get', 'post'], '/payments/stripe/success', [AgroSyncController::class, 'stripeSuccess']);
        Route::match(['get', 'post'], '/payments/stripe/fail', [AgroSyncController::class, 'stripeFail']);
        Route::match(['get', 'post'], '/payments/stripe/cancel', [AgroSyncController::class, 'stripeCancel']);
        Route::get('/payments/orders/stripe/mock-gateway', [AgroSyncController::class, 'orderStripeMockGateway']);
        Route::match(['get', 'post'], '/payments/orders/stripe/success', [AgroSyncController::class, 'orderStripeSuccess']);
        Route::match(['get', 'post'], '/payments/orders/stripe/fail', [AgroSyncController::class, 'orderStripeFail']);
        Route::match(['get', 'post'], '/payments/orders/stripe/cancel', [AgroSyncController::class, 'orderStripeCancel']);
    });

    // ---------------------------------------------------------------
    // Authenticated endpoints (ownership is checked in the controller)
    // ---------------------------------------------------------------
    Route::middleware([AuthenticateApiToken::class, 'throttle:api'])->group(function () {
        Route::post('/auth/logout', [AgroSyncController::class, 'logout']);
        Route::get('/auth/me', [AgroSyncController::class, 'me']);

        Route::patch('/users/{userId}', [AgroSyncController::class, 'updateUserProfile']);
        Route::patch('/users/{userId}/password', [AgroSyncController::class, 'changePassword']);
        Route::delete('/users/{userId}', [AgroSyncController::class, 'deleteUserAccount']);

        Route::get('/farmers', [AgroSyncController::class, 'farmers'])
            ->middleware(RequireRole::class.':admin,officer,vendor');

        Route::get('/advisory-cases', [AgroSyncController::class, 'advisoryCases'])
            ->middleware(RequireRole::class.':admin,officer,farmer');
        Route::post('/advisory-cases', [AgroSyncController::class, 'submitAdvisory'])
            ->middleware(RequireRole::class.':farmer');
        Route::post('/advisory-cases/{caseId}/respond', [AgroSyncController::class, 'respondToCase'])
            ->middleware(RequireRole::class.':officer');
        Route::get('/advisory-cases/regional/stats', [AgroSyncController::class, 'regionalAdvisoryStats'])
            ->middleware(RequireRole::class.':admin,officer');

        Route::post('/products', [AgroSyncController::class, 'createProduct'])
            ->middleware(RequireRole::class.':admin,vendor');
        Route::patch('/products/{productId}', [AgroSyncController::class, 'updateProduct'])
            ->middleware(RequireRole::class.':admin,vendor');
        Route::delete('/products/{productId}', [AgroSyncController::class, 'deleteProduct'])
            ->middleware(RequireRole::class.':admin,vendor');

        Route::middleware(RequireRole::class.':farmer')->group(function () {
            Route::get('/farmers/{farmerId}/cart', [AgroSyncController::class, 'cart']);
            Route::post('/farmers/{farmerId}/cart', [AgroSyncController::class, 'addToCart']);
            Route::patch('/farmers/{farmerId}/cart/{productId}', [AgroSyncController::class, 'updateCartItem']);
            Route::delete('/farmers/{farmerId}/cart', [AgroSyncController::class, 'clearCart']);
            Route::post('/orders', [AgroSyncController::class, 'placeOrder']);
            Route::post('/price-alerts', [AgroSyncController::class, 'subscribePriceAlert']);
            Route::post('/payments/orders/stripe/initiate', [AgroSyncController::class, 'initiateOrderStripePayment']);
        });

        Route::get('/orders', [AgroSyncController::class, 'orders'])
            ->middleware(RequireRole::class.':admin,farmer,vendor');
        Route::patch('/orders/{orderId}/status', [AgroSyncController::class, 'updateOrderStatus'])
            ->middleware(RequireRole::class.':admin,farmer,vendor');

        Route::get('/crop-listings', [AgroSyncController::class, 'cropListings']);
        Route::post('/crop-listings', [AgroSyncController::class, 'createCropListing'])
            ->middleware(RequireRole::class.':admin,farmer');
        Route::patch('/crop-listings/{listingId}', [AgroSyncController::class, 'updateCropListing'])
            ->middleware(RequireRole::class.':admin,farmer');
        Route::delete('/crop-listings/{listingId}', [AgroSyncController::class, 'deleteCropListing'])
            ->middleware(RequireRole::class.':admin,farmer');

        Route::post('/crop-deals/interest', [AgroSyncController::class, 'expressInterest'])
            ->middleware(RequireRole::class.':company');
        Route::get('/crop-deals', [AgroSyncController::class, 'cropDeals'])
            ->middleware(RequireRole::class.':admin,farmer,company');
        Route::patch('/crop-deals/{dealId}/status', [AgroSyncController::class, 'updateDealStatus'])
            ->middleware(RequireRole::class.':farmer,company');
        Route::post('/payments/sslcommerz/initiate', [AgroSyncController::class, 'initiateSslCommerzPayment'])
            ->middleware(RequireRole::class.':company');
        Route::post('/payments/stripe/initiate', [AgroSyncController::class, 'initiateStripePayment'])
            ->middleware(RequireRole::class.':company');

        Route::get('/notifications/{userId}', [AgroSyncController::class, 'notifications']);
        Route::patch('/notifications/{notificationId}/read', [AgroSyncController::class, 'markNotificationRead']);
        Route::patch('/notifications/user/{userId}/read-all', [AgroSyncController::class, 'markAllNotificationsRead']);

        Route::get('/settings/{userId}', [AgroSyncController::class, 'settings']);
        Route::put('/settings/{userId}', [AgroSyncController::class, 'updateSettings']);

        // -----------------------------------------------------------
        // Admin only
        // -----------------------------------------------------------
        Route::middleware(RequireRole::class.':admin')->group(function () {
            Route::get('/users', [AgroSyncController::class, 'users']);
            Route::get('/admin/stats', [AgroSyncController::class, 'adminStats']);
            Route::post('/admin/officers', [AgroSyncController::class, 'createOfficer']);
            Route::patch('/admin/farmers/{farmerId}/state', [AgroSyncController::class, 'updateFarmerAdminState']);
            Route::patch('/admin/officers/{officerId}/state', [AgroSyncController::class, 'updateOfficerAdminState']);
            Route::get('/admin/orders', [AgroSyncController::class, 'adminOrders']);
            Route::patch('/admin/orders/{orderId}/settlement', [AgroSyncController::class, 'updateOrderSettlement']);
            Route::get('/admin/tenants', [AgroSyncController::class, 'tenants']);
            Route::post('/admin/tenants', [AgroSyncController::class, 'createTenant']);
            Route::patch('/admin/tenants/{tenantId}', [AgroSyncController::class, 'updateTenant']);
            Route::get('/admin/audit-logs', [AgroSyncController::class, 'auditLogs']);
        });
    });
});
