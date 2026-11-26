#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Сборщик метрик Hermes -> SYSTEM (трекер проектов).

Источник: state.db таблица sessions — там Hermes держит реальные
input/output/cache_read/cache_write токены, модель, estimated_cost_usd
и время начала/конца сессии.

Ставится на КАЖДЫЙ сервер с агентом (напр. /root/netagent/collect_metrics.py).

Ручной запуск:
    HERMES_HOME=/home/main/.hermes INGEST_KEY=... AGENT_CODE=wifepornhub \
    PROJECT_REF="Монетизация" python3 collect_metrics.py

Cron (каждые 5 минут) — пример для /etc/cron.d/system-metrics:
    */5 * * * * main HERMES_HOME=/home/main/.hermes INGEST_KEY=xxx AGENT_CODE=wifepornhub PROJECT_REF="Монетизация" python3 /root/netagent/collect_metrics.py >/dev/null 2>&1
"""
import json
import os
import sqlite3
import ssl
import sys
import urllib.request
from pathlib import Path

SYSTEM_URL = os.environ.get("SYSTEM_URL", "http://5.144.179.170:8080").rstrip("/")
INGEST_KEY = os.environ.get("INGEST_KEY", "")
AGENT_CODE = os.environ.get("AGENT_CODE", "")
PROJECT_REF = os.environ.get("PROJECT_REF", "")

HERMES_HOME = Path(os.environ.get("HERMES_HOME", Path.home() / ".hermes"))
STATE_DB = HERMES_HOME / "state.db"
STATE = HERMES_HOME / "cron/.system_collect_state"

SOURCES = ("cron", "telegram", "cli", "gateway")


def last_id() -> str:
    if STATE.is_file():
        try:
            return STATE.read_text().strip()
        except OSError:
            pass
    return ""


def save_id(sid: str) -> None:
    try:
        STATE.parent.mkdir(parents=True, exist_ok=True)
        STATE.write_text(sid)
    except OSError:
        pass


def post(payload: dict) -> bool:
    if not INGEST_KEY:
        print("Нет INGEST_KEY", file=sys.stderr)
        return False
    data = json.dumps(payload).encode()
    req = urllib.request.Request(
        SYSTEM_URL + "/api/ingest.php",
        data=data,
        headers={"Content-Type": "application/json", "X-Ingest-Key": INGEST_KEY},
        method="POST",
    )
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    try:
        with urllib.request.urlopen(req, timeout=25, context=ctx) as r:
            r.read()
            return r.status == 200
    except Exception as e:
        print("POST failed:", type(e).__name__, e, file=sys.stderr)
        return False


def main() -> int:
    if not AGENT_CODE:
        print("Нет AGENT_CODE", file=sys.stderr)
        return 2
    if not STATE_DB.is_file():
        print(f"Нет {STATE_DB}", file=sys.stderr)
        return 1

    since = last_id()
    con = sqlite3.connect(f"file:{STATE_DB}?mode=ro", uri=True)
    con.row_factory = sqlite3.Row
    where = ["(input_tokens > 0 OR output_tokens > 0)"]
    args: list = []
    if since:
        where.append("started_at > (SELECT started_at FROM sessions WHERE id = ?)")
        args.append(since)
    sql = (
        "SELECT id, source, model, input_tokens, output_tokens, "
        "cache_read_tokens, cache_write_tokens, estimated_cost_usd, actual_cost_usd, "
        "started_at, ended_at, api_call_count "
        "FROM sessions WHERE " + " AND ".join(where) +
        " ORDER BY started_at ASC LIMIT 500"
    )
    rows = list(con.execute(sql, args))
    con.close()

    sent = 0
    last = since
    for r in rows:
        started = r["started_at"]
        ended = r["ended_at"]
        dur_ms = int((ended - started) * 1000) if (started and ended) else 0
        cost = r["actual_cost_usd"]
        if cost is None:
            cost = r["estimated_cost_usd"] or 0.0
        payload = {
            "agent_code": AGENT_CODE,
            "server": AGENT_CODE,
            "model": r["model"] or "",
            "project_ref": PROJECT_REF,
            "tok_in": int(r["input_tokens"] or 0),
            "tok_cache": int(r["cache_read_tokens"] or 0) + int(r["cache_write_tokens"] or 0),
            "tok_out": int(r["output_tokens"] or 0),
            "cost_usd": round(float(cost), 6),
            "duration_ms": dur_ms,
            "job_id": f"{r['source']}:{r['id']}",
            "ts": started,
            "error": "",
        }
        if post(payload):
            sent += 1
            last = r["id"]
        else:
            break
    if last:
        save_id(last)
    print(f"sent={sent} last={last}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
