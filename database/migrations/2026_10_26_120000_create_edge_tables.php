<?php

/*
 * ============================================================================
 * EDGE SCHEMA (BE-EDGE) — tables métier Fedpawa, noms EXACTS du legacy
 * (schéma de référence : /root/Fedpawa/fedpawa_light/schema_sqlite.sql).
 *
 * ATTENTION : la vague D (BE-EDGE) dépend de tables normalement livrées par
 * BE-SCHEMA (vague A). Au moment où cette migration a été écrite, BE-SCHEMA
 * n'avait RIEN commis (attente > 9 min). Conformément à la consigne
 * (« écris quand même avec les noms de tables/modèles attendus documentés »),
 * ce fichier crée les tables manquantes via Schema::hasTable() (no-op si
 * BE-SCHEMA les livre ensuite : aucune duplication, rien à merger).
 *
 * Tables créées si absentes (consommées par les 7 contrôleurs périphériques) :
 *   contact_messages, tickets_support, internal_messages, courriers, audit_log.
 * Tables attendues de BE-SCHEMA / d'autres vagues (NON créées ici) :
 *   clients, abonnements, factures, contrats, kyc, admins, paiements,
 *   password_resets, contract_templates, contract_history, login_throttle.
 *
 * Modèles Eloquent attendus dans app/Models (périmètre BE-SCHEMA) :
 *   App\Models\Client, App\Models\ContactMessage, App\Models\SupportTicket,
 *   App\Models\InternalMessage, App\Models\Courrier, App\Models\Facture,
 *   App\Models\Contrat, App\Models\Kyc, App\Models\Abonnement, App\Models\AuditLog.
 * Les contrôleurs edge y font référence via DB::table() ou ces classes si
 * présentes ; les tests bootstrappent le nécessaire localement.
 * ============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_messages')) {
            Schema::create('contact_messages', function (Blueprint $table) {
                $table->id();
                $table->string('nom');
                $table->string('email');
                $table->string('telephone')->nullable();
                $table->string('sujet');
                $table->text('message');
                $table->string('statut')->default('nouveau');
                $table->datetime('date_reception')->nullable();
                $table->index('statut');
            });
        }

        if (! Schema::hasTable('tickets_support')) {
            Schema::create('tickets_support', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('client_id');
                $table->string('sujet');
                $table->text('message');
                $table->string('statut')->default('open');
                $table->timestamp('created_at')->nullable();
                $table->text('reponse')->nullable();
                $table->datetime('reponse_at')->nullable();
                $table->index('client_id');
            });
        }

        if (! Schema::hasTable('internal_messages')) {
            Schema::create('internal_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('sender_id')->nullable();
                $table->string('sender_type');
                $table->unsignedInteger('receiver_id')->nullable();
                $table->string('receiver_type');
                $table->text('content');
                $table->boolean('is_read')->default(false);
                $table->timestamp('created_at')->nullable();
                $table->index(['sender_id', 'receiver_id']);
            });
        }

        if (! Schema::hasTable('courriers')) {
            Schema::create('courriers', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('client_id');
                $table->datetime('date_reception')->nullable();
                $table->string('scan')->nullable();
                $table->string('statut')->default('non_lu');
                $table->index('client_id');
            });
        }

        if (! Schema::hasTable('audit_log')) {
            Schema::create('audit_log', function (Blueprint $table) {
                $table->id();
                $table->string('action');
                $table->unsignedInteger('user_id')->nullable();
                $table->string('user_type')->nullable();
                $table->timestamp('timestamp')->nullable();
                $table->text('details')->nullable();
                $table->index(['action', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        // Ne rien dropper : ces tables peuvent avoir été créées par BE-SCHEMA.
    }
};
