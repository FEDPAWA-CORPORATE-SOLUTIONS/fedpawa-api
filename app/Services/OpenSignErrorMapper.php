<?php

namespace App\Services;

/**
 * Cartographie des messages d'erreur OpenSign vers des messages lisibles
 * en français, avec une action recommandée pour l'administrateur.
 *
 * OpenSign retourne des erreurs HTTP et/ou des corps JSON avec des codes
 * et messages propres. Ce service les traduit en messages métier compréhensibles
 * par un administrateur non technique, et indique si l'erreur est récupérable
 * automatiquement (retry, refresh token) ou nécessite une intervention manuelle.
 */
class OpenSignErrorMapper
{
    /**
     * @param  \Throwable  $e
     * @return array{
     *     code: string,
     *     message: string,
     *     action: string,
     *     auto_recoverable: bool,
     *     notify_admin: bool,
     *     severity: 'low'|'medium'|'high'|'critical'
     * }
     */
    public static function map(\Throwable $e): array
    {
        $message = $e->getMessage();
        $code = $e->getCode() ?: 'UNKNOWN';

        // Détection d'un code HTTP dans le message (ex: "OpenSign API Error [401]: ...")
        preg_match('/\[(\d{3})\]/', $message, $httpMatches);
        $httpCode = $httpMatches[1] ?? null;

        // Détection d'un code d'erreur OpenSign dans le corps JSON
        preg_match('/"error"\s*:\s*"([^"]+)"/', $message, $jsonMatches);
        $opensignError = $jsonMatches[1] ?? null;

        return self::classify($httpCode, $opensignError, $message, $code);
    }

    /**
     * Classifie l'erreur et retourne un dictionnaire lisible.
     */
    private static function classify(?string $httpCode, ?string $opensignError, string $rawMessage, string $code): array
    {
        // -----------------------------------------------------------
        // 401 — Token expiré ou invalide (récupérable automatiquement)
        // -----------------------------------------------------------
        if ($httpCode === '401' || str_contains($rawMessage, '401')) {
            return [
                'code' => 'OPEENSIGN_UNAUTHORIZED',
                'message' => 'La session OpenSign a expiré ou est invalide.',
                'action' => 'Le système renouvelle automatiquement le token et réessaie l\'opération. Si l\'erreur persiste, vérifiez les identifiants OPENSIGN_SERVICE_EMAIL / OPENSIGN_SERVICE_PASSWORD.',
                'auto_recoverable' => true,
                'notify_admin' => false,
                'severity' => 'low',
            ];
        }

        // -----------------------------------------------------------
        // 403 — Accès refusé (droits insuffisants sur le document/enveloppe)
        // -----------------------------------------------------------
        if ($httpCode === '403' || str_contains($rawMessage, '403')) {
            return [
                'code' => 'OPEENSIGN_FORBIDDEN',
                'message' => 'OpenSign refuse l\'accès à cette opération (droits insuffisants).',
                'action' => 'Vérifiez que le compte de service OpenSign a les droits sur ce document/enveloppe. Intervention manuelle requise.',
                'auto_recoverable' => false,
                'notify_admin' => true,
                'severity' => 'high',
            ];
        }

        // -----------------------------------------------------------
        // 404 — Document ou enveloppe introuvable
        // -----------------------------------------------------------
        if ($httpCode === '404' || str_contains($rawMessage, '404')) {
            return [
                'code' => 'OPEENSIGN_NOT_FOUND',
                'message' => 'Le document ou l\'enveloppe OpenSign est introuvable.',
                'action' => 'L\'enveloppe de signature a peut-être été supprimée côté OpenSign. Recréez une enveloppe pour ce contrat.',
                'auto_recoverable' => false,
                'notify_admin' => true,
                'severity' => 'medium',
            ];
        }

        // -----------------------------------------------------------
        // 422 / 400 — Requête malformée (données d'envoi incorrectes)
        // -----------------------------------------------------------
        if (in_array($httpCode, ['400', '422'], true) || str_contains($rawMessage, '422') || str_contains($rawMessage, '400')) {
            return [
                'code' => 'OPEENSIGN_BAD_REQUEST',
                'message' => 'La requête envoyée à OpenSign est malformée ou incomplète.',
                'action' => 'Vérifiez les données envoyées (document_url, signers, message). Correction côté code requise.',
                'auto_recoverable' => false,
                'notify_admin' => true,
                'severity' => 'high',
            ];
        }

        // -----------------------------------------------------------
        // 429 — Trop de requêtes (rate limiting)
        // -----------------------------------------------------------
        if ($httpCode === '429' || str_contains($rawMessage, '429')) {
            return [
                'code' => 'OPEENSIGN_RATE_LIMIT',
                'message' => 'Trop de requêtes envoyées à OpenSign (limite dépassée).',
                'action' => 'Attendez quelques instants avant de réessayer. Le système peut réessayer automatiquement après un délai.',
                'auto_recoverable' => true,
                'notify_admin' => false,
                'severity' => 'low',
            ];
        }

        // -----------------------------------------------------------
        // 500+ — Erreur serveur OpenSign
        // -----------------------------------------------------------
        if ($httpCode && $httpCode[0] === '5') {
            return [
                'code' => 'OPEENSIGN_SERVER_ERROR',
                'message' => 'Erreur côté serveur OpenSign (indisponible temporairement).',
                'action' => 'Réessayez ultérieurement. Si l\'erreur persiste, contactez le support OpenSign.',
                'auto_recoverable' => true,
                'notify_admin' => false,
                'severity' => 'medium',
            ];
        }

        // -----------------------------------------------------------
        // Erreurs spécifiques OpenSign (corps JSON "error")
        // -----------------------------------------------------------
        if ($opensignError) {
            return self::mapOpenSignError($opensignError, $rawMessage);
        }

        // -----------------------------------------------------------
        // Erreur de connexion / réseau (DNS, timeout, refus de connexion)
        // -----------------------------------------------------------
        if (str_contains($rawMessage, 'connection')
            || str_contains($rawMessage, 'timeout')
            || str_contains($rawMessage, 'refused')
            || str_contains($rawMessage, 'resolve')
            || str_contains($rawMessage, 'unreachable')) {
            return [
                'code' => 'OPEENSIGN_NETWORK',
                'message' => 'Impossible de joindre le service OpenSign (réseau indisponible).',
                'action' => 'Vérifiez la connectivité réseau vers OPENSIGN_BASE_URL. Réessayez automatiquement.',
                'auto_recoverable' => true,
                'notify_admin' => false,
                'severity' => 'medium',
            ];
        }

        // -----------------------------------------------------------
        // Erreur d'authentification (mauvais login/password)
        // -----------------------------------------------------------
        if (str_contains($message, 'login') && str_contains($message, 'error')) {
            return [
                'code' => 'OPEENSIGN_LOGIN_FAILED',
                'message' => 'L\'authentification auprès d\'OpenSign a échoué.',
                'action' => 'Vérifiez OPENSIGN_SERVICE_EMAIL et OPENSIGN_SERVICE_PASSWORD dans la configuration.',
                'auto_recoverable' => false,
                'notify_admin' => true,
                'severity' => 'critical',
            ];
        }

        // -----------------------------------------------------------
        // Erreur inconnue — notification admin pour investigation
        // -----------------------------------------------------------
        return [
            'code' => 'OPEENSIGN_UNKNOWN',
            'message' => 'Erreur OpenSign non identifiée : '.$rawMessage,
            'action' => 'Consultez les logs détaillés et contactez le support OpenSign si nécessaire.',
            'auto_recoverable' => false,
            'notify_admin' => true,
            'severity' => 'high',
        ];
    }

    /**
     * Mappe un code d'erreur OpenSign spécifique (corps JSON "error").
     */
    private static function mapOpenSignError(string $opensignError, string $rawMessage): array
    {
        $errorLower = strtolower($opensignError);

        if (str_contains($errorLower, 'envelope') && str_contains($errorLower, 'not found')) {
            return [
                'code' => 'OPEENSIGN_ENVELOPE_NOT_FOUND',
                'message' => 'L\'enveloppe de signature n\'existe plus côté OpenSign.',
                'action' => 'Recréez une enveloppe de signature pour ce contrat.',
                'auto_recoverable' => false,
                'notify_admin' => true,
                'severity' => 'medium',
            ];
        }

        if (str_contains($errorLower, 'document') && str_contains($errorLower, 'invalid')) {
            return [
                'code' => 'OPEENSIGN_INVALID_DOCUMENT',
                'message' => 'Le document PDF envoyé à OpenSign est invalide ou corrompu.',
                'action' => 'Vérifiez le fichier PDF source avant de renvoyer à la signature.',
                'auto_recoverable' => false,
                'notify_admin' => true,
                'severity' => 'high',
            ];
        }

        if (str_contains($errorLower, 'signer') && str_contains($errorLower, 'invalid')) {
            return [
                'code' => 'OPEENSIGN_INVALID_SIGNER',
                'message' => 'Un signataire renseigné est invalide (email manquant ou incorrect).',
                'action' => 'Corrigez les informations des signataires dans la demande de signature.',
                'auto_recoverable' => false,
                'notify_admin' => true,
                'severity' => 'high',
            ];
        }

        if (str_contains($errorLower, 'already signed') || str_contains($errorLower, 'déjà signé')) {
            return [
                'code' => 'OPEENSIGN_ALREADY_SIGNED',
                'message' => 'Ce document a déjà été signé.',
                'action' => 'Aucune action nécessaire, le contrat est déjà scellé.',
                'auto_recoverable' => false,
                'notify_admin' => false,
                'severity' => 'low',
            ];
        }

        // Erreur OpenSign spécifique non répertoriée
        return [
            'code' => 'OPEENSIGN_API_ERROR',
            'message' => 'Erreur OpenSign : '.$opensignError,
            'action' => 'Consultez la documentation OpenSign pour ce code d\'erreur.',
            'auto_recoverable' => false,
            'notify_admin' => true,
            'severity' => 'medium',
        ];
    }
}
