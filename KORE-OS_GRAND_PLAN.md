# KORE-OS — Grand Plan d'Implémentation

**Version:** 1.0  
**Statut:** En cours de rédaction (section par section)  
**Objectif:** Passer de l'existant (Hermes sur Telegram + Kore-OS_TEP séparé) au projet souhaité (KORE-OS fusionné avec WPA, classes de workers, supervision stricte, chaîne de notification)  
**Commanditaire:** K Ray Nobun (The_Korp)

---

## Table des matières (progression)

1. État des lieux — ce qui existe déjà — RÉDIGÉ  
2. Principe directeur du plan — RÉDIGÉ  
3. Phase 0 — Préparation et validation des décisions — RÉDIGÉ  
4. Phase 1 — Fondation : classes de workers, rôles, compétences — EN COURS  
5. Phase 2 — Moteur core : flux de tâche, délégation, chaîne de notification — PLANIFIÉ  
6. Phase 3 — Interface : WPA, tableau de bord, sessions, Lanes — PLANIFIÉ  
7. Phase 4 — Fiabilité : checkpoints, snapshots, reprise après crash — PLANIFIÉ  
8. Phase 5 — Intégration Hermes ↔ Kore-OS via MCP — PLANIFIÉ  
9. Phase 6 — Recette, polissage et remise au commanditaire — PLANIFIÉ  
10. Risques et parades — PLANIFIÉ  
11. Critères de succès — PLANIFIÉ  

---

## 1. État des lieux — ce qui existe déjà

Avant de définir les étapes, il faut partir de ce qui est réellement implanté sur le VPS, et non d'une feuille blanche.

### Ce qui existe côté Hermes
- Un agent IA piloté principalement par Telegram (interface de messagerie).
- Un orchestrateur qui peut : lire/écrire des fichiers, exécuter des commandes terminal, chercher sur le web, naviguer dans un browser, gérer des connexions (connecteurs hébergés et MCP), gérer des skills, maintenir une mémoire persistante, faire de la synthèse vocale et de l'analyse d'image.
- Une chaîne de fallback automatique entre modèles d'IA (NVIDIA → OpenRouter/Claude-Sonnet-4.6) avec rotation de deux clés API NVIDIA et cooldown par code HTTP. Mesuré en production : 52 rotations de credentials en une journée.
- Un répertoire de skills (23 répertoires mesurés) sous forme de fichiers markdown, ajoutables à chaud sans rebuild.
- Un scheduler cron dédié (processus séparé du gateway) tournant sur un cycle de 10 minutes.
- Une mémoire persistante (profil utilisateur + notes).
- Ce qui manque : pas de sandbox dur, pas de missions multi-jours, pas de traits, pas de git-state avec rollback, pas de dashboard visuel, pas de rôles de workers affichés, pas de séparation Flux A/Flux B, pas de sessions multiples, pas de Lanes.

### Ce qui existe côté Kore-OS_TEP
- Un moteur de missions autonome (Node.js/TypeScript) avec boucle à micro-steps, interprétation, dispatcher d'outils, rapporteur.
- 17 outils MCP enregistrés + legacy executeTask.
- Persistance SQLite (missions, checkpoints, décisions, processus, snapshots d'expérience, budget de tokens).
- Checkpoints automatiques toutes les 5 itérations.
- Snapshots multi-jours (FULL à la suspension + périodiques + purge max 3).
- Reprise après crash au redémarrage (restauration à itération+1, budget réhydraté).
- Git-state déclaratif à `/etc/elo-state` (états réseau, pare-feu, services, plannings, limites agent) avec rollback forensique (SHA validé) et journal HTTP (`GET /api/v1/state/log`).
- Vault AES-256-GCM sans dépendance, IV aléatoire à chaque écriture, authTag vérifié.
- Sandbox dur : conteneurs `read_only: true`, `cap_drop: ALL`, profil seccomp (115 syscalls), kill de groupe (`process.kill(-pid)`), watchdog SIGKILL 10 s, purge garantie des orphelins.
- Seuils d'urgence applicatifs (RAM 85% / CPU 180 s / FD 1024) avec kill de groupe protégé.
- Notifications push VAPID réelles (RFC 8030 + VAPID + ECE).
- PWA Control Center (vanilla TS) : onglets Missions/Logs/Artifacts/Stats, thinking feed temps réel, abonnement push.
- Gateway Fastify + WebSocket (cognition :1020, intervention :1035), auth par token.
- Deux conteneurs (`kore-os-admin`, `kore-os-gateway`) liés par socket IPC Unix.
- Ce qui manque : pas de test end-to-end du runtime complet sur le VPS (pas de Docker), pas de chaîne de fallback multi-fournisseur (mono-fournisseur en pratique), pas d'interface Telegram/CLI, pas de skills hot-reloadables, pas de classes de workers, pas de rôles affichés.

### Ce qui manque à l'existant pour atteindre le projet souhaité
- Une interface WPA unifiée faisant dashboard, sessions, Lanes, monitoring.
- Le modèle des classes de workers (template avec skills, prompt système, documents, modèle IA, placeholders).
- Les deux niveaux de compétences (skills de worker + skills de contexte).
- Les cinq rôles de sub-agents affichés (admin, superviseur, worker, cronjobber, chercheur).
- La séparation Flux A (résultats) / Flux B (réflexion interne).
- La chaîne de notification Worker → Superviseur → Admin → Utilisateur.
- La supervision stricte (excellence demandée, boucles de correction).
- Les checkpoints/snapshots côté workers Hermes.
- La fusion Hermes ↔ Kore-OS.

---

## 2. Principe directeur du plan

Le plan suit la méthode qui a déjà fait ses Kore pour Kore-OS_TEP : chaque work package est borné, a un critère d'acceptation explicite et vérifiable, et produit un livrable écrit avant l'analyse fine. On ne réécrit rien en bloc ; on greffe et on assemble par étapes réversibles.

L'option d'architecture retenue est celle de la fusion par MCP (Option A de l'étude) : Hermes reste le shell opérateur (Telegram, CLI, skills, pool de modèles, fallback), Kore-OS reste le moteur de missions (boucle persistante, checkpoints, snapshots, sandbox, git-state, vault), et les deux communiquent via le protocole MCP. Aucune des deux bases n'est réécrite.

Le plan est découpé en 6 phases, de la plus fondationnelle à la plus intégrative. Chaque phase doit être validée par le commanditaire avant passage à la suivante (conformément aux modalités de livraison pour le worker Pythagor).

---

## 3. Phase 0 — Préparation et validation des décisions

### Objectif
Confirmer avec le commanditaire que l'interprétation de ses 30 réponses au questionnaire est correcte, et valider l'architecture de fusion retenue (MCP) avant d'écrire quoi que ce soit.

### Étapes
1. **Lire et valider la documentation existante** — Le document `KORE-OS_DOCUMENTATION.md` (21 sections) doit être relu par le commanditaire. Chaque décision architecturale y est tracée à une réponse de questionnaire. Toute correction est intégrée avant de continuer.
2. **Valider l'architecture MCP** — Confirmer que le pont Hermes (shell) ↔ Kore-OS (moteur) via MCP est acceptable. Aucune des deux bases n'est modifiée ; seul un bloc `mcp_servers` est ajouté à la config Hermes.
3. **Confirmer le worker "Pythagor"** — Nommer officiellement le worker affecté au projet. Il devra lire la spécification en entier, produire un plan d'implémentation phasé, tenir un journal des décisions d'architecture, valider chaque fonctionnalité avec le commanditaire avant passage à la suivante, et ne jamais inclure de secrets en clair dans les livrables.
4. **Préparer l'environnement** — Vérifier que `pip install mcp` est disponible côté Hermes (prérequis du client MCP natif). Vérifier que le serveur MCP Kore tourne déjà avec ses 4 outils enregistrés (`git_state_checkpoint`, `sandbox_exile`, `patch`, `vault_put/get`).

### Critère d'acceptation de la Phase 0
Le commanditaire a signé (explicitement ou implicitement par retour) la documentation et l'architecture. Le worker Pythagor est nommé. L'environnement MCP est vérifié.

---

## 4. Phase 1 — Fondation : classes de workers, rôles, compétences

### Objectif
Mettre en place les briques de base qui définissent ce qu'est un worker dans KORE-OS : le modèle des classes de workers, les cinq rôles, et le système à deux niveaux de compétences. Rien ne s'exécute encore de manière autonome ; on pose les structures de données et les interfaces de création.

### 4.1 Registre des classes de workers
Créer la structure de données `WorkerClass` :
- id, nom, description
- liste des skills de worker (IDs)
- prompt système de base
- documents de référence (few-shot)
- placeholders personnalisables
- modèle IA par défaut
- statut actif/inactif
- dates de création et modification

### 4.2 Instanciation de workers
À partir d'une classe, créer un `WorkerInstance` :
- id, nom de l'instance
- référence à la classe parente
- valeurs des placeholders pour cette instance
- prompt système rendu (classe + placeholders remplis)
- skills de contexte assignés (au début : vide)
- lane assignée (optionnel)
- statut : inactif / en cours / bloqué / terminé
- tâche courante (optionnel)

### 4.3 Les cinq rôles
Définir et implémenter l'affectation de rôle à chaque sub-agent :
1. **Admin** — administration, configuration, droits, délégation, choix du modèle.
2. **Superviseur / Manager** — supervision des workers, assignation de tâches, contrôle qualité, boucles de correction.
3. **Worker / Implémenteur** — exécution concrète (écriture, code, recherche, saisie).
4. **Cron Jobber** — tâches planifiées récurrentes.
5. **Chercheur (Renseigneur)** — recherche d'information, lecture de documentation, réponses factuelles.

Chaque worker doit afficher son rôle dans l'interface.

### 4.4 Compétences de worker (niveau 1)
Ce sont les skills que le commanditaire donne à une classe de worker. Exemples : recherche web, écriture de fichiers, envoi d'emails, rendu vidéo, exécution terminal. Elles appartiennent à la classe et sont accessibles à toutes les instances.

### 4.5 Compétences de contexte (niveau 2)
Ce sont des skills créés par un sub-agent pendant qu'il travaille, lorsqu'il apprend quelque chose de spécifique au contexte du projet (pas à sa nature de worker). Elles sont utilisables par n'importe qui, assignées par l'Admin ou le Superviseur quand un sub-agent doit travailler dans ce contexte.

### 4.6 Catalogue de skills
Une page centralisée listant toutes les skills avec : description, statut (actif/inactif), date de création, date de dernière utilisation. Ajout et modification possibles depuis l'interface.

### Critère d'acceptation de la Phase 1
- On peut créer une classe de worker (skills, prompt, documents, modèle, placeholders).
- On peut instancier plusieurs workers à partir d'une même classe.
- Les cinq rôles existent et sont affichables.
- Le catalogue de skills liste les skills avec leurs métadonnées.
- Rien n'est encore exécuté de manière autonome ; c'est une phase de structure.

---

## 5. Phase 2 — Moteur core : flux de tâche, délégation, chaîne de notification

### Objectif
Mettre en place le cœur logique de KORE-OS : comment un objectif utilisateur devient une tâche exécutée par un worker, comment elle est déléguée, et comment le résultat remonte jusqu'à l'utilisateur. C'est la phase où le système commence à "faire" quelque chose de concret.

### 5.1 Flux de tâche : Clarifier → Approuver → Exécuter → Rendre compte

Quand l'utilisateur donne un objectif à KORE-OS, le système suit ce flux en quatre étapes, dans l'ordre :

**Étape 1 — Clarifier (réalisée par un sub-agent spécifique).**
Avant d'exécuter quoi que ce soit, un sub-agent désigné pose des questions de clarification à l'utilisateur si l'objectif est ambigu. Exemple : "Vous voulez une vidéo de lancement. Quelle durée ? Quel style ? Quelles contraintes de marque ?" L'utilisateur répond.

**Étape 2 — Approuver (le plan est présenté à l'utilisateur).**
Une fois les éléments clarifiés, l'Administrateur (ou un délégué) présente le plan d'exécution : les étapes prévues, les workers concernés, l'ordre chronologique. L'utilisateur approuve ou rejette. Aucun travail ne commence avant approbation.

**Étape 3 — Exécuter (un Worker réalise la tâche).**
Le Worker assigné exécute la tâche en utilisant ses skills de classe et ses skills de contexte assignés. Il produit un résultat concret (fichier, document, code, rendu).

**Étape 4 — Rendre compte.**
Le Worker rapporte le résultat à son Superviseur, qui le qualité-contrôle, puis remonte à l'Administrateur, puis à l'utilisateur uniquement si la tâche est terminée ou bloquée sur une décision importante.

### 5.2 Délégation

L'Administrateur choisit quel sub-agent reçoit quelle tâche en fonction de la correspondance entre le type de tâche et la spécialité du sub-agent. Il peut réassigner une tâche en cours automatiquement si les choses ne se passent pas bien.

Plusieurs workers peuvent exister simultanément. L'Administrateur assigne en fonction de la disponibilité et de l'adéquation.

### 5.3 Gestion de l'échec et escalade

Quand un sub-agent ne peut pas terminer une tâche :

1. Il essaie une approche différente automatiquement.
2. S'il échoue toujours, il présente :
   - Quel est le problème (en termes métier, pas techniques).
   - Quelles sont les solutions potentielles.
   - Ce que chaque solution implique (conséquences, coût, temps).
3. L'utilisateur ou l'Administrateur décide des prochaines étapes.

Le système ne faille pas en silence. Il ne marque jamais une tâche comme échouée sans proposer un diagnostic.

### 5.4 Chaîne de notification : Worker → Superviseur → Admin → Utilisateur

La chaîne standard est :

- **Worker** notifie son **Superviseur** quand une tâche est terminée ou bloquée.
- **Superviseur** qualité-contrôle et notifie l'**Administrateur**.
- **Administrateur** notifie **l'utilisateur** uniquement quand :
  - La tâche donnée par l'utilisateur est terminée, OU
  - Il y a quelque chose de vraiment important que l'Administrateur ne peut pas décider seul.

L'utilisateur n'est pas notifié de chaque étape intermédiaire. La chaîne filtre le bruit.

### 5.5 Supervision stricte et boucles de correction

Les superviseurs sont extrêmement critiques. Avant de transmettre un résultat de worker vers le haut :

- Ils vérifient que le résultat est excellent, pas seulement acceptable.
- Ils rejettent les détails non demandés (tolérance faible aux détails indésirables).
- Ils demandent au worker de corriger des points précis.
- Ils sont réalistes et terre-à-terre ; ils ne surpromettent pas.

Si un Superviseur transmet un résultat qui ne correspond pas à ce qui était demandé, le Superviseur en est responsable.

**Boucle de correction :**
1. Le Superviseur renvoie le travail au Worker avec des points de correction précis.
2. Le Worker révise en utilisant ses skills et son contexte.
3. Le Superviseur re-vérifie.
4. Seulement quand c'est excellent, le travail remonte à l'Administrateur.

### 5.6 Priorités et ordonnancement

La priorité d'une tâche est déterminée dans cet ordre :

1. L'utilisateur définit les priorités manuellement à tout moment.
2. Les délais et l'urgence.
3. Le jugement de l'Administrateur sur ce qui est le plus important.

L'utilisateur peut modifier les priorités à tout moment.

### 5.7 Mémoire et skills de contexte

Quand un sub-agent apprend quelque chose de spécifique au contexte du projet pendant son travail, il fabrique un skill de contexte. L'Administrateur ou le Superviseur l'assigne au sub-agent concerné quand celui-ci doit travailler dans ce contexte. Les sub-agents se souviennent seulement des leçons importantes, pas de tout.

### Critère d'acceptation de la Phase 2
- On peut donner un objectif à KORE-OS et recevoir des questions de clarification.
- Un plan est présenté et approuvé avant exécution.
- Un worker exécute la tâche approuvée.
- Le résultat remonte par la chaîne Worker → Superviseur → Admin → Utilisateur.
- Un échec est diagnostiqué (problème, solutions, implications) plutôt que marqué en silence.
- Un superviseur peut rejeter un résultat et demander des corrections.

---

## 6. Phase 3 — Interface : WPA, tableau de bord, sessions, Lanes

### Objectif
Construire l'interface visuelle (Web Progressive App) qui permet à l'utilisateur de piloter le système sans avoir à lire des logs techniques ou à rester dans Telegram. C'est la phase qui rend le système visible et opérable au quotidien.

### 6.1 Interface principale : le tableau de bord

Une vue principale affichant tous les workers actifs en temps réel. Pour chaque worker :

- Nom / ID du worker
- Rôle (admin, superviseur, worker, cron, chercheur)
- Lane à laquelle il est affecté
- Statut (inactif, en cours, bloqué, terminé)

Une **narration de la finalité** pour chaque worker — une description de ce que le worker accomplit en termes métier, pas techniques :

- Bonne narration : "Ce worker rend possible le remplissage de formulaires avec les options X1, X2…, ce qui fait que l'utilisateur va agir exactement comme suit : …"
- Mauvaise narration (à éviter) : "Ce worker modifie le fichier form.js et rencontre une erreur de package npm."

Le tableau de bord est dans la zone principale de l'interface WPA.

### 6.2 Séparation Flux A / Flux B

L'interface sépare deux flux de communication :

**Flux A — destiné à l'utilisateur (affiché par défaut) :**
- Réponses aux instructions
- Livrables et fichiers produits
- Validations et confirmations
- Erreurs bloquantes nécessitant une décision

**Flux B — réflexion interne (masqué par défaut, accessible via un mode "détails" ou panneau latéral) :**
- Raisonnements internes
- Auto-correction
- Logs de débug
- Auto-examen du travail

L'utilisateur ne voit que le Flux A par défaut. Il choisit de consulter le Flux B quand il veut comprendre comment le système a raisonné.

### 6.3 Sessions multiples

L'utilisateur peut lancer plusieurs sessions en même temps. Depuis l'interface WPA (sélecteur de session, onglets ou liste latérale), il peut alterner entre les sessions. Chaque session conserve son propre contexte et son propre historique.

### 6.4 Lanes (pistes de travail parallèles)

Une Lane est une piste de travail dédiée, isolée contextuellement :

- Une Lane peut exécuter plusieurs tâches sans que leurs contextes ne se mélangent.
- Une Lane peut avoir plusieurs workers à son service (exemple : un implémenteur + un testeur).
- Chaque Lane a un identifiant, un nom, un statut, et une liste des workers affectés.
- Les tâches dans la Lane A ne peuvent pas lire les fichiers, notes ou contexte de la Lane B, sauf partage explicite via un skill de contexte.

### 6.5 Catalogue des skills et traçabilité visuelle

Une page répertorie tous les skills avec description, statut, date de création et date de dernière utilisation. Chaque message généré par un skill est traçable vers ce skill. Dans l'interface, une indication visuelle (bordure colorée ou petit sceau) sur chaque bulle de message indique le skill utilisé. Le survol affiche le nom du skill. Une légende des couleurs est disponible.

### 6.6 Quotas et code couleur par modèle

Affichage de l'usage des quotas par provider (par minute et par jour). Affichage du modèle IA actif sur chaque sub-agent. Chaque modèle a une couleur (bordure ou badge). Une légende explique quelle couleur correspond à quel modèle.

### 6.7 Monitoring VPS temps réel

Un programme côté VPS fournit directement les données de ressources (sans appel IA) : RAM, CPU, SWAP, disque, charge (1m/5m/15m). Affichage temps réel dans l'interface WPA avec rafraîchissement automatique.

### 6.8 Gestion dynamique des skills

Ajout et modification de skills depuis l'interface WPA. Catalogue consultable avec description, statut, dates.

### 6.9 Tâches de fond 100% automatiques

Une catégorie spéciale de tâches pré-établies tournant à 100% en fond. Exécution automatique sans demande de confirmation ni de reprise. Listées dans une section dédiée de l'interface.

### 6.10 Vue des instructions par Lane

Une vue globale de toutes les instructions données, avec pour chacune : texte, statut (en attente, en cours, terminée, bloquée, annulée), Lane associée, derniers messages de la Lane, dates de création et dernière mise à jour. Filtres par statut, Lane, date.

### Critère d'acceptation de la Phase 3
- Le tableau de bord affiche tous les workers avec rôle, Lane et statut en temps réel.
- La narration de finalité est en termes métier, pas techniques.
- Le Flux A est affiché par défaut ; le Flux B est accessible à la demande.
- Plusieurs sessions sont ouvertes et alternables.
- Les Lanes isolent le contexte entre tâches.
- Le catalogue des skills est consultable et modifiable depuis l'interface.
- Les quotas et le modèle actif sont affichés avec code couleur.
- Le monitoring VPS est visible en temps réel.
- Les tâches de fond s'exécutent automatiquement sans question de reprise.
- Une vue globale des instructions existe avec filtres.

---

## 7. Phase 4 — Fiabilité : checkpoints, snapshots, reprise après crash

### Objectif
Assurer que KORE-OS ne perd pas le travail en cours quand un sub-agent plante ou qu'un fournisseur de modèle d'IA échoue. C'est la phase qui rend le système fiable sur la durée, en s'appuyant sur ce que Kore-OS_TEP sait déjà faire (checkpoints, snapshots, reprise) et que Hermes n'a pas.

### 7.1 Checkpoints périodiques
Le moteur de missions checkpoint le travail régulièrement (toutes les N itérations). Un checkpoint enregistre l'état de la tâche à ce moment-là : itération atteinte, données partielles, decisions prises.

### 7.2 Snapshots multi-jours
Une snapshot complète (FULL) est prise à la suspension d'une mission, et des snapshots périodiques sont pris selon un intervalle configurable. Les snapshots sont archivés (format portable .tar.gz) et purgés au-delà d'un maximum (3 par défaut).

### 7.3 Reprise après crash
Si un sub-agent s'arrête de répondre ou crash, l'Administrateur redémarre la tâche depuis le dernier checkpoint. Le worker est ré-instancié depuis sa classe, recharge ses skills et ses skills de contexte, et reprend à l'itération+1 avec le budget réhydraté. Aucun travail accompli n'est perdu.

### 7.4 Pause / reprise sans perte de progrès
Une tâche longue (heures ou jours) peut être suspendue et reprise plus tard sans perdre ce qui a déjà été fait. La restauration repart de l'itération suivante avec le budget recalculé.

### 7.5 Git-state et rollback forensique
Avant toute action à risque, un point de restauration est créé via git-state (`/etc/elo-state`). En cas de problème, on peut rollback à un SHA précédent. Un journal des commits d'état est accessible via une route HTTP (`GET /api/v1/state/log`). Cela donne une traçabilité "qui a modifié quoi et quand" sur les états du système.

### 7.6 Seuils d'urgence et protection
Des seuils de sécurité protègent le système : RAM 85%, CPU, descripteurs de fichiers. En cas de dépassement, un kill de groupe est déclenché avec des gardes (PID 1, soi-même, ancêtres, gateway et MCP protégés) pour éviter que le système ne s'auto-détruise.

### 7.7 Résilience fournisseur de modèle
La chaîne de fallback (pool de clés + rotation + fournisseur alternatif) absorbe les 429, 401, 402, 503 sans intervention humaine. Si tous les fournisseurs échouent, le problème est présenté à l'utilisateur avec des solutions potentielles.

### Critère d'acceptation de la Phase 4
- Un checkpoint est créé périodiquement pendant l'exécution d'une tâche.
- Une snapshot est prise à la suspension et peut restaurer la tâche plus tard.
- Après un crash simulé d'un worker, la tâche reprend depuis le dernier checkpoint sans perte de progrès.
- Une pause/reprise sur une tâche longue conserve tout le travail effectué.
- Un rollback git-state est possible à un SHA antérieur.
- Les seuils d'urgence déclenchent une protection sans casser le système.
- Une panne de fournisseur de modèle est absorbée par le fallback automatique.

---

## 8. Phase 5 — Intégration Hermes ↔ Kore-OS via MCP

### Objectif
Fusionner les deux bases existantes sans les réécrire : Hermes reste le shell opérateur (Telegram, CLI, skills, pool de modèles, fallback), Kore-OS reste le moteur de missions (boucle persistante, checkpoints, snapshots, sandbox, git-state, vault), et les deux communiquent via le protocole MCP (Model Context Protocol). C'est l'option A de l'étude de fusion, retenue parce qu'elle est la seule soutenue par les faits.

### 8.1 Pourquoi l'option MCP (et pas les autres)

- **Option B (Kore héberge Hermes dans son runtime) :** trop risquée, car le runtime Kore n'a jamais été testé en bout en bout sur le VPS (pas de Docker, seccomp non audité). Embarquer Hermes dedans inverserait le rapport de confiance.
- **Option C (fusion profonde module par module) :** c'est une réécriture, pas une intégration. Deux langages, deux modèles de processus, deux invariants opposés. Coût au trimestre, risque maximal, bénéfice incertain.
- **Option A (MCP) :** aucune des deux bases n'est modifiée. Le serveur MCP Kore existe déjà avec 4 outils (`git_state_checkpoint`, `sandbox_exile`, `patch`, `vault_put/get`). Hermes a un client MCP natif qui découvre les outils au démarrage et les injecte dans toutes les surfaces (Telegram compris). Coût : jours, pas semaines ni trimestres.

### 8.2 Étapes d'intégration

**Vague 0 — Vérifier les prérequis :**
- Confirmer que le paquet Python `mcp` est installé côté Hermes (sinon, le client MCP est silencieusement désactivé).
- Confirmer que le serveur MCP Kore tourne déjà avec ses 4 outils enregistrés.

**Vague 1 — Brancher le serveur Kore dans la config Hermes :**
- Ajouter un bloc `mcp_servers: kore` dans `~/.hermes/config.yaml` (transport stdio vers le processus MCP Kore, variables d'environnement explicites pour les secrets, jamais l'environnement hérité).
- Redémarrer le gateway Hermes.
- Vérifier que les outils apparaissent (`mcp_kore_git_state_checkpoint`, etc.) dans les ensembles d'outils de toutes les surfaces, y compris Telegram.

**Vague 2 — Exposer les actions de mission côté Kore :**
- Exposer les actions de pilotage du dispatcher Kore : start, pause, resume, cancel, budget, priorité, statut, snapshot. Ce sont le pendant des routes Fastify existantes (`server.ts:438-533`).

**Vague 3 — Déléguer une mission réelle de bout en bout :**
- Depuis Telegram, lancer une mission réelle de plusieurs heures (exemple : reconnaissance de code + proposition de patch).
- Vérifier que la mission est checkpointée, suspendue, reprise, et qu'un rapport Kaizen est généré.
- Vérifier que l'utilisateur reçoit une notification Telegram de suspension.

### 8.3 Ce que l'intégration apporte à Hermes
- Un sandbox dur pour l'exécution de code risqué (via `sandbox_exile` / `TmpWorkspace` de Kore).
- Des missions persistantes multi-jours (ce que Hermes n'a pas : ses workers meurent par le transport LLM, 20% de mortalité mesurée).
- Les traits (CONCEPTION / REALIZATION / EVALUATION) appliqués aux tâches déléguées.
- Le git-state avec rollback forensique avant toute action risquée.
- Le vault AES-256-GCM pour les secrets.

### 8.4 Ce que l'intégration apporte à Kore
- L'interface Telegram existante d'Hermes (Kore n'a qu'une PWA vanilla inachevée).
- Les skills d'Hermes (23 répertoires de procédures éprouvées) pour habiller et lancer les missions Kore.
- Le pool de credentials et le fallback d'Hermes pour gérer la panne fournisseur transverse qui manque à Kore.

### Critère d'acceptation de la Phase 5
- Une mission réelle lancée depuis Telegram est exécutée par Kore-OS via MCP.
- La mission survit à une coupure du processus Hermes (checkpoint + snapshot réhydratés à la reprise).
- Le rapport vit dans le répertoire Kaizen de Kore.
- L'utilisateur reçoit la notification de suspension sur Telegram.
- Aucun des deux dépôts n'a été réécrit ; seul un bloc de config a été ajouté.

---

## 9. Phase 6 — Recette, polissage et remise au commanditaire

### Objectif
Valider l'ensemble du système end-to-end, polir les aspérités, et remettre la main au commanditaire avec une documentation complète et un journal des décisions d'architecture.

### 9.1 Recette fonctionnelle (test bout en bout)

Scénario d'acceptation : depuis Telegram, l'utilisateur dit "Produis une vidéo de lancement de deux minutes pour le produit Client X."

Le système doit :
1. Poser des questions de clarification (style, durée, contraintes de marque).
2. Présenter un plan : recherche → script → motion design → relecture → livraison.
3. Attendre l'approbation de l'utilisateur.
4. Instancier les workers concernés (chercheur, motion designer, copywriter).
5. Assigner les skills de contexte pertinents (guide de marque Client X).
6. Exécuter chaque étape avec supervision stricte (le superviseur rejette ce qui n'est pas excellent et demande des corrections).
7. Remonter le résultat uniquement quand la tâche est terminée ou bloquée sur une décision importante.
8. Livrer la vidéo et l'e-mail de lancement.

### 9.2 Journal des décisions d'architecture

Le worker affecté (Pythagor) tient un journal consignant chaque choix architectural : pourquoi telle structure de données, pourquoi telle séparation de flux, pourquoi l'option MCP, etc. Ce journal est remis avec le système.

### 9.3 Plan d'implémentation phasé (MVP → complet)

Un plan validé avec le commanditaire, allant d'un MVP réduit (une classe de worker, un flux de tâche simple, un dashboard basique) à la version complète (toutes les classes, tous les rôles, toutes les Lanes, monitoring, intégration MCP).

### 9.4 Validation incrémentale

Chaque fonctionnalité est validée avec le commanditaire avant passage à la suivante. Aucun développement en avance sur la validation.

### 9.5 Sécurité des livrables

Aucune clé API, token ou secret en clair dans aucun livrable. Tous les secrets sont remplacés par `[REDACTED]`.

### Critère d'acceptation de la Phase 6
- Le scénario bout en bout (vidéo de lancement Client X) fonctionne de bout en bout depuis Telegram.
- Le journal des décisions d'architecture est remis.
- Le plan phasé MVP → complet est signé par le commanditaire.
- Chaque fonctionnalité a été validée incrémentalement.
- Aucun secret n'apparaît en clair dans les livrables.

---

## 10. Risques et parades

| Risque | Probabilité | Impact | Parade |
|--------|-------------|--------|--------|
| Panne fournisseur de modèle (429/stale) | Élevée | Moyen | Pool de clés + rotation + fournisseur alternatif + checkpoints |
| Superviseur trop strict, bloque tout | Faible | Moyen | L'Administrateur peut overridre ; nombre de cycles de correction limité |
| Fuite de contexte entre Lanes | Faible | Élevé | Sandbox + garde d'isolation Lane ; accès refusé par défaut |
| Secrets dans l'historique git | Moyenne | Élevé | Vault AES-256-GCM ; révoquer les anciennes clés ; rien en clair dans les livrables |
| Runtime Kore non testé sur VPS (pas de Docker) | Moyenne | Élevé | Provisionner Docker avant déploiement complet ; test de fumée |
| Trop de notifications à l'utilisateur | Moyenne | Faible | Chaîne de notification filtrée ; seulement terminaison et blocages importants |
| Contradiction entre skills de contexte | Faible | Moyen | Le superviseur détecte ; l'Administre arbitre |

---

## 11. Critères de succès

KORE-OS est réussi quand :

1. L'utilisateur peut ouvrir un tableau de bord et voir ce que fait chaque sub-agent en temps réel.
2. Le système tourne de manière fiable sur de longues périodes sans intervention utilisateur.
3. Il accompli des tâches que l'utilisateur ferait autrement lui-même.
4. Il prend en charge une part croissante de la charge de travail de l'utilisateur.
5. La mortalité des workers (tâches perdues par panne fournisseur) est proche de zéro grâce aux checkpoints et snapshots.
6. La supervision stricte empêche tout résultat médiocre d'atteindre l'utilisateur.

---

## 12. Ordre résumé des étapes

1. **Phase 0** — Valider la documentation, l'architecture MCP, nommer Pythagor, préparer l'environnement MCP.
2. **Phase 1** — Classes de workers, cinq rôles, skills de niveau 1 et 2, catalogue.
3. **Phase 2** — Flux Clarifier → Approuver → Exécuter → Rendre compte, délégation, gestion d'échec, chaîne de notification, supervision stricte.
4. **Phase 3** — WPA, tableau de bord, séparation Flux A/B, sessions multiples, Lanes, catalogue skills traçable, quotas, monitoring VPS, gestion dynamique skills, tâches de fond, vue instructions.
5. **Phase 4** — Checkpoints, snapshots, reprise après crash, pause/reprise, git-state rollback, seuils d'urgence, résilience fournisseur.
6. **Phase 5** — Intégration Hermes ↔ Kore-OS via MCP (vagues 0 à 3).
7. **Phase 6** — Recette bout en bout, journal des décisions, plan phasé, validation incrémentale, sécurité des livrables.

---

*Grand plan terminé. Les 12 sections sont rédigées. Chaque phase a un critère d'acceptation explicite et peut être validée indépendamment avant de passer à la suivante.*





