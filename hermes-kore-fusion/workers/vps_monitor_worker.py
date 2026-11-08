#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
VPS Monitor Worker — Phase 3 de KORE-OS

Lit les ressources du VPS (RAM, CPU, SWAP, disque, charge) directement
depuis le système, sans appel IA, et écrit dans data/telemetry/telemetry.json.
Le dashboard WPA (/api/telemetry) lit ce fichier pour l'affichage temps réel.
"""

import json
import os
import time
from pathlib import Path

DATA_FILE = Path("/root/hermes-kore-fusion/data/telemetry/telemetry.json")


def read_cpu_percent():
    """Lecture du % CPU depuis /proc/stat."""
    try:
        with open("/proc/stat", "r") as f:
            line = f.readline()
        parts = line.split()
        # parts[1..] = user, nice, system, idle, iowait, irq, softirq, steal
        values = [int(x) for x in parts[1:8]]
        idle = values[3] + values[4]  # idle + iowait
        total = sum(values)
        used = total - idle
        return round((used / total) * 100, 1) if total > 0 else 0.0
    except Exception:
        return 0.0


def read_ram():
    """Lecture RAM depuis /proc/meminfo."""
    try:
        mem = {}
        with open("/proc/meminfo", "r") as f:
            for line in f:
                if ":" in line:
                    k, v = line.split(":", 1)
                    mem[k.strip()] = int(v.strip().split()[0])
        total = mem.get("MemTotal", 0)
        available = mem.get("MemAvailable", 0)
        used = total - available
        return {
            "total_kb": total,
            "used_kb": used,
            "available_kb": available,
            "percent": round((used / total) * 100, 1) if total > 0 else 0.0,
        }
    except Exception:
        return {"total_kb": 0, "used_kb": 0, "available_kb": 0, "percent": 0.0}


def read_swap():
    """Lecture SWAP depuis /proc/meminfo."""
    try:
        mem = {}
        with open("/proc/meminfo", "r") as f:
            for line in f:
                if ":" in line:
                    k, v = line.split(":", 1)
                    mem[k.strip()] = int(v.strip().split()[0])
        total = mem.get("SwapTotal", 0)
        free = mem.get("SwapFree", 0)
        used = total - free
        return {
            "total_kb": total,
            "used_kb": used,
            "free_kb": free,
            "percent": round((used / total) * 100, 1) if total > 0 else 0.0,
        }
    except Exception:
        return {"total_kb": 0, "used_kb": 0, "free_kb": 0, "percent": 0.0}


def read_disk():
    """Lecture disque depuis `df /`."""
    try:
        import shutil
        total, used, free = shutil.disk_usage("/")
        return {
            "total": total,
            "used": used,
            "free": free,
            "percent": round((used / total) * 100, 1) if total > 0 else 0.0,
        }
    except Exception:
        return {"total": 0, "used": 0, "free": 0, "percent": 0.0}


def read_loadavg():
    """Lecture charge système depuis /proc/loadavg."""
    try:
        with open("/proc/loadavg", "r") as f:
            parts = f.read().split()
        return {
            "load1": float(parts[0]),
            "load5": float(parts[1]),
            "load15": float(parts[2]),
        }
    except Exception:
        return {"load1": 0.0, "load5": 0.0, "load15": 0.0}


def collect():
    return {
        "timestamp": time.time(),
        "iso": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "cpu": read_cpu_percent(),
        "ram": read_ram(),
        "swap": read_swap(),
        "disk": read_disk(),
        "load": read_loadavg(),
    }


def main():
    os.makedirs(str(DATA_FILE.parent), exist_ok=True)
    data = collect()
    with open(DATA_FILE, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print(json.dumps(data, ensure_ascii=False))


if __name__ == "__main__":
    main()
