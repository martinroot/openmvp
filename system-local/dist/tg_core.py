#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Общая библиотека менеджеров направлений.

Каждое направление (WINTASK, ADULT, ...) — отдельный скрипт-менеджер, который
мониторит свой чат и умеет свои вещи. Общая часть вынесена сюда: чтение чата,
разбор протокола агента, отправка в трекер, уведомления оператору, очередь.
"""
import json
import os
import re
import ssl
import sys
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

# протокол отчёта агента: TASK|<id задачи>|<СТАТУС>
ALLOWED_STATUS = ("DONE", "ACTIVE", "REPORT")
TASK_REPORT = re.compile(r"(?im)^\s*TASK\s*\|\s*(\d+)\s*\|\s*([A-Z_]+)\s*(?:\|\s*([^\n]*))?")

# heartbeat Hermes: «⏳ Working — 6 min — iteration 37/40» — агент жив и работает
HEARTBEAT = re.compile(r"(⏳\s*Working|iteration\s*\d+/\d+|receiving stream response)", re.IGNORECASE)

# следы работы инструментов (прогресс, а не результат)
TRACE_MARKERS = re.compile(
    r"(🐍\s*Running code|💻\s*terminal|🔎\s*Searching|📖\s*Reading|🧠\s*Thinking|"
    r"📝\s*Writing|🌐\s*Web|Tool call|Executing|"
    r"⏳\s*Working|iteration\s*\d+/\d+|receiving stream response)", re.IGNORECASE)


def time_now() -> float:
    return datetime.now(timezone.utc).timestamp()


def iso(dt) -> str:
    if dt is None:
        return ""
    return dt.replace(tzinfo=timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def ts(dt) -> float:
    if dt is None:
        return 0.0
    return dt.replace(tzinfo=timezone.utc).timestamp()


def load_cfg(path: str) -> dict:
    p = Path(path)
    if not p.is_file():
        raise SystemExit(f"Нет конфига {p}")
    return json.loads(p.read_text(encoding="utf-8"))


def load_state(path: str) -> dict:
    p = Path(path)
    if p.is_file():
        try:
            return json.loads(p.read_text(encoding="utf-8"))
        except Exception:
            pass
    return {}


def save_state(path: str, s: dict) -> None:
    try:
        p = Path(path)
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(json.dumps(s, ensure_ascii=False), encoding="utf-8")
    except OSError as e:
        print("state save err:", e, file=sys.stderr)


def http(method: str, url: str, payload=None, key: str = None, timeout: int = 20):
    data = json.dumps(payload, ensure_ascii=False).encode() if payload is not None else None
    headers = {"Content-Type": "application/json"}
    if key:
        headers["X-Ingest-Key"] = key
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as r:
            raw = r.read().decode("utf-8", "replace")
            try:
                return r.status, json.loads(raw)
            except json.JSONDecodeError:
                return r.status, {"raw": raw[:300]}
    except Exception as e:
        return 0, {"error": f"{type(e).__name__}: {e}"}


def notify_operator(text: str) -> None:
    """Уведомление оператору в Telegram."""
    token = (os.environ.get("HERMES_BOT_TOKEN") or os.environ.get("TELEGRAM_BOT_TOKEN") or "").strip()
    chat = os.environ.get("OPERATOR_CHAT_ID", "353169388").strip()
    if not token:
        return
    try:
        body = json.dumps({"chat_id": chat, "text": text, "disable_web_page_preview": True}).encode()
        req = urllib.request.Request(f"https://api.telegram.org/bot{token}/sendMessage",
                                     data=body, headers={"Content-Type": "application/json"},
                                     method="POST")
        with urllib.request.urlopen(req, timeout=15, context=ssl.create_default_context()) as r:
            r.read()
        print(f"notify -> operator: {text[:70]}", flush=True)
    except Exception as e:
        print("notify err:", type(e).__name__, e, file=sys.stderr, flush=True)


def is_trace(text: str) -> bool:
    return bool(TRACE_MARKERS.search(text or ""))


def sender_info(m) -> dict:
    s = m.sender
    return {
        "username": (getattr(s, "username", "") or "").lower(),
        "is_bot": bool(getattr(s, "is_bot", False)),
        "name": getattr(s, "first_name", "?") or "?",
    }


async def pull_chat(client, cid: int, last_id: int, limit: int = 50):
    """Новые сообщения из чата (id > last_id), по возрастанию id."""
    out = []
    async for m in client.iter_messages(cid, limit=limit, min_id=last_id, reverse=True):
        out.append(m)
    return out


async def pull_recent(client, cid: int, limit: int = 12):
    """Последние сообщения — нужны, чтобы поймать правки уже импортированных."""
    out = []
    async for m in client.iter_messages(cid, limit=limit):
        out.append(m)
    out.reverse()
    return out


def report_body(text: str, m) -> str:
    """Тело отчёта = всё после строки протокола."""
    body = (m.group(3) or "").strip()
    if not body:
        body = (text[m.end():] or "").strip()
    return body[:900]
