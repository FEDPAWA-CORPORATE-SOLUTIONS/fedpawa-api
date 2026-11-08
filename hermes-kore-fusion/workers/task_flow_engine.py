#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Moteur de flux de tâche — Phase 2 de KORE-OS

Implémente le flux Clarifier → Approuver → Exécuter → Rendre compte,
la délégation par spécialité, la chaîne de notification
Worker → Superviseur → Admin → Utilisateur, et la supervision stricte
avec boucles de correction.

Conformément à la spécification KORE-OS :
- Un objectif utilisateur ne donne jamais lieu à exécution immédiate :
  un sub-agent spécifique pose d'abord des questions de clarification.
- Le plan est présenté à l'utilisateur pour approbation avant tout travail.
- Un Worker exécute la tâche approuvée.
- Le résultat remonte par la chaîne Worker → Superviseur → Admin → Utilisateur.
- Le Superviseur est extrêmement strict : il rejette ce qui n'est pas
  excellent et demande des corrections précises.
- En cas d'échec, le système essaie une autre approche, puis présente
  le problème, les solutions potentielles et leurs implications.
"""

import json
import os
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional

DATA_ROOT = Path("/root/hermes-kore-fusion/data")
TASKS_PATH = DATA_ROOT / "tasks.json"
NOTIFICATIONS_PATH = DATA_ROOT / "notifications.json"
INSTANCES_PATH = DATA_ROOT / "worker_instances.json"
CONTEXT_SKILLS_PATH = DATA_ROOT / "context_skills.json"

# Rôles valides
ROLE_ADMIN = "admin"
ROLE_SUPERVISEUR = "superviseur"
ROLE_WORKER = "worker"
ROLE_CRON = "cron_jobber"
ROLE_RECHERCHEUR = "renseigneur"

# Statuts de tâche
STATUT_EN_ATTENTE = "en_attente"        # en attente de clarification
STATUT_EN_COURS = "en_cours"            # plan approuvé, exécution en cours
STATUT_BLOQUE = "bloque"                # bloqué, attend une décision
STATUT_TERMINE = "termine"              # terminé, livré à l'utilisateur
STATUT_ECHEC = "echec"                  # échec après toutes les approches

# Types de notification
NOTIF_COMPLETION = "completion"         # tâche terminée
NOTIF_BLOCKER = "blocker"               # bloqueur nécessitant une décision
NOTIF_CORRECTION = "correction"         # demande de correction au worker
NOTIF_QUALITY_REJECT = "quality_reject" # refus qualité du superviseur


class TaskFlowEngine:
    """Moteur de flux de tâche et chaîne de notification KORE-OS."""

    def __init__(self):
        self.data_root = DATA_ROOT
        self._ensure_files()

    # ------------------------------------------------------------------
    # Initialisation des fichiers de données
    # ------------------------------------------------------------------
    def _ensure_files(self):
        for path, default in [
            (TASKS_PATH, {"version": "1.0", "date_creation": self._now(), "taches": [], "compteur_taches": 0}),
            (NOTIFICATIONS_PATH, {"version": "1.0", "date_creation": self._now(), "notifications": [], "compteur_notifications": 0}),
        ]:
            if not path.exists():
                self._write_json(path, default)

    # ------------------------------------------------------------------
    # Utilitaires I/O
    # ------------------------------------------------------------------
    @staticmethod
    def _now() -> str:
        return datetime.now(timezone.utc).isoformat()

    @staticmethod
    def _read_json(path: Path) -> dict:
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f)

    @staticmethod
    def _write_json(path: Path, data: dict) -> None:
        with open(path, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)

    # ------------------------------------------------------------------
    # Chargement des workers et classes
    # ------------------------------------------------------------------
    def _load_workers(self) -> List[dict]:
        path = INSTANCES_PATH
        if path.exists():
            return self._read_json(path).get("instances", [])
        return []

    def _load_classes(self) -> List[dict]:
        registry_path = Path("/root/hermes-kore-fusion/data/worker_classes/registry.json")
        if not registry_path.exists():
            return []
        registry = self._read_json(registry_path)
        classes = []
        for entry in registry.get("classes", []):
            if not entry.get("actif", True):
                continue
            class_path = Path("/root/hermes-kore-fusion/data/worker_classes/") / entry["fichier"]
            if class_path.exists():
                classes.append(self._read_json(class_path))
        return classes

    def _find_worker_by_id(self, worker_id: str) -> Optional[dict]:
        for w in self._load_workers():
            if w["id"] == worker_id:
                return w
        return None

    def _find_workers_by_role(self, role: str) -> List[dict]:
        return [w for w in self._load_workers() if w.get("role") == role]

    def _find_workers_by_class(self, class_id: str) -> List[dict]:
        return [w for w in self._load_workers() if w.get("class_id") == class_id]

    def _find_available_worker(self, class_id: str) -> Optional[dict]:
        """Trouve un worker disponible (inactif ou terminé) d'une classe donnée."""
        candidates = self._find_workers_by_class(class_id)
        for w in candidates:
            if w.get("statut") in ("inactif", "termine"):
                return w
        return None

    # ------------------------------------------------------------------
    # CRUD des tâches
    # ------------------------------------------------------------------
    def create_task(
        self,
        objectif_utilisateur: str,
        session_id: Optional[str] = None,
        lane_id: Optional[str] = None,
        priorite: str = "normale",
        classe_souhaitee: Optional[str] = None,
    ) -> dict:
        """Crée une tâche en statut en_attente (en attente de clarification)."""
        task_id = f"tache_{uuid.uuid4().hex[:8]}"
        task = {
            "id": task_id,
            "objectif": objectif_utilisateur,
            "session_id": session_id,
            "lane_id": lane_id,
            "priorite": priorite,
            "classe_souhaitee": classe_souhaitee,
            "statut": STATUT_EN_ATTENTE,
            "etape_actuelle": "clarification",  # clarification, approbation, execution, rendu_compte
            "questions_clarification": [],
            "reponses_clarification": {},
            "plan": None,
            "plan_approuve": False,
            "worker_assigne": None,
            "worker_id": None,
            "superviseur_id": None,
            "resultat": None,
            "probleme": None,
            "solutions_potentielles": [],
            "date_creation": self._now(),
            "date_derniere_maj": self._now(),
            "date_completion": None,
            "corrections_demandees": [],
            "historique": [],
        }

        tasks = self._read_json(TASKS_PATH)
        tasks["taches"].append(task)
        tasks["compteur_taches"] = tasks.get("compteur_taches", 0) + 1
        self._write_json(TASKS_PATH, tasks)

        self._ajouter_historique(task_id, "creation", "Tâche créée, en attente de clarification.")
        self._envoyer_notification(
            expediteur="systeme",
            destinataire="utilisateur",
            tache_id=task_id,
            type_notif=NOTIF_BLOCKER,
            message=f"Une nouvelle tâche a été créée : '{objectif_utilisateur}'. Des questions de clarification sont nécessaires avant de commencer.",
        )

        return task

    def get_task(self, task_id: str) -> Optional[dict]:
        for t in self._read_json(TASKS_PATH).get("taches", []):
            if t["id"] == task_id:
                return t
        return None

    def list_tasks(self, lane_id: Optional[str] = None, statut: Optional[str] = None) -> List[dict]:
        taches = self._read_json(TASKS_PATH).get("taches", [])
        if lane_id:
            taches = [t for t in taches if t.get("lane_id") == lane_id]
        if statut:
            taches = [t for t in taches if t.get("statut") == statut]
        return taches

    def update_task(self, task_id: str, updates: dict) -> Optional[dict]:
        tasks = self._read_json(TASKS_PATH)
        for i, t in enumerate(tasks["taches"]):
            if t["id"] == task_id:
                t.update(updates)
                t["id"] = task_id
                t["date_derniere_maj"] = self._now()
                tasks["taches"][i] = t
                self._write_json(TASKS_PATH, tasks)
                return t
        return None

    # ------------------------------------------------------------------
    # Étape 1 : Clarification
    # ------------------------------------------------------------------
    def ajouter_questions_clarification(self, task_id: str, questions: List[str]) -> dict:
        """Ajoute des questions de clarification à une tâche (étape 1 du flux)."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")
        task["questions_clarification"] = questions
        task["etape_actuelle"] = "clarification"
        task["statut"] = STATUT_EN_ATTENTE
        self._ajouter_historique(task_id, "clarification", f"{len(questions)} question(s) de clarification ajoutée(s).")
        self.update_task(task_id, task)
        return task

    def repondre_clarification(self, task_id: str, question: str, reponse: str) -> dict:
        """Enregistre une réponse à une question de clarification."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")
        task["reponses_clarification"][question] = reponse
        # Vérifier si toutes les questions ont une réponse
        toutes_repondues = all(
            q in task["reponses_clarification"] for q in task["questions_clarification"]
        )
        if toutes_repondues:
            task["etape_actuelle"] = "approbation"
            task["statut"] = STATUT_EN_ATTENTE  # en attente d'approbation du plan
            self._ajouter_historique(task_id, "clarification_complete", "Toutes les questions de clarification ont reçu une réponse.")
        else:
            self._ajouter_historique(task_id, "reponse_clarification", f"Réponse enregistrée : {question}")
        self.update_task(task_id, task)
        return task

    # ------------------------------------------------------------------
    # Étape 2 : Approbation du plan
    # ------------------------------------------------------------------
    def generer_plan(self, task_id: str) -> dict:
        """Génère le plan d'exécution à partir des réponses de clarification.
        L'Administrateur décide de la décomposition et de la classe de worker."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        # Déterminer la classe de worker la plus adaptée
        classes = self._load_classes()
        classe_choisie = None
        if task.get("classe_souhaitee"):
            for c in classes:
                if c["id"] == task["classe_souhaitee"]:
                    classe_choisie = c
                    break
        if not classe_choisie:
            # Heuristique simple basée sur l'objectif
            objectif = task["objectif"].lower()
            for c in classes:
                desc = c.get("description", "").lower()
                if any(mot in objectif for mot in desc.split()[:3]):
                    classe_choisie = c
                    break
        if not classe_choisie and classes:
            classe_choisie = classes[0]

        plan = {
            "objectif": task["objectif"],
            "classe_worker": classe_choisie["id"] if classe_choisie else None,
            "nom_classe": classe_choisie["nom"] if classe_choisie else "Non définie",
            "etapes": [
                "1. Clarification des besoins (réponses reçues)",
                "2. Exécution par le worker assigné",
                "3. Contrôle qualité par le superviseur",
                "4. Livraison à l'utilisateur",
            ],
            "questions_clarification": task.get("questions_clarification", []),
            "reponses_clarification": task.get("reponses_clarification", {}),
        }

        task["plan"] = plan
        task["classe_souhaitee"] = classe_choisie["id"] if classe_choisie else None
        task["etape_actuelle"] = "approbation"
        task["statut"] = STATUT_EN_ATTENTE  # en attente d'approbation utilisateur
        self._ajouter_historique(task_id, "plan_genere", f"Plan généré pour la classe {classe_choisie['nom'] if classe_choisie else 'indéfinie'}.")
        self.update_task(task_id, task)
        return task

    def approuver_plan(self, task_id: str, approuve: bool, commentaire: Optional[str] = None) -> dict:
        """L'utilisateur approuve ou rejette le plan (étape 2 du flux)."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        if approuve:
            task["plan_approuve"] = True
            task["statut"] = STATUT_EN_COURS
            task["etape_actuelle"] = "execution"
            self._ajouter_historique(task_id, "plan_approuve", "Plan approuvé par l'utilisateur. L'exécution peut commencer.")
            # Notifier l'utilisateur que l'exécution démarre
            self._envoyer_notification(
                expediteur="administrateur",
                destinataire="utilisateur",
                tache_id=task_id,
                type_notif=NOTIF_COMPLETION,
                message=f"Le plan pour '{task['objectif']}' est approuvé. L'exécution démarre.",
            )
        else:
            task["statut"] = STATUT_EN_ATTENTE
            task["etape_actuelle"] = "clarification"
            self._ajouter_historique(task_id, "plan_rejete", f"Plan rejeté : {commentaire or 'Aucun commentaire'}.")
            self._envoyer_notification(
                expediteur="administrateur",
                destinataire="utilisateur",
                tache_id=task_id,
                type_notif=NOTIF_BLOCKER,
                message=f"Le plan pour '{task['objectif']}' a été rejeté. {commentaire or 'Veuillez préciser les modifications souhaitées.'}",
            )

        self.update_task(task_id, task)
        return task

    # ------------------------------------------------------------------
    # Étape 3 : Exécution (délégation)
    # ------------------------------------------------------------------
    def deleguer_tache(self, task_id: str) -> dict:
        """Délègue la tâche à un worker disponible de la classe appropriée."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")
        if not task.get("plan_approuve"):
            raise ValueError("Le plan n'a pas encore été approuvé.")

        classe_id = task.get("classe_souhaitee")
        if not classe_id:
            # Pas de classe précisée : choisir une classe par défaut (Coding)
            classes = self._load_classes()
            for c in classes:
                if c.get("nom") == "Coding":
                    classe_id = c["id"]
                    break
            if not classe_id and classes:
                classe_id = classes[0]["id"]
        worker = self._find_available_worker(classe_id)
        if not worker:
            # Aucun worker disponible : il faut en instancier un
            from worker_class_manager import WorkerClassManager
            mgr = WorkerClassManager()
            worker = mgr.instantiate_worker(
                class_id=classe_id,
                name=f"Worker-{task_id[-6:]}",
                lane_id=task.get("lane_id"),
            )

        # Assigner un superviseur
        superviseurs = self._find_workers_by_role(ROLE_SUPERVISEUR)
        superviseur = superviseurs[0] if superviseurs else None

        task["worker_assigne"] = worker["nom"]
        task["worker_id"] = worker["id"]
        task["superviseur_id"] = superviseur["id"] if superviseur else None
        task["statut"] = STATUT_EN_COURS
        task["etape_actuelle"] = "execution"
        self._ajouter_historique(task_id, "delegation", f"Tâche déléguée à {worker['nom']} (classe {worker['class_nom']}).")
        if superviseur:
            self._ajouter_historique(task_id, "superviseur_assigne", f"Superviseur : {superviseur['nom']}.")
        self.update_task(task_id, task)

        # Mettre à jour le statut du worker
        self._update_worker_statut(worker["id"], "en_cours", task_id)

        return task

    def executer_tache(self, task_id: str, resultat_travail: str) -> dict:
        """Le worker termine son travail et soumet le résultat (étape 3 du flux)."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        task["resultat"] = resultat_travail
        task["statut"] = STATUT_EN_COURS  # en attente de validation superviseur
        task["etape_actuelle"] = "rendu_compte"
        self._ajouter_historique(task_id, "execution_terminee", "Worker a soumis son résultat. En attente de contrôle qualité.")
        self.update_task(task_id, task)

        # Notifier le superviseur
        if task.get("superviseur_id"):
            self._envoyer_notification(
                expediteur="worker",
                destinataire="superviseur",
                tache_id=task_id,
                type_notif=NOTIF_COMPLETION,
                message=f"Le worker {task.get('worker_assigne')} a terminé '{task['objectif']}'. En attente de votre contrôle qualité.",
            )

        return task

    # ------------------------------------------------------------------
    # Supervision stricte et contrôle qualité
    # ------------------------------------------------------------------
    def controler_qualite(self, task_id: str, approuve: bool, corrections: Optional[List[str]] = None) -> dict:
        """Le superviseur contrôle la qualité du résultat (étape de supervision)."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        if approuve:
            task["statut"] = STATUT_TERMINE
            task["etape_actuelle"] = "rendu_compte"
            task["date_completion"] = self._now()
            self._ajouter_historique(task_id, "qualite_approuvee", "Le superviseur a approuvé le résultat. Qualité excellente.")
            self.update_task(task_id, task)

            # Notifier l'Administrateur puis l'utilisateur
            self._envoyer_notification(
                expediteur="superviseur",
                destinataire="administrateur",
                tache_id=task_id,
                type_notif=NOTIF_COMPLETION,
                message=f"Tâche '{task['objectif']}' validée par le superviseur. Résultat excellent, prêt à être livré à l'utilisateur.",
            )
            self._envoyer_notification(
                expediteur="administrateur",
                destinataire="utilisateur",
                tache_id=task_id,
                type_notif=NOTIF_COMPLETION,
                message=f"Votre tâche '{task['objectif']}' est terminée. Le résultat est prêt à être consulté.",
            )

            # Libérer le worker
            if task.get("worker_id"):
                self._update_worker_statut(task["worker_id"], "termine", None)

        else:
            task["statut"] = STATUT_EN_COURS
            task["etape_actuelle"] = "execution"
            corrections = corrections or []
            task["corrections_demandees"] = corrections
            self._ajouter_historique(task_id, "qualite_refusee", f"Le superviseur a demandé {len(corrections)} correction(s).")
            self.update_task(task_id, task)

            # Notifier le worker des corrections demandées
            self._envoyer_notification(
                expediteur="superviseur",
                destinataire="worker",
                tache_id=task_id,
                type_notif=NOTIF_CORRECTION,
                message=f"Le résultat pour '{task['objectif']}' n'est pas encore excellent. Corrections demandées : {' ; '.join(corrections)}.",
            )

        return task

    # ------------------------------------------------------------------
    # Étape 4 : Gestion de l'échec
    # ------------------------------------------------------------------
    def signaler_echec(self, task_id: str, probleme: str, solutions: List[str], implications: List[str]) -> dict:
        """Le worker signale un échec après avoir essayé une autre approche."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        task["probleme"] = probleme
        task["solutions_potentielles"] = solutions
        task["statut"] = STATUT_BLOQUE
        task["etape_actuelle"] = "echec"
        self._ajouter_historique(task_id, "echec_signale", f"Problème : {probleme}. {len(solutions)} solution(s) proposée(s).")
        self.update_task(task_id, task)

        # Notifier l'Administrateur qui décide
        self._envoyer_notification(
            expediteur="worker",
            destinataire="administrateur",
            tache_id=task_id,
            type_notif=NOTIF_BLOCKER,
            message=f"Échec sur '{task['objectif']}' : {probleme}. Solutions : {' ; '.join(solutions)}. Implications : {' ; '.join(implications)}. Décision requise.",
        )
        return task

    def reprendre_apres_echec(self, task_id: str, nouvelle_approche: str) -> dict:
        """L'Administrateur décide de reprendre avec une nouvelle approche."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        task["statut"] = STATUT_EN_COURS
        task["etape_actuelle"] = "execution"
        task["probleme"] = None
        self._ajouter_historique(task_id, "reprise_apres_echec", f"Nouvelle approche : {nouvelle_approche}.")
        self.update_task(task_id, task)
        return task

    # ------------------------------------------------------------------
    # Notifications (chaîne Worker → Superviseur → Admin → Utilisateur)
    # ------------------------------------------------------------------
    def _envoyer_notification(
        self,
        expediteur: str,
        destinataire: str,
        tache_id: str,
        type_notif: str,
        message: str,
    ) -> dict:
        notif_id = f"notif_{uuid.uuid4().hex[:8]}"
        notif = {
            "id": notif_id,
            "expediteur": expediteur,
            "destinataire": destinataire,
            "tache_id": tache_id,
            "type": type_notif,
            "message": message,
            "date_creation": self._now(),
            "lue": False,
        }
        notifs = self._read_json(NOTIFICATIONS_PATH)
        notifs["notifications"].append(notif)
        notifs["compteur_notifications"] = notifs.get("compteur_notifications", 0) + 1
        self._write_json(NOTIFICATIONS_PATH, notifs)
        return notif

    def list_notifications(self, destinataire: Optional[str] = None, non_lues_seulement: bool = False) -> List[dict]:
        notifs = self._read_json(NOTIFICATIONS_PATH).get("notifications", [])
        if destinataire:
            notifs = [n for n in notifs if n.get("destinataire") == destinataire]
        if non_lues_seulement:
            notifs = [n for n in notifs if not n.get("lue", False)]
        return notifs

    def marquer_notification_lue(self, notif_id: str) -> bool:
        notifs = self._read_json(NOTIFICATIONS_PATH)
        for n in notifs["notifications"]:
            if n["id"] == notif_id:
                n["lue"] = True
                self._write_json(NOTIFICATIONS_PATH, notifs)
                return True
        return False

    # ------------------------------------------------------------------
    # Historique et workers
    # ------------------------------------------------------------------
    def _ajouter_historique(self, task_id: str, action: str, description: str) -> None:
        task = self.get_task(task_id)
        if task:
            entree = {
                "action": action,
                "description": description,
                "date": self._now(),
            }
            task.setdefault("historique", []).append(entree)

    def _update_worker_statut(self, worker_id: str, statut: str, tache_courante: Optional[str]) -> None:
        instances = self._read_json(INSTANCES_PATH)
        for w in instances["instances"]:
            if w["id"] == worker_id:
                w["statut"] = statut
                w["tache_courante"] = tache_courante
                w["derniere_activite"] = self._now()
                self._write_json(INSTANCES_PATH, instances)
                break

    def get_statistiques(self) -> dict:
        taches = self._read_json(TASKS_PATH).get("taches", [])
        stats = {
            "total": len(taches),
            "par_statut": {},
            "par_priorite": {},
        }
        for t in taches:
            s = t.get("statut", "inconnu")
            stats["par_statut"][s] = stats["par_statut"].get(s, 0) + 1
            p = t.get("priorite", "normale")
            stats["par_priorite"][p] = stats["par_priorite"].get(p, 0) + 1
        return stats

    # ------------------------------------------------------------------
    # Phase 4 — Fiabilité : checkpoints, snapshots, reprise après crash
    # ------------------------------------------------------------------
    def create_checkpoint(self, task_id: str, iteration: int, donnees: dict) -> dict:
        """Crée un checkpoint pour une tâche à une itération donnée."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        checkpoint_id = f"ckpt_{uuid.uuid4().hex[:8]}"
        checkpoint = {
            "id": checkpoint_id,
            "task_id": task_id,
            "iteration": iteration,
            "donnees": donnees,
            "date_creation": self._now(),
        }

        ckpt_path = Path("/root/hermes-kore-fusion/data/checkpoints")
        ckpt_path.mkdir(parents=True, exist_ok=True)
        with open(ckpt_path / f"{checkpoint_id}.json", "w", encoding="utf-8") as f:
            json.dump(checkpoint, f, ensure_ascii=False, indent=2)

        task.setdefault("checkpoints", []).append({
            "id": checkpoint_id,
            "iteration": iteration,
            "date_creation": checkpoint["date_creation"],
        })
        # Garder seulement les 10 derniers checkpoints
        if len(task["checkpoints"]) > 10:
            task["checkpoints"] = task["checkpoints"][-10:]
        task["dernier_checkpoint_id"] = checkpoint_id
        task["dernier_checkpoint_iteration"] = iteration
        self.update_task(task_id, task)

        self._ajouter_historique(task_id, "checkpoint", f"Checkpoint créé à l'itération {iteration}.")
        return checkpoint

    def list_checkpoints(self, task_id: str) -> List[dict]:
        """Liste les checkpoints d'une tâche."""
        task = self.get_task(task_id)
        if not task:
            return []
        return task.get("checkpoints", [])

    def create_snapshot(self, task_id: str, type_snapshot: str = "full") -> dict:
        """Crée une snapshot complète d'une tâche (suspension ou périodique)."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        snapshot_id = f"snap_{uuid.uuid4().hex[:8]}"
        snapshot = {
            "id": snapshot_id,
            "task_id": task_id,
            "type": type_snapshot,  # full / periodic
            "statut": task.get("statut"),
            "etape_actuelle": task.get("etape_actuelle"),
            "resultat": task.get("resultat"),
            "probleme": task.get("probleme"),
            "solutions_potentielles": task.get("solutions_potentielles", []),
            "corrections_demandees": task.get("corrections_demandees", []),
            "checkpoints": task.get("checkpoints", []),
            "dernier_checkpoint_id": task.get("dernier_checkpoint_id"),
            "date_creation": self._now(),
        }

        snap_path = Path("/root/hermes-kore-fusion/data/snapshots")
        snap_path.mkdir(parents=True, exist_ok=True)
        with open(snap_path / f"{snapshot_id}.json", "w", encoding="utf-8") as f:
            json.dump(snapshot, f, ensure_ascii=False, indent=2)

        task.setdefault("snapshots", []).append({
            "id": snapshot_id,
            "type": type_snapshot,
            "date_creation": snapshot["date_creation"],
        })
        # Purge : garder max 3 snapshots
        if len(task["snapshots"]) > 3:
            task["snapshots"] = task["snapshots"][-3:]
        task["dernier_snapshot_id"] = snapshot_id
        self.update_task(task_id, task)

        self._ajouter_historique(task_id, "snapshot", f"Snapshot {type_snapshot} créée.")
        return snapshot

    def list_snapshots(self, task_id: str) -> List[dict]:
        """Liste les snapshots d'une tâche."""
        task = self.get_task(task_id)
        if not task:
            return []
        return task.get("snapshots", [])

    def restore_from_checkpoint(self, task_id: str, checkpoint_id: Optional[str] = None) -> dict:
        """Restaure une tâche depuis un checkpoint (reprise après crash)."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        # Déterminer quel checkpoint utiliser
        if not checkpoint_id:
            checkpoint_id = task.get("dernier_checkpoint_id")
        if not checkpoint_id:
            raise ValueError("Aucun checkpoint disponible pour cette tâche.")

        ckpt_path = Path("/root/hermes-kore-fusion/data/checkpoints") / f"{checkpoint_id}.json"
        if not ckpt_path.exists():
            raise ValueError(f"Checkpoint introuvable: {checkpoint_id}")

        checkpoint = self._read_json(ckpt_path)
        iteration = checkpoint.get("iteration", 0)
        donnees = checkpoint.get("donnees", {})

        # Restaurer l'état de la tâche
        task["statut"] = STATUT_EN_COURS
        task["etape_actuelle"] = "execution"
        task["date_derniere_maj"] = self._now()
        task["repris_de_checkpoint"] = checkpoint_id
        task["repris_a_iteration"] = iteration
        self.update_task(task_id, task)

        # Réactiver le worker si nécessaire
        if task.get("worker_id"):
            self._update_worker_statut(task["worker_id"], "en_cours", task_id)

        self._ajouter_historique(task_id, "restore_checkpoint", f"Restauration depuis le checkpoint {checkpoint_id} (itération {iteration}).")
        self._envoyer_notification(
            expediteur="administrateur",
            destinataire="utilisateur",
            tache_id=task_id,
            type_notif=NOTIF_COMPLETION,
            message=f"La tâche '{task['objectif']}' a été reprise depuis son dernier point de sauvegarde (itération {iteration}). Aucun travail n'est perdu.",
        )

        return task

    def restore_from_snapshot(self, task_id: str, snapshot_id: Optional[str] = None) -> dict:
        """Restaure une tâche depuis une snapshot (reprise multi-jours)."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        if not snapshot_id:
            snapshot_id = task.get("dernier_snapshot_id")
        if not snapshot_id:
            raise ValueError("Aucune snapshot disponible pour cette tâche.")

        snap_path = Path("/root/hermes-kore-fusion/data/snapshots") / f"{snapshot_id}.json"
        if not snap_path.exists():
            raise ValueError(f"Snapshot introuvable: {snapshot_id}")

        snapshot = self._read_json(snap_path)

        # Restaurer l'état depuis la snapshot
        task["statut"] = snapshot.get("statut", STATUT_EN_COURS)
        task["etape_actuelle"] = snapshot.get("etape_actuelle", "execution")
        task["resultat"] = snapshot.get("resultat")
        task["probleme"] = snapshot.get("probleme")
        task["solutions_potentielles"] = snapshot.get("solutions_potentielles", [])
        task["corrections_demandees"] = snapshot.get("corrections_demandees", [])
        task["date_derniere_maj"] = self._now()
        task["repris_de_snapshot"] = snapshot_id
        self.update_task(task_id, self._read_json(TASKS_PATH)["taches"][
            next(i for i, t in enumerate(self._read_json(TASKS_PATH)["taches"]) if t["id"] == task_id)
        ])

        self._ajouter_historique(task_id, "restore_snapshot", f"Restauration depuis la snapshot {snapshot_id} ({snapshot.get('type')}).")
        self._envoyer_notification(
            expediteur="administrateur",
            destinataire="utilisateur",
            tache_id=task_id,
            type_notif=NOTIF_COMPLETION,
            message=f"La tâche '{task['objectif']}' a été restaurée depuis une snapshot précédente. Reprise sans perte de travail.",
        )

        return task

    def suspendre_tache(self, task_id: str) -> dict:
        """Suspend une tâche en créant une snapshot complète."""
        task = self.get_task(task_id)
        if not task:
            raise ValueError(f"Tâche introuvable: {task_id}")

        task["statut"] = STATUT_BLOQUE
        task["etape_actuelle"] = "suspendue"
        task["date_derniere_maj"] = self._now()
        self.update_task(task_id, task)

        # Créer une snapshot FULL avant suspension
        self.create_snapshot(task_id, type_snapshot="full")

        self._ajouter_historique(task_id, "suspendre", "Tâche suspendue, snapshot complète créée.")
        self._envoyer_notification(
            expediteur="administrateur",
            destinataire="utilisateur",
            tache_id=task_id,
            type_notif=NOTIF_BLOCKER,
            message=f"La tâche '{task['objectif']}' a été suspendue. Elle peut être reprise plus tard sans perte de travail.",
        )

        return task

    def get_reliability_status(self) -> dict:
        """Retourne l'état de fiabilité du système (checkpoints, snapshots)."""
        taches = self._read_json(TASKS_PATH).get("taches", [])
        total_checkpoints = sum(len(t.get("checkpoints", [])) for t in taches)
        total_snapshots = sum(len(t.get("snapshots", [])) for t in taches)
        taches_suspendues = sum(1 for t in taches if t.get("statut") == STATUT_BLOQUE)
        taches_reprises = sum(1 for t in taches if t.get("repris_de_checkpoint") or t.get("repris_de_snapshot"))

        ckpt_dir = Path("/root/hermes-kore-fusion/data/checkpoints")
        snap_dir = Path("/root/hermes-kore-fusion/data/snapshots")
        ckpt_count = len(list(ckpt_dir.glob("*.json"))) if ckpt_dir.exists() else 0
        snap_count = len(list(snap_dir.glob("*.json"))) if snap_dir.exists() else 0

        return {
            "total_taches": len(taches),
            "total_checkpoints": total_checkpoints,
            "total_snapshots": total_snapshots,
            "checkpoints_fichiers": ckpt_count,
            "snapshots_fichiers": snap_count,
            "taches_suspendues": taches_suspendues,
            "taches_reprises": taches_reprises,
            "mortalite_evitee": taches_reprises,  # tâches qui n'ont pas été perdues grâce aux checkpoints
        }


# ------------------------------------------------------------------
# Interface en ligne de commande rapide (Phase 4)
# ------------------------------------------------------------------
if __name__ == "__main__":
    import sys
    engine = TaskFlowEngine()
    cmd = sys.argv[1] if len(sys.argv) > 1 else "help"
    if cmd == "stats":
        print(engine.get_reliability_status())
    elif cmd == "checkpoints":
        task_id = sys.argv[2] if len(sys.argv) > 2 else ""
        if task_id:
            for ck in engine.list_checkpoints(task_id):
                print(f"  {ck['id']} — itération {ck['iteration']} — {ck['date_creation']}")
        else:
            print("Usage: task_flow_engine.py checkpoints <task_id>")
    else:
        print("Commandes : stats, checkpoints <task_id>")
