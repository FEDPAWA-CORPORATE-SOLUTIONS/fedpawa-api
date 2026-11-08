#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Background Tasks Worker — Phase 3 de KORE-OS

Gère les tâches de fond 100% automatiques (pré-établies) qui s'exécutent
sans demande de confirmation ni de reprise. Lit depuis
data/background-tasks/ et écrit l'historique.
Le dashboard WPA (/api/background-tasks) lit ces données.
"""

import json
import os
import time
from pathlib import Path

TASKS_DIR = Path("/root/hermes-kore-fusion/data/background-tasks")
HISTORY_FILE = TASKS_DIR / "history.json"

DEFAULT_TASKS = [
    {
        "id": "bg-rotation-logs",
        "nom": "Rotation des logs",
        "categorie": "maintenance",
        "frequence": "quotidien",
        "statut": "auto",
        "derniere_execution": None,
        "prochaine_execution": None,
        "description": "Fait pivoter les fichiers de logs pour éviter qu'ils ne grossissent indéfiniment.",
    },
    {
        "id": "bg-sante-kore",
        "nom": "Vérification santé Kore",
        "categorie": "surveillance",
        "frequence": "10min",
        "statut": "auto",
        "derniere_execution": None,
        "prochaine_execution": None,
        "description": "Vérifie que le moteur Kore répond et que le pont MCP est actif.",
    },
    {
        "id": "bg-backup-bridge",
        "nom": "Sauvegarde bridge.jsonl",
        "categorie": "sauvegarde",
        "frequence": "quotidien",
        "statut": "auto",
        "derniere_execution": None,
        "prochaine_execution": None,
        "description": "Sauvegarde le fichier de traçabilité MCP bridge.jsonl.",
    },
    {
        "id": "bg-surveillance-swap",
        "nom": "Surveillance swap",
        "categorie": "surveillance",
        "frequence": "5min",
        "statut": "auto",
        "derniere_execution": None,
        "prochaine_execution": None,
        "description": "Surveille le taux d'utilisation du swap et alerte si saturation.",
    },
]


def collect():
    TASKS_DIR.mkdir(parents=True, exist_ok=True)
    tasks_file = TASKS_DIR / "tasks.json"

    # Initialiser les tâches par défaut si absent
    if not tasks_file.exists():
        with open(tasks_file, "w", encoding="utf-8") as f:
            json.dump(DEFAULT_TASKS, f, ensure_ascii=False, indent=2)
        tasks = DEFAULT_TASKS
    else:
        with open(tasks_file, "r", encoding="utf-8") as f:
            tasks = json.load(f)

    # Historique
    history = []
    if HISTORY_FILE.exists():
        try:
            with open(HISTORY_FILE, "r", encoding="utf-8") as f:
                history = json.load(f)
        except Exception:
            history = []

    return {
        "timestamp": time.time(),
        "taches": tasks,
        "historique": history[-20:],
    }


def main():
    data = collect()
    with open(HISTORY_FILE, "w", encoding="utf-8") as f:
        json.dump(data["historique"], f, ensure_ascii=False, indent=2)
    # Sauvegarder aussi la liste des tâches
    with open(TASKS_DIR / "tasks.json", "w", encoding="utf-8") as f:
        json.dump(data["taches"], f, ensure_ascii=False, indent=2)
    print(json.dumps(data, ensure_ascii=False))


if __name__ == "__main__":
    main()
