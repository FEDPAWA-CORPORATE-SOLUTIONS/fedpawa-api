#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Synchronisation Phase 3 — Exporte les données Phase 1&2 vers les formats
attendus par les API routes du WPA Next.js.
"""

import json
import os
import sys
import time
from pathlib import Path

sys.path.insert(0, "/root/hermes-kore-fusion/workers")
from worker_class_manager import WorkerClassManager
from task_flow_engine import TaskFlowEngine

DATA = Path("/root/hermes-kore-fusion/data")
WORKERS_DIR = DATA / "workers"
INSTRUCTIONS_DIR = DATA / "instructions"

WORKERS_DIR.mkdir(parents=True, exist_ok=True)
INSTRUCTIONS_DIR.mkdir(parents=True, exist_ok=True)


def sync_workers():
    """Exporte les instances de workers vers data/workers/<id>.json (format WPA)."""
    mgr = WorkerClassManager()
    instances = mgr.list_instances()
    count = 0
    for w in instances:
        worker_file = WORKERS_DIR / f"{w['id']}.json"
        data = {
            "id": w["id"],
            "nom": w["nom"],
            "role": w.get("role", "worker"),
            "lane_id": w.get("lane_id"),
            "model_actif": w.get("modele_ia"),
            "statut": w.get("statut", "inactif").upper(),
            "mission_id": w.get("tache_courante"),
            "narration_finalite": f"Worker de classe {w.get('class_nom', '?')} — exécute les tâches de sa spécialité.",
            "derniere_maj": w.get("derniere_activite") or w.get("date_creation"),
            "skills_utilisees": w.get("skills_de_classe", []) + w.get("skills_de_contexte", []),
            "class_id": w.get("class_id"),
            "class_nom": w.get("class_nom"),
        }
        with open(worker_file, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
        count += 1
    print(f"  {count} workers exportés vers data/workers/")


def sync_instructions():
    """Exporte les tâches Phase 2 vers data/instructions/<id>.json (format WPA)."""
    engine = TaskFlowEngine()
    taches = engine.list_tasks()
    count = 0
    for t in taches:
        inst_file = INSTRUCTIONS_DIR / f"{t['id']}.json"
        data = {
            "id": t["id"],
            "texte": t["objectif"],
            "statut": t.get("statut", "en_attente"),
            "lane_id": t.get("lane_id"),
            "date_creation": t.get("date_creation"),
            "derniere_maj": t.get("date_derniere_maj"),
            "worker_assigné": t.get("worker_assigne"),
            "messages_lane": t.get("historique", []),
            "plan_approuve": t.get("plan_approuve", False),
            "etape_actuelle": t.get("etape_actuelle"),
            "resultat": t.get("resultat"),
        }
        with open(inst_file, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
        count += 1
    print(f"  {count} instructions exportées vers data/instructions/")


def sync_vps_stats():
    """Convertit la sortie du VPS monitor vers le format attendu par /api/vps/stats."""
    import vps_monitor_worker
    data = vps_monitor_worker.collect()
    ram = data["ram"]
    swap = data["swap"]
    disk = data["disk"]
    stats = {
        "cpu_pct": data["cpu"],
        "ram_used_mb": round(ram["used_kb"] / 1024, 1),
        "ram_total_mb": round(ram["total_kb"] / 1024, 1),
        "ram_pct": ram["percent"],
        "swap_used_mb": round(swap["used_kb"] / 1024, 1),
        "swap_total_mb": round(swap["total_kb"] / 1024, 1),
        "swap_pct": swap["percent"],
        "disk_pct": disk["percent"],
        "load_avg": f"{data['load']['load1']} / {data['load']['load5']} / {data['load']['load15']}",
        "timestamp": data["iso"],
    }
    with open(DATA / "vps_stats.json", "w", encoding="utf-8") as f:
        json.dump(stats, f, ensure_ascii=False, indent=2)
    print(f"  vps_stats.json écrit (CPU {stats['cpu_pct']}%, RAM {stats['ram_pct']}%, SWAP {stats['swap_pct']}%)")


def sync_quotas():
    """Convertit la sortie du quota tracker vers /root/hermes-kore-fusion/data/quotas.json."""
    import quota_tracker_worker
    data = quota_tracker_worker.collect()
    with open(DATA / "quotas.json", "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print(f"  quotas.json écrit ({len(data['providers'])} providers)")


def sync_background_tasks():
    """Écrit la config des tâches de fond pour /api/background-tasks."""
    import background_tasks_worker
    bdata = background_tasks_worker.collect()
    config = {"tâches": bdata["taches"]}
    config_path = Path("/root/hermes-kore-fusion/config/background-tasks.json")
    config_path.parent.mkdir(parents=True, exist_ok=True)
    with open(config_path, "w", encoding="utf-8") as f:
        json.dump(config, f, ensure_ascii=False, indent=2)
    # Historique vide initial
    hist_path = Path("/root/hermes-kore-fusion/data/background-tasks-history.json")
    with open(hist_path, "w", encoding="utf-8") as f:
        json.dump({"tâches": []}, f, ensure_ascii=False, indent=2)
    print(f"  background-tasks.json écrit ({len(bdata['taches'])} tâches)")


if __name__ == "__main__":
    print("=== Synchronisation Phase 3 ===")
    sync_workers()
    sync_instructions()
    sync_vps_stats()
    sync_quotas()
    sync_background_tasks()
    print("\nSynchronisation terminée.")
