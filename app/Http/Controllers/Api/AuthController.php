<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\VerifyEmailRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Models\Admin;
use App\Models\Client;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\AuditService;
use App\Services\InternalMailService;
use App\Services\LoginThrottleService;
use App\Services\NotificationService;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Parité de src/Controller/AuthController.php (PHP) — mêmes clés JSON, mêmes
 * codes HTTP. Sessions PHP remplacées par tokens Sanctum (décision verrouillée).
 *
 * Endpoints :
 * - POST /api/v1/auth/register        register()        201 {message}
 * - POST /api/v1/auth/login           login()           200 {message, token, role} | 401 | 429
 * - POST /api/v1/auth/logout          logout()          200 {message}
 * - GET  /api/v1/auth/me              me()              200 {authenticated, role, name} (toujours 200)
 * - POST /api/v1/auth/password/forgot forgotPassword()   200 {message} générique anti-énumération
 * - POST /api/v1/auth/otp/verify      verifyOtp()       200 {message} | 400 | 404
 * - POST /api/v1/auth/password/reset  resetPassword()   200 {message} | 400 | 403
 * - POST /api/v1/auth/verify-email    verifyEmail()     200 | 400 | 410 | 422 (PUBLIC)
 * - POST /api/v1/auth/resend-verification resendVerification() 202 | 401 | 409 | 429 (auth)
 */
class AuthController extends Controller
{
    /**
     * Vérification de mot de passe multi-format (audit) : les admins seedés
     * via le PHP source portent un hash argon2id ($argon2id$...) alors que le
     * driver par défaut est bcrypt avec hashing.verify=true — Hash::check
     * lève alors RuntimeException (=> 500 au login admin).
     * On vérifie bcrypt d'abord (cas nominal), puis argon2id si le hash le
     * demande, via password_verify() natif qui gère les deux algorithmes.
     */
    private static function passwordMatches(string $plain, ?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }
        if (str_starts_with($stored, '$argon2')) {
            // Hash argon2id : le driver bcrypt configurerait une RuntimeException
            // (verify=true). password_verify() natif supporte tous les formats.
            return password_verify($plain, $stored);
        }
        try {
            return Hash::check($plain, $stored);
        } catch (\RuntimeException $e) {
            // Cas limite : hash non bcrypt mais sans préfixe argon2 reconnu.
            return password_verify($plain, $stored);
        }
    }

    // ------------------------------------------------------------------
    // Client : inscription
    // ------------------------------------------------------------------
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            if (Client::where('email', $data['email'])->exists()) {
                return response()->json(['error' => 'Email déjà utilisé'], 409);
            }

            $client = Client::create([
                'nom' => htmlspecialchars($data['nom']),
                'entreprise' => htmlspecialchars($data['entreprise']),
                'email' => $data['email'],
                'telephone' => htmlspecialchars($data['telephone']),
                'mot_de_passe' => Hash::make($data['mot_de_passe']),
                'date_inscription' => now()->format('Y-m-d H:i:s'),
                'statut' => 'actif',
                'role' => 'client',
            ]);

            // Utilisateur Sanctum aligné (même email que le client métier)
            User::create([
                'name' => $client->nom,
                'email' => $client->email,
                'password' => $data['mot_de_passe'],
            ]);

            AuditService::log('REGISTER', (int) $client->id, 'client', 'Compte créé');

            // Vérification e-mail (CONTRAT-VERIF.md) : token généré (hash sha256
            // stocké), e-mail envoyé via le mailer par défaut (log en test, smtp
            // en prod), email_verification_sent_at = now(). Le token clair ne sort
            // QUE dans l'e-mail, jamais dans la réponse ni les logs applicatifs.
            $token = $client->genererTokenVerification();
            $url = 'https://app.fedpawa.com/verification-email?token='.$token;
            try {
                app(NotificationService::class)->sendVerificationEmail($client, $url);
            } catch (\Throwable $mailEx) {
                Log::warning('E-mail de vérification non envoyé pour '.$client->email.': '.$mailEx->getMessage());
            }

            return response()->json(['message' => 'Compte créé avec succès', 'email_verified' => false], 201);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Register error: '.$e->getMessage());

            return response()->json(['error' => 'Erreur interne lors de la création du compte'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Client : connexion (throttle LoginThrottleService tel quel)
    // ------------------------------------------------------------------
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $input = $request->validated();
            $email = (string) ($input['email'] ?? '');
            $ip = $request->ip();

            // 1) Compte admin ? — message dédié, AUCUN échec throttle (parité PHP)
            $admin = Admin::where('email', $email)->first();
            if ($admin) {
                if (! self::passwordMatches($input['mot_de_passe'], $admin->mot_de_passe)) {
                    return response()->json([
                        'error' => 'Ceci est un compte administrateur. Utilisez la page d\'accès admin: /admin_dashboard.html',
                    ], 401);
                }
                LoginThrottleService::recordSuccess($email, 'admin');
                AuditService::log('ADMIN_LOGIN_via_client_form', (int) $admin->id, 'admin', 'Login OK from client form');

                return response()->json([
                    'message' => 'Connexion réussie',
                    'redirect' => 'admin_dashboard.html',
                    'role' => 'admin',
                ]);
            }

            // 2) Verrou actif ? — 429 avant toute vérification (parité PHP)
            $remaining = LoginThrottleService::getRemainingLock($email, 'client');
            if ($remaining > 0) {
                $minutes = (int) ceil($remaining / 60);

                return response()->json([
                    'error' => "Trop de tentatives échouées (5). Réessayez dans $minutes minute(s) ou contactez le support.",
                ], 429);
            }

            // 3) Vérification des identifiants client
            $client = Client::where('email', $email)->first();
            if (! $client || ! self::passwordMatches($input['mot_de_passe'], $client->mot_de_passe)) {
                $justLocked = LoginThrottleService::recordFailure($email, 'client', $ip);
                if ($justLocked) {
                    AuditService::log('LOGIN_LOCKED', null, 'client', "Client $email verrouillé après 5 échecs (30 min)");
                    try {
                        // Notification interne à l'admin (parité MailService->send)
                        (new InternalMailService())->send(
                            null, 'system', null, 'admin',
                            "L'utilisateur $email a atteint la limite de 5 tentatives de connexion et doit maintenant attendre 30 minutes."
                        );
                    } catch (\Throwable $mailEx) {
                        Log::warning('Throttle admin notify failed: '.$mailEx->getMessage());
                    }
                }

                return response()->json(
                    $justLocked
                        ? ['error' => 'Trop de tentatives échouées (5). Votre compte est bloqué pendant 30 minutes.']
                        : ['error' => 'Identifiants invalides'],
                    $justLocked ? 429 : 401
                );
            }

            // 4) Succès : reset du throttle, audit, token Sanctum
            LoginThrottleService::recordSuccess($email, 'client');
            $role = (string) ($client->role ?? 'client');
            AuditService::log('CLIENT_LOGIN', (int) $client->id, 'client', 'Login OK');

            // Notification catalogue : compte_securite — connexion depuis un nouvel appareil
            try {
                $uaHash = hash('sha256', (string) $request->userAgent());
                $device = DB::table('login_devices')
                    ->where('client_id', (int) $client->id)
                    ->where('ua_hash', $uaHash)
                    ->first();
                if (! $device) {
                    DB::table('login_devices')->insert([
                        'client_id' => (int) $client->id,
                        'ua_hash' => $uaHash,
                        'ip' => $ip,
                        'first_seen_at' => now()->format('Y-m-d H:i:s'),
                        'last_seen_at' => now()->format('Y-m-d H:i:s'),
                    ]);
                    app(PushService::class)->notifyEvent(
                        (int) $client->id,
                        'compte_securite',
                        'Nouvelle connexion à votre compte',
                        "Une connexion depuis un nouvel appareil a été détectée (IP {$ip}). Si ce n'est pas vous, changez votre mot de passe immédiatement.",
                        '/espace-client?tab=securite'
                    );
                } else {
                    DB::table('login_devices')->where('id', $device->id)->update([
                        'last_seen_at' => now()->format('Y-m-d H:i:s'),
                        'ip' => $ip,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('compte_securite (login) non fatal : '.$e->getMessage());
            }

            $user = $this->sanctumUserFor($client);
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'message' => 'Connexion réussie',
                'token' => $token,
                'role' => $role,
                'email_verified' => $client->estVerifie(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Login error: '.$e->getMessage());

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Déconnexion : révocation du token courant
    // ------------------------------------------------------------------
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté']);
    }

    // ------------------------------------------------------------------
    // Profil courant : parité me() — 200 même non authentifié
    // ------------------------------------------------------------------
    public function me(Request $request): JsonResponse
    {
        // Route publique (parité PHP : 200 {authenticated:false} si anonyme) —
        // on interroge explicitement le guard sanctum, sinon le guard par
        // défaut 'web' (session) ne voit jamais le Bearer token.
        $user = $request->user('sanctum');
        if (! $user) {
            return response()->json(['authenticated' => false], 200);
        }

        $client = Client::where('email', $user->email)->first();

        // Parité public/index.php (route /api/me) : le client connecté reçoit
        // aussi 'email' et 'kyc_status' = statut du DERNIER dossier KYC
        // (null si jamais soumis). Le tunnel s'en sert pour sauter l'étape
        // KYC d'un client déjà vérifié. Dégradation gracieuse (null) si la
        // table kyc est absente — même comportement que le legacy.
        $kycStatus = null;
        if ($client) {
            try {
                $kycStatus = DB::table('kyc')
                    ->where('client_id', $client->id)
                    ->orderByDesc('id')
                    ->value('statuts') ?: null;
            } catch (\Throwable $kycErr) {
                $kycStatus = null;
            }
        }

        return response()->json([
            'authenticated' => true,
            'role' => (string) ($client->role ?? 'client'),
            'name' => (string) ($client->nom ?? $user->name),
            'email' => (string) ($client->email ?? $user->email),
            'kyc_status' => $kycStatus,
            'email_verified' => $client ? $client->estVerifie() : false,
        ], 200);
    }

    // ------------------------------------------------------------------
    // Vérification e-mail : POST /api/v1/auth/verify-email — PUBLIC
    // Contrat (CONTRAT-VERIF.md) :
    //   200 {verified:true}                      succès (token consommé)
    //   200 {verified:true, deja_verifie:true}   client déjà vérifié
    //   410 {code:"lien_expire"}                 TTL 72 h dépassé
    //   400 {code:"lien_invalide"}               token inconnu OU déjà utilisé
    //   422                                       token malformé (validation)
    // ------------------------------------------------------------------
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        try {
            $token = (string) $request->validated('token');

            $client = Client::trouverParTokenVerification($token);
            if (! $client) {
                // Inconnu OU déjà consommé (marquerVerifie() efface le hash).
                return response()->json([
                    'code' => 'lien_invalide',
                    'message' => 'Lien de vérification invalide ou déjà utilisé.',
                ], 400);
            }

            if ($client->estVerifie()) {
                return response()->json(['verified' => true, 'deja_verifie' => true], 200);
            }

            if ($client->tokenVerificationExpire()) {
                // On consomme le hash : un lien expiré ne redeviendra jamais valide.
                $client->email_verification_hash = null;
                $client->save();

                return response()->json([
                    'code' => 'lien_expire',
                    'message' => 'Ce lien de vérification a expiré (72 h). Demandez un nouveau lien depuis votre espace client.',
                ], 410);
            }

            $client->marquerVerifie();
            AuditService::log('EMAIL_VERIFIED', (int) $client->id, 'client', 'Adresse e-mail vérifiée');

            return response()->json(['verified' => true], 200);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Verify email error: '.$e->getMessage());

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Renvoi du lien : POST /api/v1/auth/resend-verification — auth requis
    // Contrat : uniquement si non vérifié -> 202 {envoye:true} ;
    // throttle 60 s -> 429 {code:"deja_envoye", retry_after:<s>}.
    // ------------------------------------------------------------------
    public function resendVerification(Request $request): JsonResponse
    {
        try {
            $user = $request->user('sanctum');
            $client = $user ? Client::where('email', $user->email)->first() : null;
            if (! $client) {
                return response()->json(['error' => 'Client introuvable'], 404);
            }

            if ($client->estVerifie()) {
                return response()->json([
                    'code' => 'deja_verifie',
                    'message' => 'Votre adresse e-mail est déjà vérifiée.',
                ], 409);
            }

            // Throttle 60 s basé sur email_verification_sent_at (déterministe).
            $sentAt = $client->email_verification_sent_at;
            if ($sentAt !== null) {
                $elapsed = now()->timestamp - $sentAt->timestamp;
                if ($elapsed < 60) {
                    return response()->json([
                        'code' => 'deja_envoye',
                        'message' => 'Un e-mail de vérification vient déjà d\'être envoyé. Réessayez dans quelques secondes.',
                        'retry_after' => 60 - $elapsed,
                    ], 429);
                }
            }

            $token = $client->genererTokenVerification();
            $url = 'https://app.fedpawa.com/verification-email?token='.$token;
            try {
                app(NotificationService::class)->sendVerificationEmail($client, $url);
            } catch (\Throwable $mailEx) {
                Log::warning('Resend verification non envoyé pour '.$client->email.': '.$mailEx->getMessage());
            }

            AuditService::log('VERIFICATION_RESENT', (int) $client->id, 'client', 'Lien de vérification renvoyé');

            return response()->json(['envoye' => true], 202);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Resend verification error: '.$e->getMessage());

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Mot de passe oublié : réponse générique anti-énumération
    // ------------------------------------------------------------------
    public function forgotPassword(PasswordResetRequest $request): JsonResponse
    {
        try {
            $email = (string) $request->validated('email');
            $generic = 'Si cet e-mail existe, un code de réinitialisation a été envoyé.';
            $client = Client::where('email', $email)->first();

            if (! $client) {
                return response()->json(['message' => $generic]);
            }

            // Invalider les anciens codes non utilisés (parité PHP)
            PasswordReset::where('client_id', $client->id)->where('used', 0)->update(['used' => 1]);

            $token = sprintf('%06d', random_int(1, 999999));
            PasswordReset::create([
                'client_id' => $client->id,
                'token_hash' => Hash::make($token),
                'expires_at' => now()->addMinutes(15),
                'used' => 0,
                'created_at' => now(),
            ]);

            $otpSent = false;
            try {
                $otpSent = (new NotificationService())->sendOtp($email, $token);
            } catch (\Throwable $mailEx) {
                Log::warning('OTP mail envoi échoué: '.$mailEx->getMessage());
            }
            if (! $otpSent) {
                // Le code n'est JAMAIS écrit en clair dans les logs (interdit).
                Log::info("[OTP] transport e-mail non configuré — aucun code envoyé ni journalisé pour {$email}");
            }

            return response()->json(['message' => $generic]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Password reset request error: '.$e->getMessage());

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Vérification OTP (e-mail remplace la session, API stateless)
    // ------------------------------------------------------------------
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $email = (string) $data['email'];

            $client = Client::where('email', $email)->first();
            if (! $client) {
                return response()->json(['error' => 'Client introuvable'], 404);
            }

            $reset = PasswordReset::where('client_id', $client->id)
                ->where('used', 0)
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->first();

            if (! $reset || ! Hash::check((string) $data['otp'], $reset->token_hash)) {
                return response()->json(['error' => 'Code OTP invalide ou expiré'], 400);
            }

            return response()->json(['message' => 'OTP vérifié avec succès']);
        } catch (\Throwable $e) {
            report($e);
            Log::error('OTP verify error: '.$e->getMessage());

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Réinitialisation : e-mail + otp remplacent la session vérifiée
    // ------------------------------------------------------------------
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $data = $request->all();
            $email = (string) ($data['email'] ?? '');

            // Équivalent stateless du 403 « Session de réinitialisation non autorisée » :
            // e-mail + OTP valides et non consommés requis.
            $client = $email !== '' ? Client::where('email', $email)->first() : null;
            $reset = $client ? PasswordReset::where('client_id', $client->id)
                ->where('used', 0)
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->first() : null;

            if (! $reset || ! Hash::check((string) ($data['otp'] ?? ''), $reset->token_hash)) {
                return response()->json(['error' => 'Session de réinitialisation non autorisée'], 403);
            }

            $client->mot_de_passe = Hash::make($data['mot_de_passe']);
            $client->save();

            // Consommer tous les codes du client (parité PHP) + audit
            PasswordReset::where('client_id', $client->id)->update(['used' => 1]);
            AuditService::log('PASSWORD_RESET', (int) $client->id, 'client', 'Reset done');

            // Garder le mot de passe Sanctum synchronisé
            User::updateOrCreate(
                ['email' => $client->email],
                ['name' => $client->nom, 'password' => $data['mot_de_passe']]
            );

            // Notification catalogue : compte_securite — mot de passe modifié (best-effort)
            try {
                app(PushService::class)->notifyEvent(
                    (int) $client->id,
                    'compte_securite',
                    'Votre mot de passe a été modifié',
                    'Le mot de passe de votre compte FEDPAWA vient d\'être modifié. Si ce n\'est pas vous, contactez immédiatement le support.',
                    '/espace-client?tab=securite'
                );
            } catch (\Throwable $e) {
                Log::warning('compte_securite (reset) non fatal : '.$e->getMessage());
            }

            return response()->json(['message' => 'Mot de passe mis à jour avec succès']);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Reset password error: '.$e->getMessage());

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Helper : utilisateur Sanctum aligné sur le client métier
    // ------------------------------------------------------------------
    private function sanctumUserFor(Client $client): User
    {
        return User::updateOrCreate(
            ['email' => $client->email],
            ['name' => $client->nom, 'password' => $client->mot_de_passe]
        );
    }
}
