<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\ParticipationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('payments/webhook/paystack', [PaymentController::class, 'paystackWebhook']);

Route::get('opportunities', [OpportunityController::class, 'index']);
Route::middleware('api.token')->get('opportunities/mine', [OpportunityController::class, 'mine']);
Route::middleware('api.token:optional')->get('opportunities/{slug}', [OpportunityController::class, 'show']);

Route::middleware('api.token')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('participations', [ParticipationController::class, 'index']);
    Route::post('participations', [ParticipationController::class, 'store']);
    Route::post('participations/{participation}/cancel', [ParticipationController::class, 'cancel']);

    Route::post('opportunities', [OpportunityController::class, 'store']);
    Route::put('opportunities/{opportunity}', [OpportunityController::class, 'update']);
    Route::post('opportunities/{opportunity}/documents', [OpportunityController::class, 'uploadDocument']);
    Route::post('opportunities/{opportunity}/submit', [OpportunityController::class, 'submit']);
    Route::post('opportunities/{opportunity}/waitlist', [OpportunityController::class, 'waitlist']);

    Route::get('payments/sandbox/{reference}', [PaymentController::class, 'sandboxShow']);
    Route::post('payments/sandbox/settle', [PaymentController::class, 'sandboxSettle']);

    Route::get('wallet', [WalletController::class, 'index']);
    Route::post('wallet/deposit', [WalletController::class, 'deposit']);

    Route::get('assets', [AssetController::class, 'index']);
    Route::get('assets/{asset}', [AssetController::class, 'show']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);

    Route::get('support/conversation', [SupportController::class, 'conversation']);
    Route::post('support/conversation', [SupportController::class, 'store']);
    Route::post('support/conversation/messages', [SupportController::class, 'reply']);

    Route::prefix('admin')->group(function () {
        Route::get('overview', [AdminController::class, 'overview'])->middleware('permission:audit.view');

        Route::get('opportunities', [AdminController::class, 'opportunities'])->middleware('permission:opportunity.review');
        Route::post('opportunities/{opportunity}/review', [AdminController::class, 'review'])->middleware('permission:opportunity.review');
        Route::post('opportunities/{opportunity}/publish', [AdminController::class, 'publish'])->middleware('permission:opportunity.publish');
        Route::post('opportunities/{opportunity}/cancel', [AdminController::class, 'cancel'])->middleware('permission:opportunity.publish');
        Route::post('opportunities/{opportunity}/acquisition/start', [AdminController::class, 'startAcquisition'])->middleware('permission:asset.manage');
        Route::post('opportunities/{opportunity}/acquisition', [AdminController::class, 'recordAcquisition'])->middleware('permission:asset.manage');

        Route::get('refunds', [AdminController::class, 'refunds'])->middleware('permission:refund.review');
        Route::post('refunds/{refund}/approve', [AdminController::class, 'approveRefund'])->middleware('permission:refund.approve');
        Route::post('refunds/{refund}/pay', [AdminController::class, 'payRefund'])->middleware('permission:refund.payout');

        Route::get('distributions', [AdminController::class, 'distributions'])->middleware('permission:distribution.manage');
        Route::post('distributions', [AdminController::class, 'createDistribution'])->middleware('permission:distribution.manage');
        Route::post('distributions/{distribution}/approve', [AdminController::class, 'approveDistribution'])->middleware('permission:distribution.approve');
        Route::post('distributions/{distribution}/execute', [AdminController::class, 'executeDistribution'])->middleware('permission:distribution.manage');

        Route::get('payouts', [AdminController::class, 'payouts'])->middleware('permission:payout.request');
        Route::post('payouts', [AdminController::class, 'createPayout'])->middleware('permission:payout.request');
        Route::post('payouts/{payout}/approve', [AdminController::class, 'approvePayout'])->middleware('permission:supplier.approve');
        Route::post('payouts/{payout}/pay', [AdminController::class, 'payPayout'])->middleware('permission:payout.pay');

        Route::get('ledger', [AdminController::class, 'ledger'])->middleware('permission:ledger.view');
        Route::get('reconciliation', [AdminController::class, 'portfolioAudit'])->middleware('permission:ledger.view');
        Route::get('approvals', [AdminController::class, 'approvals'])->middleware('permission:audit.view');
        Route::get('audit', [AdminController::class, 'audit'])->middleware('permission:audit.view');
        Route::get('users', [AdminController::class, 'users'])->middleware('permission:user.manage');

        Route::get('support', [SupportController::class, 'adminIndex'])->middleware('permission:support.manage');
        Route::post('support/{conversation}/messages', [SupportController::class, 'adminReply'])->middleware('permission:support.manage');
        Route::post('support/{conversation}/close', [SupportController::class, 'adminClose'])->middleware('permission:support.manage');

        Route::get('assets', [AdminController::class, 'assets'])->middleware('permission:asset.manage');
        Route::post('assets/{asset}/income', [AdminController::class, 'recordIncome'])->middleware('permission:asset.manage');
    });
});
