<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CourierController;
use App\Http\Controllers\Api\CourrierController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\MailController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\SouscriptionController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\CheckAdminRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ===== Section BE-BUSINESS (tunnel souscription / KYC / Campay / facturation) =====
// Parité routes source : public/index.php (lignes 19-24, 66, 79-80, 90).
// Auth Sanctum : le client connecté prime (parité requireClient session).

Route::prefix('v1')->group(function (): void {

    // ===== Section BE-AUTH (parité src/Controller/AuthController.php) =====
    // Mêmes contrats JSON/codes HTTP que le PHP source ; sessions → tokens Sanctum.
    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);
        Route::post('/password/reset', [AuthController::class, 'resetPassword']);
        Route::get('/me', [AuthController::class, 'me']); // parité PHP : 200 {authenticated:false} si non connecté

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });
    // ===== Fin section BE-AUTH =====

    // Webhook Campay : serveur-à-serveur, signature HMAC vérifiée dans le contrôleur
    // (pas de middleware auth ; exempté CSRF par nature — API stateless).
    Route::post('/webhooks/campay', [WebhookController::class, 'handleCampay']);

    Route::middleware('auth:sanctum')->group(function (): void {
        // Tunnel souscription (flux le plus critique)
        Route::post('/souscription', [SouscriptionController::class, 'process']);
        Route::post('/signature', [SouscriptionController::class, 'finalize']);
        Route::post('/paiement/verifier', [SouscriptionController::class, 'verifyPayment']);
        Route::get('/contract/preview', [InvoiceController::class, 'previewContract']);
        Route::get('/contract/download', [InvoiceController::class, 'downloadContract']);

        // KYC
        Route::post('/kyc/submit', [KycController::class, 'submit']);
        Route::middleware(CheckAdminRole::class)->group(function (): void {
            Route::post('/admin/kyc/approve', [KycController::class, 'approve']);
            Route::post('/admin/kyc/reject', [KycController::class, 'reject']);
        });

        // Facturation
        Route::get('/factures', [InvoiceController::class, 'index']);
        Route::get('/factures/{id}/pdf', [InvoiceController::class, 'download'])->whereNumber('id');
    });

    // ===== Fin section BE-BUSINESS =====

    // ===== Section BE-EDGE (périphérie : contact, support, mail interne,
    // courrier/courier, storage, dashboard) — parité public/index.php
    // lignes 26, 30-31, 61-62, 64-65, 67-70, 78, 87 =====

    // Contact public (anonyme) — honeypot « site », notification admin best-effort
    Route::post('/contact', [ContactController::class, 'store']);

    Route::middleware('auth:sanctum')->group(function (): void {
        // Dashboard espace client + mise à jour profil
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::post('/profil/update', [DashboardController::class, 'updateProfile']);

        // Courriers (scans) — le client voit SES courriers (date_reception DESC)
        Route::get('/courriers', [CourrierController::class, 'index']);
        // Upload multi-pages courier (client ou admin, garde interne contrôleur)
        Route::post('/courrier/upload', [CourierController::class, 'upload']);

        // Support : création + listing (50 derniers)
        Route::post('/support', [SupportController::class, 'store']);
        Route::get('/support/tickets', [SupportController::class, 'myTickets']);

        // Messagerie interne client<->admin
        Route::get('/mail/thread', [MailController::class, 'thread']);
        Route::post('/mail/send', [MailController::class, 'send']);
        Route::post('/mail/reply', [MailController::class, 'reply']); // garde admin interne (403 si client)

        // Documents : ownership dans le contrôleur (client = ses docs, admin = tout),
        // anti path-traversal, factures générées à la volée (L019)
        Route::get('/document', [StorageController::class, 'serve']);

        // Admin (garde CheckAdminRole : 401 non connecté, 403 client)
        Route::middleware(CheckAdminRole::class)->group(function (): void {
            Route::get('/admin/document-info', [StorageController::class, 'documentInfo']);
            Route::post('/admin/courrier/add', [CourrierController::class, 'add']);
        });
    });

    // ===== Fin section BE-EDGE =====
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
