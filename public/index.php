<?php
/**
 * Front Controller — handles ALL routing for the PHP built-in server.
 * This ensures every request goes through Laravel's router, including /api/* paths.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ---- Route TO controller based on URI ----

// API routes — ALL /api/* paths go through the API router
if (preg_match('#^/api(.*)#', $uri)) {
    // Bootstrap the API router
    $router = new \App\Router();
    
    // CSRF token
    $router->get('/api/csrf-token', function() {
        header('Content-Type: application/json');
        echo json_encode(['csrf_token' => \App\Controller\Controller::getCsrfToken()]);
    });
    
    // Subscription routes
    $router->post('/api/souscription', 'SubscriptionController@process');
    $router->post('/api/signature', 'SubscriptionController@finalize');
    $router->post('/api/paiement/verifier', 'SubscriptionController@verifyPayment');
    $router->get('/api/contract/preview', 'SubscriptionController@preview');
    $router->get('/api/contract/download', 'SubscriptionController@download');
    
    // Auth routes
    $router->post('/api/login', 'AuthController@login');
    $router->post('/api/logout', 'AuthController@logout');
    $router->post('/api/register', 'AuthController@register');
    
    // Contact route
    $router->post('/api/contact', 'ContactController@store');
    
    // Password routes
    $router->post('/api/password/request', 'AuthController@requestPasswordReset');
    $router->post('/api/password/verify', 'AuthController@verifyOtp');
    $router->post('/api/password/reset', 'AuthController@resetPassword');
    
    // Dashboard routes
    $router->get('/api/dashboard', 'DashboardController@index');
    $router->post('/api/profil/update', 'DashboardController@updateProfile');
    
    // Me route — returns user profile with KYC status
    $router->get('/api/me', function() {
        header('Content-Type: application/json');
        
        // Check admin role
        if (isset($_SESSION['admin_logged_in'])) {
            echo json_encode([
                'authenticated' => true,
                'role' => 'admin',
                'name' => 'Administration',
                'email' => $_SESSION['admin_email'] ?? ''
            ]);
        }
        // Check client role
        elseif (!isset($_SESSION['client_id'])) {
            echo json_encode(['authenticated' => false]);
        }
        else {
            try {
                $kycDb = \App\Service\Database::getConnection();
                $kycStmt = $kycDb->prepare("SELECT statuts FROM kyc WHERE client_id = ? ORDER BY id DESC LIMIT 1");
                $kycStmt->execute([$_SESSION['client_id']]);
                $kycStatus = $kycStmt->fetchColumn() ?: null;
            } catch (\Throwable $kycErr) {
                $kycStatus = null;
            }
            
            echo json_encode([
                'authenticated' => true,
                'role' => $_SESSION['role'] ?? 'client',
                'name' => $_SESSION['client_name'] ?? '',
                'email' => $_SESSION['client_email'] ?? '',
                'kyc_status' => $kycStatus
            ]);
        }
    });
    
    // Fin des routes API
}

// Standard Laravel front controller fallback
// Toutes les autres requêtes sont redirigées vers le routeur Laravel
';

// Fallback vers Laravel (nécessite que le serveur soit configuré en mode FPM)
// header('Location: /');

// Fin du contrôleur frontal
