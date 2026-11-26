#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Сторож чатов агентов. Без моделей и планировщика.

Один процесс следит за несколькими чатами. Направление (проект) определяется
по ID чата: в конфиге directions у каждого чата указан project_ref — он же
проверяется по tracker'у (у проекта должен быть тот же agent_chat_id).

Что делает (и больше ничего):
  1. Следит за чатом агента.
  2. По строке статуса агента закрывает текущую задачу в трекере.
  3. Выдаёт агенту следующную задачу — ровно одну.
  4. Пишет оператору в личку: что закрыто, что пошло дальше, если агент молчит.

План задач живёт в трекере SYSTEM: оператор сам добавляет и удаляет задачи
в дереве. Здесь нет ни моделей, ни критериев приёмки, ни бюджета.

Протокол агента (строка в сообщении):
    TASK|<id>|ACTIVE   — взял задачу
    TASK|<id>|REPORT   — прогресс
    TASK|<id>|DONE     — выполнил: задача закрывается
    TASK|<id>|BLOCKED  — застрял: ждём оператора, следующую задачу не выдаём

Конфиг: wintask_watch.json. Секреты: wintask_watch.env (600).
Состояние: state.json — по ключу ID чата.
"""
from __future__ import annotations

import asyncio
import calendar
import json
import os
import re
import time
import time as _walltime
import urllib.parse
import urllib.request
from pathlib import Path

BASE = Path(__file__).resolve().parent
# Путь к конфигу можно переопределить (тесты, другой каталог установки).
CFG_PATH = Path(os.environ.get("WATCH_CONFIG") or (BASE / "wintask_watch.json"))
CFG = json.loads(CFG_PATH.read_text(encoding="utf-8"))
STATE_FILE = Path(os.environ.get("WATCH_STATE") or (BASE / "state.json"))

STATUS_RE = re.compile(
    r"TASK\|(\w[\w.-]*)\|(ACTIVE|REPORT|DONE|BLOCKED|PAUSED)(?:\|run=([a-f0-9]{6,}))?",
    re.I)

# Задача считается закрытой / заблокированной / ждущей оператора.
SKIP_STATUS = ("done", "cancelled", "blocked", "paused")


def log(msg: str) -> None:
    print("%s %s" % (time.strftime("%Y-%m-%d %H:%M:%S"), msg), flush=True)


def env(name: str, default: str = "") -> str:
    v = os.environ.get(name, default)
    return v.strip() if isinstance(v, str) else default


def short(s: str, n: int) -> str:
    return " ".join((s or "").split())[:n]


# ── состояние ─────────────────────────────────────────────────────────────
def state_load() -> dict:
    try:
        st = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        return st if isinstance(st, dict) else {}
    except Exception:
        return {}


def state_save(st: dict) -> None:
    tmp = STATE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(st, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(STATE_FILE)


def dir_state(st: dict, chat_id: int) -> dict:
    ds = st.setdefault(str(chat_id), {
        "last_msg_id": 0, "task_id": None, "last_agent_ts": 0.0,
        "silence_notified": False, "started": False})
    ds.setdefault("rec_id", None)
    ds.setdefault("rec_ts", 0.0)
    return ds


# ── трекер ────────────────────────────────────────────────────────────────
def tracker(path: str, payload: dict | None = None) -> dict:
    url = CFG["tracker"].rstrip("/") + path
    headers = {"X-Ingest-Key": env("SYSTEM_INGEST_KEY")}
    data = None
    if payload is not None:
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=headers,
                                 method="POST" if data else "GET")
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        # 404 = задачи/объекта больше нет в трекере (оператор удалил).
        # Не роняем цикл и не сыплем ошибками — возвращаем как обычный ответ.
        if e.code == 404:
            return {"ok": False, "error": "not found"}
        raise


def direction_tasks(d: dict) -> tuple[list, dict]:
    """Задачи направления и сам проект. Пустой список = проект не найден."""
    ref = urllib.parse.quote(d["project_ref"])
    data = tracker("/api/tasks.php?project_ref=" + ref)
    if not data.get("ok"):
        raise RuntimeError("tracker: " + str(data.get("error")))
    proj = data.get("project") or {}
    # Проект в трекере должен смотреть в тот же чат, что и в конфиге.
    chat_in_tracker = proj.get("agent_chat_id")
    if chat_in_tracker and int(chat_in_tracker) != int(d["chat_id"]):
        raise RuntimeError("чат проекта %s в трекере = %s, в конфиге = %s"
                           % (d["project_ref"], chat_in_tracker, d["chat_id"]))
    return data.get("tasks", []), proj


def recurring_items(d: dict) -> list:
    """Периодические задачи направления из трекера (с признаком «пора»)."""
    ref = urllib.parse.quote(d["project_ref"])
    data = tracker("/api/recurring.php?project_ref=" + ref)
    if not data.get("ok"):
        raise RuntimeError("tracker: " + str(data.get("error")))
    return data.get("items", []) or []


class TaskGone(Exception):
    """Задачи больше нет в трекере (оператор удалил её)."""


def set_status(task_id: int, status: str, note: str = "") -> None:
    data = tracker("/api/task_status.php",
                   {"task_id": int(task_id), "status": status, "note": note})
    if not data.get("ok"):
        err = str(data.get("error") or "")
        if "not found" in err:
            raise TaskGone("задача %s удалена из трекера" % task_id)
        raise RuntimeError("tracker status: " + err)


def next_task(tasks: list, exclude=()) -> dict | None:
    """Следующая задача: по приоритету (деньги выше) и порядку в списке.

    exclude — id задач, которые выдавать нельзя (только что закрытая):
    страховка от повторной выдачи одной и той же задачи.

    Задачи с готовностью в будущем (ready_at) и задачи из цепочки RoadMap,
    где предыдущий шаг ещё не закрыт, — пропускаем: ждём своё время.
    """
    now = _walltime.time()
    for t in tasks:
        if int(t.get("id") or 0) in exclude:
            continue
        if (t.get("status") or "").lower() in SKIP_STATUS:
            continue
        # трекер решает по окну выдачи (МСК), времени готовности и цепочке
        if "issuable" in t:
            if not int(t.get("issuable") or 0):
                continue
        else:
            ready = t.get("ready_at")
            if ready:
                ts = parse_ready_at(str(ready))
                if ts is not None and ts > now:
                    continue
            if not chain_head_ready(t, tasks):
                continue
        return t
    return None


def parse_ready_at(value: str):
    """'Y-m-d H:i:s' из трекера (UTC) → epoch."""
    value = (value or "").strip()
    if not value:
        return None
    try:
        return calendar.timegm(_walltime.strptime(value, "%Y-%m-%d %H:%M:%S"))
    except Exception:
        return None


def chain_head_ready(t: dict, tasks: list) -> bool:
    """Из цепочки RoadMap выдаём только голову: предыдущие шаги должны быть закрыты."""
    chain = str(t.get("auto_chain") or "")
    step = int(t.get("auto_step") or 0)
    if not chain or step <= 1:
        return True
    for other in tasks:
        if str(other.get("auto_chain") or "") != chain:
            continue
        ostep = int(other.get("auto_step") or 0)
        if ostep >= step:
            continue
        if (other.get("status") or "").lower() in SKIP_STATUS:
            continue
        return False
    return True


def task_message(d: dict, t: dict) -> str:
    """Агенту уходит текст задачи: заголовок и, если есть, описание (вход/критерий).

    Без ID, протокола, приоритета и прочих служебных полей.
    Если у задачи есть цель/срок — добавляем их, чтобы агент знал, чего добиться.
    """
    title = (t.get("title") or "").strip()
    desc = (t.get("task_desc") or "").strip()
    goal = (t.get("goal") or "").strip()
    due = (t.get("due") or "").strip()
    parts = [title]
    if desc:
        parts.append(desc)
    if goal:
        line = "Чего добиться: " + goal
        if due:
            line += ". Срок: " + due
        parts.append(line)
    elif due:
        parts.append("Срок: " + due)
    if len(parts) == 1:
        return parts[0]
    return "\n\n".join(parts)


# ── футер с токенами ────────────────────────────────────────────────────────
# Формат 1 (машиночитаемый): ⚙️ IN=13782|OUT=38|CACHE_R=3072|CACHE_W=0|
#                              THINK=29|CALLS=1|MS=2016|USD=0.006157|MODEL=xxx|TURN=yyy
# Формат 2 (человеческий):    ⚙️ in 401K · out 51.6K · cache 9.26M/0 · think 44.5K ·
#                              27 calls · 556.0s · $0.00
_KV_RE = re.compile(r"([A-Za-z_]+)=([^|\n]+)")
_GEAR = "⚙"          # ⚙ — метка строки с токенами
_MULT = {"": 1, "K": 1000, "M": 1000000, "G": 1000000000, "T": 1000000000000}


def _num(raw) -> int:
    s = str(raw or "").strip().replace(",", ".")
    m = re.match(r"^([\d.]+)\s*([KkMmGgTt]?)$", s)
    if not m:
        try:
            return int(float(s))
        except Exception:
            return 0
    try:
        val = float(m.group(1))
    except Exception:
        return 0
    return int(val * _MULT.get(m.group(2).upper(), 1))


def _flt(raw) -> float:
    s = str(raw or "").strip().lstrip("$").replace(",", ".")
    m = re.search(r"[\d.]+", s)
    try:
        return float(m.group(0)) if m else 0.0
    except Exception:
        return 0.0


def parse_footer(text: str) -> Optional[dict]:
    """Достать из сообщения агента учёт вызовов. None — футера нет."""
    if not text:
        return None
    line = None
    for ln in text.splitlines():
        ln = ln.strip()
        if ln.startswith(_GEAR) or (_GEAR in ln):
            line = ln
            break
        if re.search(r"\b\d+\s+calls\b", ln, re.I) and re.search(r"\bin\b|\bIN=", ln):
            line = ln
            break
    if not line:
        return None

    if "=" in line and "|" in line:
        kv = {k.strip().upper(): v.strip() for k, v in _KV_RE.findall(line)}
        if "IN" in kv or "OUT" in kv:
            return {
                "tok_in": _num(kv.get("IN")),
                "tok_out": _num(kv.get("OUT")),
                "tok_cache": _num(kv.get("CACHE_R")),
                "tok_cache_write": _num(kv.get("CACHE_W")),
                "tok_think": _num(kv.get("THINK")),
                "calls": _num(kv.get("CALLS")) or 1,
                "duration_ms": _num(kv.get("MS")),
                "cost_usd": _flt(kv.get("USD")),
                "model": kv.get("MODEL", ""),
                "turn_id": kv.get("TURN", ""),
            }

    def grab(pattern):
        m = re.search(pattern, line, re.I)
        return m.group(1) if m else None

    # человеческий формат принимаем только если нашлись все числа
    tok_in_s = grab(r"\bin\s+([\d.]+\s*[KkMmGgTt]?)")
    tok_out_s = grab(r"\bout\s+([\d.]+\s*[KkMmGgTt]?)")
    calls_s = grab(r"([\d.]+)\s+calls")
    if not (tok_in_s and tok_out_s and calls_s):
        return None

    m_cache = re.search(r"cache\s+([\d.]+\s*[KkMmGgTt]?)\s*/\s*([\d.]+\s*[KkMmGgTt]?)", line, re.I)
    cache = (m_cache.group(1), m_cache.group(2)) if m_cache else None
    return {
        "tok_in": _num(tok_in_s),
        "tok_out": _num(tok_out_s),
        "tok_cache": _num(cache[0]) if cache else 0,
        "tok_cache_write": _num(cache[1]) if cache else 0,
        "tok_think": _num(grab(r"\bthink\s+([\d.]+\s*[KkMmGgTt]?)")),
        "calls": _num(calls_s) or 1,
        "duration_ms": int(_flt(grab(r"([\d.]+)\s*s\b")) * 1000),
        "cost_usd": _flt(grab(r"\$\s*([\d.]+)")) if "$" in line else 0.0,
        "model": grab(r"model\s+([\w./:-]+)") or "",
        "turn_id": "",
    }


async def report_usage(d: dict, foot: dict) -> None:
    """Отправить учёт вызовов в трекер (turn-агрегат по ходу агента)."""
    payload = {
        "type": "turn",
        "chat_id": int(d["chat_id"]),
        "agent_code": d.get("agent_code") or "unknown",
        "model": foot.get("model") or "",
        "turn_id": foot.get("turn_id") or "",
        "calls": int(foot.get("calls") or 1),
        "tok_in": int(foot.get("tok_in") or 0),
        "tok_cache": int(foot.get("tok_cache") or 0),
        "tok_cache_write": int(foot.get("tok_cache_write") or 0),
        "tok_out": int(foot.get("tok_out") or 0),
        "tok_think": int(foot.get("tok_think") or 0),
        "cost_usd": float(foot.get("cost_usd") or 0),
        "duration_ms": int(foot.get("duration_ms") or 0),
        "ts": int(time.time()),
    }
    try:
        tracker("/api/ingest.php", payload)
        log("[%s] учёт: %s вызов(ов), in=%d out=%d cache=%d think=%d, $%s, %d мс, %s"
            % (d["project_ref"], payload["calls"], payload["tok_in"], payload["tok_out"],
               payload["tok_cache"], payload["tok_think"], payload["cost_usd"],
               payload["duration_ms"], payload["model"] or "модель не указана"))
    except Exception as e:
        log("!! не смог отправить учёт в трекер: %r" % (e,))

# ── телеграм ──────────────────────────────────────────────────────────────
async def notify(client, d: dict, text: str) -> None:
    op = CFG.get("operator_chat_id")
    if not op or not CFG.get("notify_enabled", True):
        return
    try:
        await client.send_message(int(op), "[%s] %s" % (d.get("project_ref", "?"), text))
        log("оператору: " + text.replace("\n", " ")[:120])
    except Exception as e:
        log("!! не смог написать оператору: %r" % (e,))


async def send_task(client, d: dict, t: dict) -> None:
    await client.send_message(int(d["chat_id"]), task_message(d, t))
    log("[%s] выдал задачу [%s]: %s" % (d["project_ref"], t.get("id"),
                                        (t.get("title") or "")[:70]))



async def deliver_commands(client, st: dict, d: dict) -> None:
    """Команды от трекера (например /model ... --provider ...) уходят в чат агента."""
    try:
        ref = urllib.parse.quote(d["project_ref"])
        data = tracker("/api/chat_commands.php?project_ref=" + ref)
    except Exception as e:
        log("!! не смог взять команды [%s]: %r" % (d["project_ref"], e))
        return
    for it in (data.get("items") or []):
        cmd = str(it.get("cmd") or "").strip()
        cid = int(it.get("id") or 0)
        if not cmd or not cid:
            continue
        try:
            await client.send_message(int(d["chat_id"]), cmd)
        except Exception as e:
            log("!! не смог отправить команду [%s]: %r" % (cid, e))
            continue
        try:
            tracker("/api/chat_commands.php", {"type": "sent", "id": cid})
        except Exception as e:
            log("!! не смог отметить команду [%s]: %r" % (cid, e))
        log("[%s] команда отправлена в чат: %s" % (d["project_ref"], cmd[:120]))
        await notify(client, d, "⚙️ Команда отправлена: " + cmd[:160])

async def tick_direction(client, st: dict, d: dict) -> None:
    chat = int(d["chat_id"])
    agent = int(d["agent_id"])
    ds = dir_state(st, chat)

    await deliver_commands(client, st, d)

    msgs = await client.get_messages(chat, limit=50)
    if not msgs:
        return
    newest = max(m.id for m in msgs)

    if not ds.get("started"):
        # Первый запуск по этому чату: старую историю не исполняем.
        ds["last_msg_id"] = newest
        ds["started"] = True
        tasks, _ = direction_tasks(d)
        if not tasks:
            log("[%s] задач нет — ждём оператора" % d["project_ref"])
        await maybe_issue(client, st, d)
        return

    fresh = [m for m in msgs if m.id > ds.get("last_msg_id", 0)]
    if not fresh:
        # план могли пополнить — отдаём задачу, если в направлении ничего не в работе
        await drop_gone_task(st, d)
        await maybe_issue(client, st, d)
        await silence_check(client, st, d)
        return
    ds["last_msg_id"] = newest

    for m in sorted(fresh, key=lambda x: x.id):
        text = m.text or ""
        # переписку кладём в ленту трекера (dedup по chat_msg_id внутри трекера)
        if m.sender_id == agent:
            ds["last_agent_ts"] = time.time()
            ds["silence_notified"] = False
        try:
            tracker("/api/ingest.php", {
                "type": "event",
                "chat_id": chat,
                "task_id": ds.get("task_id"),
                "kind": "agent" if m.sender_id == agent else "chat",
                "level": "info",
                "text": text[:4000],
                "chat_msg_id": m.id,
            })
        except Exception as e:
            log("!! не смог записать сообщение в ленту [%s]: %r" % (d["project_ref"], e))
        if m.sender_id != agent:
            continue
        matches = list(STATUS_RE.finditer(text))
        foot = parse_footer(text)
        if foot:
            await report_usage(d, foot)
            if not matches:
                # ответ с токенами без явного статуса = агент закончил текущую задачу
                await finish_by_tokens(client, st, d, foot)
                continue
        if not matches:
            continue
        mt = matches[-1]
        await handle_status(client, st, d, mt.group(1), mt.group(2).upper(), text)

    await drop_gone_task(st, d)
    await silence_check(client, st, d)


async def drop_gone_task(st: dict, d: dict) -> None:
    """Задачу могли удалить из плана — тогда снимаем её с направления."""
    ds = dir_state(st, int(d["chat_id"]))
    cur = ds.get("task_id")
    if not cur:
        return
    try:
        tasks, _ = direction_tasks(d)
    except Exception:
        return
    if tasks and not any(int(t["id"]) == int(cur) for t in tasks):
        log("[%s] задача [%s] удалена из плана — снимаю" % (d["project_ref"], cur))
        ds["task_id"] = None
        ds["silence_notified"] = False


async def maybe_issue(client, st: dict, d: dict) -> None:
    """Выдать задачу, если в направлении ничего не в работе, а в плане есть задача.

    Сначала — периодическая задача, если она подошла по времени: чекап важнее
    плана, но идёт только когда агент свободен.
    """
    ds = dir_state(st, int(d["chat_id"]))
    if ds.get("task_id"):
        return
    # зависшая периодическая задача (агент не ответил) — снимаем через полчаса
    if ds.get("rec_id") and time.time() - float(ds.get("rec_ts") or 0) > 1800:
        log("[%s] периодическая задача [%s] без ответа 30 мин — снимаю"
            % (d["project_ref"], ds["rec_id"]))
        ds["rec_id"] = None
        ds["rec_ts"] = 0.0
    if ds.get("rec_id"):
        return
    try:
        rec_items = recurring_items(d)
    except Exception as e:
        log("!! не смог взять периодические задачи [%s]: %r" % (d["project_ref"], e))
        rec_items = []
    for it in rec_items:
        if int(it.get("due") or 0) != 1:
            continue
        rid = int(it.get("id") or 0)
        title = (it.get("title") or "").strip()
        if not rid or not title:
            continue
        ds["rec_id"] = rid
        ds["rec_ts"] = time.time()
        ds["last_agent_ts"] = time.time()
        ds["silence_notified"] = False
        try:
            tracker("/api/recurring.php", {"type": "sent", "id": rid})
        except Exception as e:
            log("!! не смог отметить периодическую задачу [%r: %r" % (rid, e))
        await client.send_message(int(d["chat_id"]), title)
        log("[%s] выдана периодическая задача [%s] (каждые %s мин): %s"
            % (d["project_ref"], rid, it.get("every_min"), title[:80]))
        await notify(client, d, "🔁 [%s] периодическая задача [%s]: %s"
                     % (d["project_ref"], rid, title[:120]))
        return
    try:
        tasks, _ = direction_tasks(d)
    except Exception as e:
        log("!! не смог взять план [%s]: %r" % (d["project_ref"], e))
        return
    t = next_task(tasks)
    if not t:
        return
    ds["task_id"] = int(t["id"])
    ds["last_agent_ts"] = time.time()
    ds["silence_notified"] = False
    await send_task(client, d, t)
    log("[%s] выдана задача [%s]: %s" % (d["project_ref"], t["id"], (t.get("title") or "")[:90]))
    await notify(client, d, "📤 [%s] выдана задача [%s]: %s"
                 % (d["project_ref"], t["id"], (t.get("title") or "")[:120]))


async def finish_by_tokens(client, st: dict, d: dict, foot: dict) -> None:
    """Ответ агента с токенами = текущая задача закончена."""
    ds = dir_state(st, int(d["chat_id"]))
    cur = ds.get("task_id")
    if not cur:
        # в работе была периодическая задача — ответ с токенами её закрывает
        if ds.get("rec_id"):
            rid = ds["rec_id"]
            ds["rec_id"] = None
            ds["rec_ts"] = 0.0
            log("[%s] периодическая задача [%s] закрыта по ответу с токенами"
                % (d["project_ref"], rid))
            await notify(client, d, "✅ Периодическая задача [%s] закрыта." % rid)
            await maybe_issue(client, st, d)
        return
    note = ("агент закончил ход (ответ с токенами): %s вызов(ов), $%s, %d мс"
            % (foot.get("calls"), foot.get("cost_usd"), foot.get("duration_ms")))
    try:
        set_status(int(cur), "done", note)
    except TaskGone:
        ds["task_id"] = None
        ds["silence_notified"] = False
        log("[%s] задача [%s] удалена из трекера — снимаю с учёта" % (d["project_ref"], cur))
        return
    except Exception as e:
        log("!! не смог закрыть задачу по токенам: %r" % (e,))
        return
    ds["task_id"] = None
    ds["silence_notified"] = False
    log("[%s] задача [%s] закрыта по ответу с токенами" % (d["project_ref"], cur))
    await notify(client, d, "✅ Задача [%s] закрыта — агент закончил ход (ответ с токенами)." % cur)
    await maybe_issue(client, st, d)


async def handle_status(client, st: dict, d: dict, tid: str, status: str, text: str) -> None:
    ds = dir_state(st, int(d["chat_id"]))
    cur = ds.get("task_id")
    if status == "ACTIVE":
        if cur and str(cur) != tid:
            log("[%s] агент взял [%s], а в работе [%s]" % (d["project_ref"], tid, cur))
            await notify(client, d, "⚠️ Агент взял задачу [%s], а активна [%s]. Оставляю как есть."
                         % (tid, cur))
        return
    if status == "REPORT":
        log("[%s] отчёт по [%s]" % (d["project_ref"], tid))
        return
    if status == "PAUSED":
        log("[%s] агент поставил [%s] на паузу" % (d["project_ref"], tid))
        await notify(client, d, "⏸ Агент поставил задачу [%s] на паузу." % tid)
        return
    if status == "BLOCKED":
        if cur and str(cur) != tid:
            log("[%s] блокер по чужой задаче [%s] — игнор (в работе [%s])"
                % (d["project_ref"], tid, cur))
            await notify(client, d, "⚠️ Блокер по задаче [%s], а в работе [%s]. Следующую не выдаю."
                         % (tid, cur))
            return
        try:
            set_status(int(tid), "BLOCKED", "агент: " + short(text, 400))
        except TaskGone:
            log("[%s] задача [%s] удалена из трекера — просто снимаю её" % (d["project_ref"], tid))
            ds["task_id"] = None
            return
        except Exception as e:
            log("!! не смог отметить BLOCKED: %r" % (e,))
        ds["task_id"] = None
        await notify(client, d, "⛔ Задача [%s] заблокирована агентом. Следующую не выдаю до "
                                "вашего решения.\n%s" % (tid, short(text, 400)))
        return
    if status == "DONE":
        if ds.get("rec_id"):
            # в работе была периодическая задача — считаем её отработанной
            rid = ds["rec_id"]
            ds["rec_id"] = None
            ds["rec_ts"] = 0.0
            log("[%s] периодическая задача [%s] — агент отчитался DONE" % (d["project_ref"], rid))
        if cur and str(cur) != tid:
            log("[%s] DONE по чужой задаче [%s] — игнор (в работе [%s])"
                % (d["project_ref"], tid, cur))
            await notify(client, d, "⚠️ Агент отчитался DONE по задаче [%s], а в работе [%s]. "
                                    "Не закрываю — сверьте, какая задача сделана." % (tid, cur))
            return
        try:
            set_status(int(tid), "DONE", "агент: " + short(text, 600))
        except TaskGone:
            # Задачи уже нет в плане (оператор удалил/пересобрал) — это не сбой.
            log("[%s] задача [%s] удалена из плана — закрывать нечего" % (d["project_ref"], tid))
            ds["task_id"] = None
            tasks, _ = direction_tasks(d)
            nxt = next_task(tasks)
            if nxt:
                ds["task_id"] = int(nxt["id"])
                ds["last_agent_ts"] = time.time()
                ds["silence_notified"] = False
                await send_task(client, d, nxt)
                await notify(client, d, "✅ Задача [%s] закрыта. Следующая: [%s] %s"
                             % (tid, nxt["id"], (nxt.get("title") or "")[:120]))
            else:
                await notify(client, d, "✅ Задача [%s] закрыта. Других задач в направлении нет." % tid)
            return
        except Exception as e:
            log("!! не смог закрыть задачу: %r" % (e,))
            return
        log("[%s] задача [%s] закрыта" % (d["project_ref"], tid))
        ds["task_id"] = None
        tasks, _ = direction_tasks(d)
        nxt = next_task(tasks, exclude={int(tid)})
        if nxt:
            ds["task_id"] = int(nxt["id"])
            ds["last_agent_ts"] = time.time()
            ds["silence_notified"] = False
            await send_task(client, d, nxt)
            await notify(client, d, "✅ Задача [%s] закрыта. Следующая: [%s] %s"
                         % (tid, nxt["id"], (nxt.get("title") or "")[:120]))
        else:
            await notify(client, d, "✅ Задача [%s] закрыта. Других задач в направлении нет." % tid)


async def silence_check(client, st: dict, d: dict) -> None:
    """Агент молчит — спросить оператора (один раз на задачу)."""
    ds = dir_state(st, int(d["chat_id"]))
    cur = ds.get("task_id")
    if not cur or ds.get("silence_notified"):
        return
    last = float(ds.get("last_agent_ts") or 0)
    if not last:
        return
    quiet = float(d.get("silent_minutes", CFG.get("silent_minutes", 10))) * 60
    if time.time() - last < quiet:
        return
    ds["silence_notified"] = True
    log("[%s] агент молчит %.0f мин по задаче [%s]"
        % (d["project_ref"], (time.time() - last) / 60, cur))
    await notify(client, d, "⏳ Агент не отвечает %.0f мин по задаче [%s]. Если он работает "
                            "(⏳ Working) — всё в порядке, иначе стоит пнуть его."
                 % ((time.time() - last) / 60, cur))


async def discover(client, st: dict) -> list[dict]:
    """Направления берём из трекера: там ID чата — рабочее поле.

    Добавили в трекере проект с ID чата — скрипт сам за ним следит,
    без правки конфигов. Нет связи с трекером — падаем на конфиг.
    """
    rows = []
    try:
        data = tracker("/api/directions.php")
        rows = data.get("directions") or []
    except Exception as e:
        log("!! не смог получить направления из трекера: %r" % (e,))
    if not rows:
        return [dict(d) for d in CFG.get("directions", []) if d.get("enabled", True)]
    # agent_id из конфига — приоритетнее (не нужно резолвить)
    known = {int(d["chat_id"]): d for d in CFG.get("directions", []) if d.get("chat_id")}
    agents = st.setdefault("agents", {})
    out = []
    for r in rows:
        try:
            chat = int(r["chat_id"])
        except Exception:
            continue
        ref = r.get("project_ref") or r.get("title") or str(chat)
        aid = None
        if chat in known and known[chat].get("agent_id"):
            aid = int(known[chat]["agent_id"])
        elif agents.get(ref):
            aid = int(agents[ref])
        elif r.get("agent_name"):
            try:
                ent = await client.get_entity(r["agent_name"])
                aid = ent.id
                agents[ref] = aid
            except Exception as e:
                log("!! не смог определить агента %s: %r" % (r.get("agent_name"), e))
        if not aid:
            log("!! у направления %s нет агента (укажи @бота) — пропускаю" % ref)
            continue
        out.append({
            "project_ref": ref,
            "chat_id": chat,
            "agent_id": int(aid),
            "agent_mention": r.get("agent_name") or known.get(chat, {}).get("agent_mention", ""),
            "agent_code": r.get("agent_code") or "",
        })
    return out


async def main() -> int:
    from telethon import TelegramClient

    session = env("TG_SESSION", str(BASE / "session"))
    api_id = int(env("TG_API_ID", "0"))
    api_hash = env("TG_API_HASH")
    if not api_id or not api_hash:
        log("!! нет TG_API_ID/TG_API_HASH в окружении")
        return 2

    client = TelegramClient(session, api_id, api_hash)
    await client.connect()
    if not await client.is_user_authorized():
        log("!! Telegram-сессия не авторизована: %s" % session)
        return 3
    me = await client.get_me()
    log("залогинен как @%s (id %s)" % (me.username, me.id))

    st = state_load()
    while True:
        directions = await discover(client, st)
        if not client.is_connected():
            log("!! Telethon отключился — переподключаюсь")
            try:
                await client.connect()
            except Exception as e:
                log("!! reconnect fail: %r" % e)
        for d in directions:
            try:
                await tick_direction(client, st, d)
            except (ConnectionError, OSError) as e:
                log("!! [%s] сеть: %r — переподключаюсь" % (d.get("project_ref"), e))
                try:
                    await client.disconnect()
                except Exception:
                    pass
                for _ in range(3):
                    try:
                        await client.connect()
                        break
                    except Exception as e2:
                        log("!! reconnect: %r" % e2)
                        await asyncio.sleep(5)
            except Exception as e:
                log("!! [%s] сбой: %r" % (d.get("project_ref"), e))
        st["last_tick"] = time.time()
        state_save(st)
        await asyncio.sleep(float(CFG.get("poll_seconds", 10)))


if __name__ == "__main__":
    import sys
    sys.exit(asyncio.run(main()))
