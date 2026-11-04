<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CourierController;
use App\Http\Controllers\Api\CourrierController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\MailController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\SouscriptionController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\AdminMailController;
use App\Http\Controllers\Api\AdminPublicationController;
use App\Http\Controllers\Api\PublicationController;
use App\Http\Controllers\Api\PublicationLectureController;
use App\Http\Controllers\Api\ResendWebhookController;
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

        // Vérification e-mail (CONTRAT-VERIF.md) : PUBLIC, pas d'auth requise
        Route::post('/verify-email', [AuthController::class, 'verifyEmail']);

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            // Renvoi du lien de vérification : auth requise, throttle 60 s côté contrôleur
            Route::post('/resend-verification', [AuthController::class, 'resendVerification']);
        });
    });
    // ===== Fin section BE-AUTH =====

    // Webhook Campay : serveur-à-serveur, signature HMAC vérifiée dans le contrôleur
    // (pas de middleware auth ; exempté CSRF par nature — API stateless).
    Route::post('/webhooks/campay', [WebhookController::class, 'handleCampay']);

    // ===== Section RESEND-MAIL (CONTRAT-MAIL.md §2) — webhook public Resend =====
    Route::post('/webhooks/resend', [ResendWebhookController::class, 'handle']);
    // ===== Fin section RESEND-MAIL (webhook) =====

    // ===== Section PUSH PWA =====
    // VAPID public : la clé publique n'est pas un secret (le PWA en a besoin
    // avant login pour PushManager.subscribe) — PUBLIC, sans auth.
    Route::get('/push/vapid', [PushController::class, 'vapid']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/push/subscribe', [PushController::class, 'subscribe'])->middleware('email_verified');
        Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);

        // Notifications in-app du client (20/page, ?unread=1)
        Route::get('/notifications', [PushController::class, 'notifications']);
        Route::post('/notifications/read', [PushController::class, 'read']);

        // Préférences de notification (contrat PWA) : GET complet, PUT partiel
        Route::get('/notifications/preferences', [PushController::class, 'preferences']);
        Route::put('/notifications/preferences', [PushController::class, 'updatePreferences']);
    });
    // ===== Fin section PUSH PWA =====


    Route::middleware('auth:sanctum')->group(function (): void {
        // Tunnel souscription (flux le plus critique) — gating e-mail vérifié (CONTRAT-VERIF.md)
        Route::post('/souscription', [SouscriptionController::class, 'process'])->middleware('email_verified');
        Route::post('/signature', [SouscriptionController::class, 'finalize'])->middleware('email_verified');
        Route::post('/paiement/verifier', [SouscriptionController::class, 'verifyPayment'])->middleware('email_verified');
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

            // Dashboard admin (parité AdminController source, public/index.php
            // lignes 75-76, 81-86) — listes, KPI, éditeur template L012, tickets
            Route::get('/admin/lists', [AdminController::class, 'lists']);
            Route::get('/admin/overview', [AdminController::class, 'overview']);
            Route::get('/admin/template', [AdminController::class, 'getTemplate']);
            Route::post('/admin/template/otp-request', [AdminController::class, 'templateOtpRequest']);
            Route::post('/admin/template/otp-verify', [AdminController::class, 'templateOtpVerify']);
            Route::post('/admin/template/update', [AdminController::class, 'updateTemplate']);
            Route::post('/admin/ticket/reply', [AdminController::class, 'ticketReply']);
        });
    });


    // ===== Section PUBLICATIONS (CONTRAT-PUBLICATIONS.md §3) =====
    Route::get('/publications/non-lues', [PublicationLectureController::class, 'nonLues'])->middleware('auth:sanctum');
    Route::get('/publications', [PublicationController::class, 'index']);
    Route::get('/publications/{id}', [PublicationController::class, 'show'])->whereNumber('id');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/publications/tout-lu', [PublicationLectureController::class, 'toutLu']);
        Route::post('/publications/{id}/lu', [PublicationLectureController::class, 'marquerLu'])->whereNumber('id');

        Route::middleware(CheckAdminRole::class)->prefix('admin')->group(function (): void {
            Route::get('/publications', [AdminPublicationController::class, 'index']);
            Route::post('/publications', [AdminPublicationController::class, 'store']);
            Route::put('/publications/{id}', [AdminPublicationController::class, 'update'])->whereNumber('id');
            Route::post('/publications/{id}/medias', [AdminPublicationController::class, 'ajouterMedia'])->whereNumber('id');
            Route::delete('/publications/{id}/medias/{mediaId}', [AdminPublicationController::class, 'supprimerMedia'])->whereNumber('id')->whereNumber('mediaId');
            Route::post('/publications/{id}/publier', [AdminPublicationController::class, 'publier'])->whereNumber('id');
            Route::post('/publications/{id}/depublier', [AdminPublicationController::class, 'depublier'])->whereNumber('id');
            Route::delete('/publications/{id}', [AdminPublicationController::class, 'destroy'])->whereNumber('id');
        });
    });
    // ===== Fin section PUBLICATIONS =====

    // ===== Section RESEND-MAIL (CONTRAT-MAIL.md §2) — boîte de réception admin =====
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::middleware(CheckAdminRole::class)->prefix('admin')->group(function (): void {
            Route::get('/mails', [AdminMailController::class, 'index']);
            Route::get('/mails/count', [AdminMailController::class, 'count']);
            Route::post('/mails/tout-lu', [AdminMailController::class, 'toutLu']);
            Route::get('/mails/{id}', [AdminMailController::class, 'show'])->whereNumber('id');
            Route::post('/mails/{id}/lu', [AdminMailController::class, 'marquerLu'])->whereNumber('id');
        });
    });
    // ===== Fin section RESEND-MAIL (admin) =====

    // ===== Fin section BE-EDGE =====
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
