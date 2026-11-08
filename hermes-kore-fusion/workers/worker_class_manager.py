#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Worker Class Manager — Phase 1 de KORE-OS

Gère le registre des classes de workers, l'instanciation de workers,
les compétences de niveau 1 (worker skills) et de niveau 2 (context skills),
et le catalogue unifié des compétences.

Conformément à la spécification KORE-OS :
- Une classe de worker = template (skills, prompt système, documents, modèle IA, placeholders)
- Un worker = instance d'une classe (avec placeholders remplis)
- Compétences de worker (niveau 1) = fournies par le commanditaire à la classe
- Compétences de contexte (niveau 2) = créées par les sub-agents pendant le travail,
  utilisables par n'importe qui, assignées par l'Admin ou le Superviseur
"""

import json
import os
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional

DATA_ROOT = Path("/root/hermes-kore-fusion/data")
CLASSES_DIR = DATA_ROOT / "worker_classes"
REGISTRY_PATH = CLASSES_DIR / "registry.json"
INSTANCES_PATH = DATA_ROOT / "worker_instances.json"
CONTEXT_SKILLS_PATH = DATA_ROOT / "context_skills.json"
SKILLS_DIR = Path("/root/hermes-kore-fusion/skills")
ROLES_PATH = Path("/root/hermes-kore-fusion/config/roles.json")


class WorkerClassManager:
    """Gestionnaire des classes de workers, instances et compétences."""

    def __init__(self):
        self.data_root = DATA_ROOT
        self._ensure_dirs()

    # ------------------------------------------------------------------
    # Initialisation des répertoires
    # ------------------------------------------------------------------
    def _ensure_dirs(self):
        for d in [CLASSES_DIR, SKILLS_DIR]:
            d.mkdir(parents=True, exist_ok=True)
        if not INSTANCES_PATH.exists():
            self._write_json(INSTANCES_PATH, {
                "version": "1.0",
                "date_creation": self._now(),
                "instances": [],
                "compteur_instances": 0,
            })
        if not CONTEXT_SKILLS_PATH.exists():
            self._write_json(CONTEXT_SKILLS_PATH, {
                "version": "1.0",
                "date_creation": self._now(),
                "context_skills": [],
                "compteur_skills": 0,
            })

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
    # Registre des classes de workers
    # ------------------------------------------------------------------
    def list_classes(self) -> List[dict]:
        """Retourne toutes les classes de workers actives."""
        registry = self._read_json(REGISTRY_PATH)
        classes = []
        for entry in registry.get("classes", []):
            if not entry.get("actif", True):
                continue
            class_data = self._read_json(CLASSES_DIR / entry["fichier"])
            classes.append(class_data)
        return classes

    def get_class(self, class_id: str) -> Optional[dict]:
        """Retourne une classe de worker par son ID."""
        registry = self._read_json(REGISTRY_PATH)
        for entry in registry.get("classes", []):
            if entry["id"] == class_id:
                return self._read_json(CLASSES_DIR / entry["fichier"])
        return None

    def create_class(self, class_data: dict) -> dict:
        """Crée une nouvelle classe de worker et l'enregistre."""
        class_id = class_data.get("id") or f"worker_class_{uuid.uuid4().hex[:8]}"
        class_data["id"] = class_id
        class_data.setdefault("actif", True)
        class_data.setdefault("date_creation", self._now())
        class_data["date_modification"] = self._now()

        fichier = f"{class_id}.json"
        self._write_json(CLASSES_DIR / fichier, class_data)

        registry = self._read_json(REGISTRY_PATH)
        registry["classes"].append({
            "id": class_id,
            "fichier": fichier,
            "nom": class_data.get("nom", class_id),
            "actif": True,
        })
        registry["total_classes"] = len(registry["classes"])
        self._write_json(REGISTRY_PATH, registry)

        return class_data

    def update_class(self, class_id: str, updates: dict) -> Optional[dict]:
        """Met à jour une classe existante."""
        class_data = self.get_class(class_id)
        if not class_data:
            return None
        class_data.update(updates)
        class_data["id"] = class_id
        class_data["date_modification"] = self._now()
        fichier = f"{class_id}.json"
        self._write_json(CLASSES_DIR / fichier, class_data)
        return class_data

    def delete_class(self, class_id: str) -> bool:
        """Désactive une classe (soft delete)."""
        class_data = self.get_class(class_id)
        if not class_data:
            return False
        return self.update_class(class_id, {"actif": False}) is not None

    # ------------------------------------------------------------------
    # Instanciation de workers
    # ------------------------------------------------------------------
    def instantiate_worker(
        self,
        class_id: str,
        name: str,
        placeholder_values: Optional[Dict[str, str]] = None,
        lane_id: Optional[str] = None,
    ) -> dict:
        """Crée une instance de worker à partir d'une classe."""
        class_data = self.get_class(class_id)
        if not class_data:
            raise ValueError(f"Classe de worker introuvable: {class_id}")

        worker_id = f"worker_{uuid.uuid4().hex[:8]}"
        placeholder_values = placeholder_values or {}

        # Rendu du prompt système avec placeholders
        system_prompt = class_data.get("system_prompt", "")
        for ph in class_data.get("placeholders", []):
            ph_name = ph["nom"]
            value = placeholder_values.get(ph_name, f"{{{ph_name}}}")
            system_prompt = system_prompt.replace("{" + ph_name + "}", value)

        worker = {
            "id": worker_id,
            "nom": name,
            "class_id": class_id,
            "class_nom": class_data.get("nom", class_id),
            "placeholder_values": placeholder_values,
            "system_prompt": system_prompt,
            "skills_de_classe": list(class_data.get("skills", [])),
            "skills_de_contexte": [],
            "lane_id": lane_id,
            "role": "worker",  # rôle par défaut, modifiable
            "statut": "inactif",
            "tache_courante": None,
            "modele_ia": class_data.get("modele_ia_defaut"),
            "date_creation": self._now(),
            "derniere_activite": None,
        }

        instances = self._read_json(INSTANCES_PATH)
        instances["instances"].append(worker)
        instances["compteur_instances"] = instances.get("compteur_instances", 0) + 1
        self._write_json(INSTANCES_PATH, instances)

        return worker

    def list_instances(self) -> List[dict]:
        """Retourne toutes les instances de workers."""
        return self._read_json(INSTANCES_PATH).get("instances", [])

    def get_instance(self, worker_id: str) -> Optional[dict]:
        for w in self.list_instances():
            if w["id"] == worker_id:
                return w
        return None

    def update_instance(self, worker_id: str, updates: dict) -> Optional[dict]:
        instances = self._read_json(INSTANCES_PATH)
        for i, w in enumerate(instances["instances"]):
            if w["id"] == worker_id:
                w.update(updates)
                w["id"] = worker_id
                w["derniere_activite"] = self._now()
                instances["instances"][i] = w
                self._write_json(INSTANCES_PATH, instances)
                return w
        return None

    def delete_instance(self, worker_id: str) -> bool:
        instances = self._read_json(INSTANCES_PATH)
        for i, w in enumerate(instances["instances"]):
            if w["id"] == worker_id:
                instances["instances"].pop(i)
                self._write_json(INSTANCES_PATH, instances)
                return True
        return False

    # ------------------------------------------------------------------
    # Compétences de contexte (niveau 2)
    # ------------------------------------------------------------------
    def create_context_skill(
        self,
        nom: str,
        description: str,
        projet_id: str,
        appris_par: str,  # ID du sub-agent qui a créé le skill
        applicable_quand: str,
    ) -> dict:
        """Crée un skill de contexte (niveau 2)."""
        skill_id = f"context_skill_{uuid.uuid4().hex[:8]}"
        skill = {
            "id": skill_id,
            "nom": nom,
            "type": "context",
            "description": description,
            "projet_id": projet_id,
            "appris_par": appris_par,
            "applicable_quand": applicable_quand,
            "assignes_a": [],
            "assignes_par": None,
            "date_creation": self._now(),
            "date_derniere_utilisation": None,
            "actif": True,
        }

        skills = self._read_json(CONTEXT_SKILLS_PATH)
        skills["context_skills"].append(skill)
        skills["compteur_skills"] = skills.get("compteur_skills", 0) + 1
        self._write_json(CONTEXT_SKILLS_PATH, skills)

        return skill

    def list_context_skills(self) -> List[dict]:
        return self._read_json(CONTEXT_SKILLS_PATH).get("context_skills", [])

    def assign_context_skill_to_worker(
        self, worker_id: str, context_skill_id: str, assigne_par: str
    ) -> bool:
        """Assigne un skill de contexte à un worker (par Admin ou Superviseur)."""
        # Vérifier que le skill existe
        context_skills = self._read_json(CONTEXT_SKILLS_PATH)
        skill_found = None
        for s in context_skills["context_skills"]:
            if s["id"] == context_skill_id:
                skill_found = s
                break
        if not skill_found:
            return False

        # Assigner au worker
        instances = self._read_json(INSTANCES_PATH)
        for w in instances["instances"]:
            if w["id"] == worker_id:
                if context_skill_id not in w.get("skills_de_contexte", []):
                    w.setdefault("skills_de_contexte", []).append(context_skill_id)
                w["derniere_activite"] = self._now()
                instances["instances"][instances["instances"].index(w)] = w
                self._write_json(INSTANCES_PATH, instances)

                # Mettre à jour le skill
                for s in context_skills["context_skills"]:
                    if s["id"] == context_skill_id:
                        if worker_id not in s.get("assignes_a", []):
                            s.setdefault("assignes_a", []).append(worker_id)
                        s["assignes_par"] = assigne_par
                        break
                self._write_json(CONTEXT_SKILLS_PATH, context_skills)
                return True
        return False

    # ------------------------------------------------------------------
    # Catalogue de skills unifié
    # ------------------------------------------------------------------
    def list_skill_catalog(self) -> List[dict]:
        """Retourne le catalogue unifié : skills de worker (niveau 1) + context skills (niveau 2)."""
        catalogue = []

        # Skills de worker (niveau 1) — depuis le répertoire skills/ (sous-dossiers avec skill.json)
        if SKILLS_DIR.exists():
            for skill_json in SKILLS_DIR.glob("*/skill.json"):
                try:
                    skill = self._read_json(skill_json)
                    if not skill.get("id") or not skill.get("nom"):
                        continue
                    catalogue.append({
                        "id": skill.get("id", skill_json.parent.name),
                        "nom": skill.get("nom", skill_json.parent.name),
                        "type": "worker",
                        "tier": 1,
                        "description": skill.get("description", ""),
                        "couleur": skill.get("couleur", "#6b7280"),
                        "role_assigne": skill.get("role_assigne"),
                        "date_creation": skill.get("date_creation"),
                        "date_derniere_utilisation": skill.get("date_derniere_utilisation"),
                        "actif": skill.get("actif", True),
                    })
                except (json.JSONDecodeError, OSError):
                    continue

        # Skills de contexte (niveau 2)
        for s in self.list_context_skills():
            catalogue.append({
                "id": s["id"],
                "nom": s["nom"],
                "type": "context",
                "tier": 2,
                "description": s.get("description", ""),
                "couleur": "#8b5cf6",
                "role_assigne": None,
                "date_creation": s.get("date_creation"),
                "date_derniere_utilisation": s.get("date_derniere_utilisation"),
                "actif": s.get("actif", True),
                "projet_id": s.get("projet_id"),
                "appris_par": s.get("appris_par"),
            })

        return catalogue

    # ------------------------------------------------------------------
    # Rôles
    # ------------------------------------------------------------------
    def list_roles(self) -> List[dict]:
        if ROLES_PATH.exists():
            return self._read_json(ROLES_PATH).get("roles", [])
        return []

    def get_role(self, role_id: str) -> Optional[dict]:
        for r in self.list_roles():
            if r["id"] == role_id:
                return r
        return None


# ------------------------------------------------------------------
# Interface en ligne de commande rapide
# ------------------------------------------------------------------
if __name__ == "__main__":
    import sys

    mgr = WorkerClassManager()
    cmd = sys.argv[1] if len(sys.argv) > 1 else "help"

    if cmd == "list-classes":
        for c in mgr.list_classes():
            print(f"  {c['id']} — {c['nom']} (modèle: {c.get('modele_ia_defaut')})")
    elif cmd == "list-instances":
        for w in mgr.list_instances():
            print(f"  {w['id']} — {w['nom']} (classe: {w['class_nom']}, statut: {w['statut']})")
    elif cmd == "list-skills":
        for s in mgr.list_skill_catalog():
            print(f"  {s['id']} — {s['nom']} [tier {s['tier']}]")
    elif cmd == "list-roles":
        for r in mgr.list_roles():
            print(f"  {r['id']} — {r['nom']} ({r.get('responsabilites', '')})")
    elif cmd == "instantiate":
        # Usage: instantiate <class_id> <name> [ph_key=ph_value ...]
        class_id = sys.argv[2]
        name = sys.argv[3]
        ph = {}
        for a in sys.argv[4:]:
            if "=" in a:
                k, v = a.split("=", 1)
                ph[k] = v
        w = mgr.instantiate_worker(class_id, name, ph)
        print(f"Worker créé: {w['id']} — {w['nom']}")
        print(f"  Prompt système: {w['system_prompt'][:120]}...")
    else:
        print("Usage: worker_class_manager.py <command> [args]")
        print("Commands: list-classes, list-instances, list-skills, list-roles, instantiate")
