#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Мост Telegram-чат агента <-> трекер SYSTEM.

1) Читает сообщения агента из Telegram-чата (юзер-аккаунт @ds_cyber, MTProto —
   это НЕ getUpdates, очередь бота мы не трогаем) и заливает их в лог задачи
   трекера через POST /api/event.php.
2) Забирает из очереди диспетчера задачи (GET /api/pending.php) и отправляет их
   в чат агента, затем помечает отправленными (POST /api/sent.php).
   Включается флагом "send_enabled": true в конфиге чата. По умолчанию ВЫКЛЮЧЕНО.

Автоответов НЕТ. На каждое сообщение агента не отвечаем: писать агенту нужно
только когда он явно идёт не туда или выполнил предыдущую задачу — это
делается вручную (кнопка «Отправить» в диспетчере трекера или сообщение в чат).

Как определяется «конец сообщения» и «конец задания»:
  * сообщение незавершённое, пока агент его правит (edit_date меняется);
    через FINAL_QUIET секунд тишины после последней правки — сообщение финальное;
  * сообщение с трейсами инструментов (💻 terminal, 🐍 Running code, 🔎 Searching…)
    — это прогресс, а не результат;
  * задача выполнена, когда: в финальном сообщении есть явный маркер
    («задача выполнена», «✅» …) ИЛИ агент замолчал на BURST_QUIET секунд.
    Тогда шлём kind=system title=task-done — трекер закрывает текущую задачу
    и активирует следующую.

Конфиг: tg_bridge.json рядом со скриптом
    {
      "system_url": "http://127.0.0.1:8080",
      "ingest_key": "...",
      "session": "/home/main/tguser/session",
      "api_id": 25007977,
      "api_hash": "...",
      "chats": [
        {"chat_id": -1004434028499, "project_ref": "WINTASK",
         "agent_code": "win-195", "agent_bot": "@win1956312899_bot", "poll_sec": 20}
      ]
    }
"""
import asyncio
import json
import os
import re
import ssl
import sys
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

BASE = Path(__file__).resolve().parent
CONFIG = Path(os.environ.get("TG_BRIDGE_CONFIG", BASE / "tg_bridge.json"))
# состояние — в каталог, доступный пользователю сервиса (каталог dist принадлежит www-data)
STATE = Path(os.environ.get("TG_BRIDGE_STATE", "/var/lib/tg-bridge/state.json"))

FINAL_QUIET = int(os.environ.get("FINAL_QUIET_SEC", "90"))    # сек тишины после правки -> сообщение финальное
BURST_QUIET = int(os.environ.get("BURST_QUIET_SEC", "600"))  # сек тишины агента -> задача выполнена

# Протокол отчёта агента: строка вида  TASK|<id задачи>|<СТАТУС>
#   TASK|15|DONE   — задача 15 выполнена
#   TASK|16|ACTIVE — задача 16 в работе
#   TASK|16|REPORT — промежуточный отчёт
# Остальные статусы не применяем автоматически — их проверяет управляющий.
ALLOWED_STATUS = ("DONE", "ACTIVE", "REPORT")
TASK_REPORT = re.compile(r"(?im)^\s*TASK\s*\|\s*(\d+)\s*\|\s*([A-Z_]+)\s*(?:\|\s*([^\n]*))?")

# heartbeat Hermes: «⏳ Working — 6 min — iteration 37/40» — агент жив и работает
HEARTBEAT = re.compile(r"(⏳\s*Working|iteration\s*\d+/\d+|receiving stream response)", re.IGNORECASE)

# следы работы инструментов (прогресс, а не результат).
# «⏳ Working — N min — iteration X/Y» — heartbeat Hermes: агент ещё в работе.
TRACE_MARKERS = re.compile(
    r"(🐍\s*Running code|💻\s*terminal|🔎\s*Searching|📖\s*Reading|🧠\s*Thinking|"
    r"📝\s*Writing|🌐\s*Web|Tool call|Executing|"
    r"⏳\s*Working|iteration\s*\d+/\d+|receiving stream response)", re.IGNORECASE)


def time_now() -> float:
    return datetime.now(timezone.utc).timestamp()


def load_cfg() -> dict:
    if not CONFIG.is_file():
        raise SystemExit(f"Нет конфига {CONFIG}")
    return json.loads(CONFIG.read_text(encoding="utf-8"))


def load_state() -> dict:
    if STATE.is_file():
        try:
            return json.loads(STATE.read_text(encoding="utf-8"))
        except Exception:
            pass
    return {}


def save_state(s: dict) -> None:
    try:
        STATE.parent.mkdir(parents=True, exist_ok=True)
        STATE.write_text(json.dumps(s, ensure_ascii=False), encoding="utf-8")
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


def ts(dt) -> float:
    if dt is None:
        return 0.0
    return dt.replace(tzinfo=timezone.utc).timestamp()


def iso(dt) -> str:
    if dt is None:
        return ""
    return dt.replace(tzinfo=timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


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


def sender_info(m):
    s = m.sender
    return {
        "username": (getattr(s, "username", "") or "").lower(),
        "is_bot": bool(getattr(s, "is_bot", False)),
        "name": getattr(s, "first_name", "?") or "?",
    }


def is_trace(text: str) -> bool:
    return bool(TRACE_MARKERS.search(text or ""))


def notify_operator(text: str) -> None:
    """Уведомление оператору в Telegram — чтобы он не ждал реакции вручную."""
    token = (os.environ.get("HERMES_BOT_TOKEN") or os.environ.get("TELEGRAM_BOT_TOKEN") or "").strip()
    chat = os.environ.get("OPERATOR_CHAT_ID", "353169388").strip()
    if not token:
        return
    try:
        body = json.dumps({"chat_id": chat, "text": text, "disable_web_page_preview": True}).encode()
        req = urllib.request.Request(f"https://api.telegram.org/bot{token}/sendMessage",
                                     data=body,
                                     headers={"Content-Type": "application/json"},
                                     method="POST")
        ctx = ssl.create_default_context()
        with urllib.request.urlopen(req, timeout=15, context=ctx) as r:
            r.read()
        print(f"notify -> operator: {text[:60]}", flush=True)
    except Exception as e:
        print("notify err:", type(e).__name__, e, file=sys.stderr, flush=True)


async def main() -> int:
    cfg = load_cfg()
    st = load_state()
    sys_url = cfg["system_url"].rstrip("/")
    key = cfg.get("ingest_key", "")

    from telethon import TelegramClient
    client = TelegramClient(cfg["session"], int(cfg["api_id"]), cfg["api_hash"])
    await client.connect()
    if not await client.is_user_authorized():
        print("Сессия не авторизована", file=sys.stderr)
        return 1
    me = await client.get_me()
    print(f"bridge: {me.id} @{me.username}", flush=True)

    chats = cfg.get("chats", [])
    for ch in chats:
        cid = int(ch["chat_id"])
        s = st.setdefault(str(cid), {})
        s.setdefault("last_msg_id", 0)
        s.setdefault("texts", {})       # msg_id -> текст (ловим правки)
        s.setdefault("burst_last", 0.0)  # ts последней реплики агента
        s.setdefault("burst_notified", False)
        s.setdefault("burst_msgs", [])
        s.setdefault("task_ref", "")   # активная задача проекта (лог агента пишем в неё)
        s.setdefault("task_at", 0.0)
        s.setdefault("last_agent_ts", 0.0)  # когда агент последний раз писал
        s.setdefault("last_hb_ts", 0.0)     # когда был heartbeat (агент в работе)
        s.setdefault("silent_flag", False)  # предупреждение о тишине уже отправлено

    while True:
        for ch in chats:
            cid = int(ch["chat_id"])
            key_s = str(cid)
            s = st[key_s]
            agent_bot = (ch.get("agent_bot", "") or "").lower().lstrip("@")
            try:
                # 0) активная задача проекта — обновляем раз в минуту
                if time_now() - float(s.get("task_at") or 0) > 60:
                    code0, res0 = http("GET", sys_url + "/api/current_task.php?project_ref="
                                       + urllib.parse.quote(ch.get("project_ref", "")), None, key)
                    if code0 == 200 and isinstance(res0, dict) and res0.get("ok"):
                        s["task_ref"] = str(res0.get("task_ref") or "")
                        s["task_at"] = time_now()
                        save_state(st)

                # 1) новые сообщения
                msgs = await pull_chat(client, cid, s["last_msg_id"])
                # 2) последние сообщения — ловим правки (edit_date)
                known = {m.id for m in msgs}
                for m in await pull_recent(client, cid, limit=12):
                    if m.id not in known and m.id <= s["last_msg_id"]:
                        msgs.append(m)

                for m in sorted(msgs, key=lambda x: x.id):
                    txt = (m.text or "").strip()
                    if not txt:
                        s["last_msg_id"] = max(s["last_msg_id"], m.id)
                        continue
                    info = sender_info(m)
                    is_agent = bool(agent_bot) and info["username"] == agent_bot
                    edited = m.edit_date is not None
                    last_change = ts(m.edit_date or m.date)
                    final = (not edited) or (time_now() - last_change) > FINAL_QUIET
                    prev = s["texts"].get(str(m.id))
                    changed = prev is not None and prev != txt

                    if prev is None or changed:
                        # лог агента ведём в активную задачу проекта, а не в сам проект
                        target = s.get("task_ref") or ch.get("project_ref", "")
                        payload = {
                            "project_ref": target,
                            "kind": "agent" if is_agent else "chat",
                            "level": "info",
                            "title": f"TG {cid} · {info['username'] or info['name']}",
                            "text": txt,
                            "server": ch.get("agent_code"),
                            "chat_msg_id": int(m.id),
                            "meta": {
                                "ts": iso(m.date),
                                "edited": edited,
                                "final": final,
                                "trace": is_trace(txt),
                            },
                        }
                        code, res = http("POST", sys_url + "/api/event.php", payload, key)
                        s["texts"][str(m.id)] = txt
                        s["last_msg_id"] = max(s["last_msg_id"], m.id)
                        save_state(st)
                        tag = "agent" if is_agent else "chat"
                        print(f"tg->tracker {cid} msg={m.id} [{tag}] {'OK' if code == 200 else res}"
                              f"{' edit' if changed else ''}{' FINAL' if final else ''}", flush=True)

                    # учёт реплик агента для детекта конца задания.
                    # Только НОВЫЕ сообщения: иначе повторный обход старых реплик
                    # сбрасывал бы флаг «уже сообщили» и task-done сыпался бы каждые 20 сек.
                    if is_agent and prev is None:
                        s["burst_last"] = ts(m.date)
                        s["burst_notified"] = False
                        s["burst_msgs"] = (s["burst_msgs"] + [int(m.id)])[-20:]
                        # последняя реплика: это отчёт о работе (trace/heartbeat) или финальный ответ?
                        s["burst_last_trace"] = is_trace(txt)
                        s["burst_last_final"] = final
                    if prev is None:
                        # свежее сообщение — сторож тишины сбрасываем
                        s["silent_flag"] = False
                    if is_agent:
                        # последнее сообщение агента и последний heartbeat — по любому,
                        # даже если сообщение уже было в состоянии (после рестарта)
                        s["last_agent_ts"] = max(float(s.get("last_agent_ts") or 0), ts(m.date))
                        if HEARTBEAT.search(txt):
                            s["last_hb_ts"] = max(float(s.get("last_hb_ts") or 0), ts(m.date))
                        # работа по конкретной задаче: агент упоминает её номер или шлёт heartbeat
                        for tid in list(s.get("stall", {}).keys()):
                            if re.search(r"(?<!\d)" + re.escape(tid) + r"(?!\d)", txt):
                                s["stall"][tid]["progress_ts"] = time_now()
                                s["stall"][tid]["flag"] = False

                # 3) отчёт агента по протоколу  TASK|<id задачи>|<СТАТУС>
                #    TASK|15|DONE   — задача 15 выполнена
                #    TASK|16|ACTIVE — задача 16 в работе
                for mid in s.get("burst_msgs", []):
                    if str(mid) in s.get("reported", []):
                        continue          # этот отчёт уже обработан
                    t = s["texts"].get(str(mid), "")
                    m = TASK_REPORT.search(t or "")
                    if not m:
                        continue
                    task_id = int(m.group(1))
                    status = m.group(2).upper()
                    # тело отчёта = всё после строки протокола (раньше терялось)
                    body = (m.group(3) or "").strip()
                    if not body:
                        body = (txt[m.end():] or "").strip()
                    body = body[:900]
                    if status not in ALLOWED_STATUS:
                        # неизвестный статус автоматически не применяем — на проверку управляющему
                        http("POST", sys_url + "/api/event.php", {
                            "project_ref": ch.get("project_ref", ""),
                            "kind": "system", "level": "warn", "title": "task-status-unknown",
                            "text": f"TASK|{task_id}|{status} — статус не из списка "
                                    f"({'/'.join(ALLOWED_STATUS)}), нужна проверка",
                            "server": ch.get("agent_code"),
                            "meta": {"task_id": task_id, "status": status},
                        }, key)
                        s["reported"] = (s.get("reported", []) + [str(mid)])[-50:]
                        save_state(st)
                        print(f"tg->tracker {cid} TASK|{task_id}|{status} UNKNOWN — на проверку", flush=True)
                        continue
                    payload = {
                        "project_ref": ch.get("project_ref", ""),
                        "kind": "system",
                        "level": "ok" if status == "DONE" else "info",
                        "title": "task-status",
                        "text": body or status,
                        "server": ch.get("agent_code"),
                        "meta": {"task_id": task_id, "status": status,
                                 "ts": iso(datetime.now(timezone.utc))},
                    }
                    code, res = http("POST", sys_url + "/api/event.php", payload, key)
                    s["reported"] = (s.get("reported", []) + [str(mid)])[-50:]
                    save_state(st)
                    print(f"tg->tracker {cid} TASK|{task_id}|{status} {'OK' if code == 200 else res}", flush=True)

                    if status == "DONE":
                        # задача закрыта — сразу ставим следующую и сообщаем оператору
                        qc, qres = http("POST", sys_url + "/api/queue_next.php", {
                            "project_ref": ch.get("project_ref", ""),
                            "agent_code": ch.get("agent_code"),
                            "agent_bot": ch.get("agent_bot", ""),
                        }, key)
                        nxt = (qres.get('title') or '') if isinstance(qres, dict) else ''
                        nxtId = (qres.get('task_id') or 0) if isinstance(qres, dict) else 0
                        notify_operator(
                            f"✅ {ch.get('project_ref','')}: задача {task_id} закрыта агентом.\n"
                            + (f"Следующая поставлена: {nxtId} — {nxt}" if nxtId
                               else "Следующих задач нет — жду указаний."))
                    elif status == "REPORT":
                        notify_operator(f"📊 {ch.get('project_ref','')}: отчёт по задаче {task_id}.\n"
                                        f"{(body or '')[:600]}")
                    if status in ("REPORT", "ACTIVE"):
                        # задача не закрыта — следим, идёт ли по ней работа
                        s.setdefault("stall", {})[str(task_id)] = {
                            "report_ts": time_now(), "progress_ts": time_now(), "flag": False}
                        s.setdefault("stall_body", {})[str(task_id)] = body

                # 3a) сторож тишины: агент не пишет дольше порога — проверяем, идёт ли работа
                stale_sec = int(ch.get("stale_min", 10)) * 60
                last_ts = float(s.get("last_agent_ts") or 0)
                silent_sec = (time_now() - last_ts) if last_ts else 0
                if last_ts and silent_sec > stale_sec and not s.get("silent_flag"):
                    hb_ts = float(s.get("last_hb_ts") or 0)
                    working = bool(hb_ts) and (time_now() - hb_ts) < 180
                    last_txt = ""
                    for mid in reversed(s.get("burst_msgs", [])):
                        last_txt = s["texts"].get(str(mid), "")
                        if last_txt:
                            break
                    http("POST", sys_url + "/api/event.php", {
                        "project_ref": s.get("task_ref") or ch.get("project_ref", ""),
                        "kind": "system", "level": "info" if working else "warn",
                        "title": "agent-idle" if working else "agent-silent",
                        "text": (f"Агент жив: heartbeat был {int(time_now() - hb_ts)} сек назад."
                                 if working else
                                 f"Агент молчит {int(silent_sec / 60)} мин. Последняя реплика: "
                                 f"{(last_txt or '')[:200]} — нужно проверить, идёт ли работа."),
                        "server": ch.get("agent_code"),
                        "meta": {"silent_sec": int(silent_sec), "working": working},
                    }, key)
                    s["silent_flag"] = True
                    save_state(st)
                    print(f"tg->tracker {cid} {'agent-idle' if working else 'agent-silent'} "
                          f"({int(silent_sec / 60)} мин)", flush=True)
                    if not working:
                        notify_operator(
                            f"⚠️ {ch.get('project_ref','')}: агент молчит {int(silent_sec / 60)} мин. "
                            f"Последняя реплика: {(last_txt or '')[:200]}\n"
                            f"Проверяю, идёт ли работа.")

                # 3b) зависшая задача: агент отчитался, но задача не закрыта и по ней нет работы
                stall_min = int(ch.get("stall_min", 10))
                for tid, st in list(s.get("stall", {}).items()):
                    if st.get("flag"):
                        continue
                    idle = time_now() - float(st.get("progress_ts") or 0)
                    if idle < stall_min * 60:
                        continue
                    # задача всё ещё активна? (её могли закрыть вручную)
                    codeS, resS = http("GET", sys_url + f"/api/task_state.php?task_id={tid}", None, key)
                    tstate = (resS or {}).get("status") if isinstance(resS, dict) else None
                    if tstate == "done":
                        s["stall"].pop(tid, None)
                        continue
                    body_txt = (s.get("stall_body", {}) or {}).get(tid, "")
                    http("POST", sys_url + "/api/event.php", {
                        "project_ref": s.get("task_ref") or ch.get("project_ref", ""),
                        "kind": "system", "level": "warn", "title": "task-stalled",
                        "text": f"Задача {tid} не закрыта: агент отчитался и остановился, "
                                f"по ней нет работы {int(idle / 60)} мин. Отчёт агента: "
                                f"{body_txt[:600]} — нужно разобрать причины и разложить задачу на части.",
                        "server": ch.get("agent_code"),
                        "meta": {"task_id": int(tid), "idle_sec": int(idle)},
                    }, key)
                    st["flag"] = True
                    save_state(st)
                    print(f"tg->tracker {cid} TASK-STALLED {tid} ({int(idle / 60)} мин)", flush=True)
                    notify_operator(
                        f"⏸ {ch.get('project_ref','')}: задача {tid} зависла — агент отчитался и встал "
                        f"({int(idle / 60)} мин без работы по ней). Разбираю причины и разламываю задачу на части.")

                # 4) очередь диспетчера -> в чат (только если разрешено)
                if not ch.get("send_enabled", False):
                    continue
                code, res = http("GET", sys_url + f"/api/pending.php?chat_id={cid}", None, key)
                for item in (res.get("items") or []) if isinstance(res, dict) else []:
                    text = str(item.get("payload") or "").strip()
                    if not text:
                        continue
                    try:
                        await client.send_message(cid, text)
                        http("POST", sys_url + f"/api/sent.php?id={int(item['id'])}",
                             {"error": ""}, key)
                        print(f"tracker->tg {cid} dispatch={item['id']}", flush=True)
                    except Exception as e:
                        print("send err:", type(e).__name__, e, file=sys.stderr)
                        http("POST", sys_url + f"/api/sent.php?id={int(item['id'])}",
                             {"error": f"{type(e).__name__}: {e}"}, key)
            except Exception as e:
                # ошибку показываем в трекере, а не только в логе: молчащий сбой моста
                # выглядит как «агент не отвечает» и стоит дороже всего
                print("loop err:", type(e).__name__, e, file=sys.stderr, flush=True)
                http("POST", sys_url + "/api/event.php", {
                    "project_ref": ch.get("project_ref", ""),
                    "kind": "system", "level": "error", "title": "bridge-error",
                    "text": f"{type(e).__name__}: {e}",
                    "server": ch.get("agent_code"),
                }, key)
        await asyncio.sleep(min(int(c.get("poll_sec", 20)) for c in chats) if chats else 20)


if __name__ == "__main__":
    try:
        sys.exit(asyncio.run(main()))
    except KeyboardInterrupt:
        sys.exit(0)
