#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Менеджер направления WINTASK.

Мониторит чат агента @win1956312899_bot (-1004434028499), ведёт лог задач
в трекере, гоняет маршрутизацию (DONE -> следующая задача) и — специфика
направления — умеет ДОКАПЫВАТЬСЯ сам, когда задача зависла:

  * если причину видно из отчёта агента — ждём управляющего (он разложит задачу);
  * если причина непонятна — менеджер сам ставит агенту «задачу по сбору ситуации»:
    агент запускает диагностику (dist/diag_collect.py) и присылает факты.
    Список гипотез направления — в DIAGNOSIS.md.

Конфиг: mgr_wintask.json рядом со скриптом.
"""
import asyncio
import json
import os
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from tg_core import (ALLOWED_STATUS, HEARTBEAT, TASK_REPORT, http, is_trace, iso, load_cfg,
                     load_state, notify_operator, pull_chat, pull_recent, report_body,
                     save_state, sender_info, time_now, ts)

BASE = Path(__file__).resolve().parent
CFG_PATH = os.environ.get("MGR_CONFIG", str(BASE / "mgr_wintask.json"))
STATE_PATH = os.environ.get("MGR_STATE", "/var/lib/tg-mgr/wintask.json")


async def main() -> int:
    cfg = load_cfg(CFG_PATH)
    st = load_state(STATE_PATH)
    sys_url = cfg["system_url"].rstrip("/")
    key = cfg.get("ingest_key", "")
    project_ref = cfg.get("project_ref", "")
    agent_bot = (cfg.get("agent_bot", "") or "").lower().lstrip("@")
    stale_min = int(cfg.get("stale_min", 10))
    stall_min = int(cfg.get("stall_min", 10))
    facts_task_id = int(cfg.get("facts_task_id", 0))

    from telethon import TelegramClient
    client = TelegramClient(cfg["session"], int(cfg["api_id"]), cfg["api_hash"])
    await client.connect()
    if not await client.is_user_authorized():
        print("Сессия не авторизована", file=sys.stderr)
        return 1
    me = await client.get_me()
    print(f"mgr wintask: {me.id} @{me.username} чат {cfg['chat_id']} проект {project_ref}", flush=True)

    s = st.setdefault("chat", {})
    s.setdefault("last_msg_id", 0)
    s.setdefault("texts", {})
    s.setdefault("last_agent_ts", 0.0)
    s.setdefault("last_hb_ts", 0.0)
    s.setdefault("silent_flag", False)
    s.setdefault("stall", {})
    s.setdefault("stall_body", {})
    s.setdefault("reported", [])
    s.setdefault("task_ref", "")
    s.setdefault("task_at", 0.0)

    # первый запуск: не переигрываем историю (иначе прилетят старые отчёты и задачи)
    fresh = not Path(STATE_PATH).is_file()
    if fresh:
        cid0 = int(cfg["chat_id"])
        hist = await pull_recent(client, cid0, limit=20)
        if hist:
            s["last_msg_id"] = max(m.id for m in hist)
            s["reported"] = [str(m.id) for m in hist]
            s["last_agent_ts"] = max([ts(m.date) for m in hist if sender_info(m)["username"] == agent_bot]
                                      or [0.0])
            print(f"bootstrap: история пропущена, last_msg_id={s['last_msg_id']}", flush=True)
            save_state(STATE_PATH, st)

    while True:
        cid = int(cfg["chat_id"])
        try:
            # активная задача проекта
            if time_now() - float(s.get("task_at") or 0) > 60:
                _c, res = http("GET", sys_url + "/api/current_task.php?project_ref="
                               + urllib_quote(project_ref), None, key)
                if _c == 200 and isinstance(res, dict) and res.get("ok"):
                    s["task_ref"] = str(res.get("task_ref") or "")
                    s["active_id"] = int(res.get("task_id") or 0)
                    s["task_at"] = time_now()
                    save_state(STATE_PATH, st)

            msgs = await pull_chat(client, cid, s["last_msg_id"])
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
                final = (not edited) or (time_now() - ts(m.edit_date or m.date)) > 90
                prev = s["texts"].get(str(m.id))
                changed = prev is not None and prev != txt

                if prev is None or changed:
                    http("POST", sys_url + "/api/event.php", {
                        "project_ref": s.get("task_ref") or project_ref,
                        "kind": "agent" if is_agent else "chat",
                        "level": "info",
                        "title": f"TG {cid} · {info['username'] or info['name']}",
                        "text": txt,
                        "server": cfg.get("agent_code"),
                        "chat_msg_id": int(m.id),
                        "meta": {"ts": iso(m.date), "edited": edited, "final": final,
                                 "trace": is_trace(txt)},
                    }, key)
                    s["texts"][str(m.id)] = txt
                    s["last_msg_id"] = max(s["last_msg_id"], m.id)
                    save_state(STATE_PATH, st)
                    print(f"tg->tracker msg={m.id} [{'agent' if is_agent else 'chat'}]"
                          f"{' edit' if changed else ''}", flush=True)

                if prev is None:
                    s["silent_flag"] = False
                if is_agent:
                    s["last_agent_ts"] = max(float(s.get("last_agent_ts") or 0), ts(m.date))
                    if HEARTBEAT.search(txt):
                        s["last_hb_ts"] = max(float(s.get("last_hb_ts") or 0), ts(m.date))
                    for tid in list(s.get("stall", {}).keys()):
                        if re.search(r"(?<!\d)" + re.escape(tid) + r"(?!\d)", txt):
                            s["stall"][tid]["progress_ts"] = time_now()
                            s["stall"][tid]["flag"] = False

            # --- протокол агента -------------------------------------------------
            for mid in s.get("stall", {}).keys():
                pass
            for m in list(msgs):
                if not sender_info(m)["username"] == agent_bot:
                    continue
                t = s["texts"].get(str(m.id), "")
                mrep = TASK_REPORT.search(t or "")
                if not mrep:
                    continue
                if str(m.id) in s.get("reported", []):
                    continue
                task_id = int(mrep.group(1))
                status = mrep.group(2).upper()
                body = report_body(t or "", mrep)
                s["reported"] = (s.get("reported", []) + [str(m.id)])[-50:]

                # ЗАЩИТА: агент часто нумерует задачи по-своему («Задача 17.3»).
                # Отметку применяем ТОЛЬКО к текущей активной задаче трекера.
                # Иначе — не применяем, а помечаем как несовпадение (управляющий разберётся).
                active_id = int(s.get("active_id") or 0)
                if active_id and task_id != active_id:
                    http("POST", sys_url + "/api/event.php", {
                        "project_ref": s.get("task_ref") or project_ref,
                        "kind": "system", "level": "warn", "title": "task-status-mismatch",
                        "text": f"Агент отчитался по задаче {task_id}, а активна {active_id} "
                                f"({s.get('task_ref', '')}). Статус НЕ применён — возможно агент "
                                f"нумерует по-своему. Текст отчёта: {(body or '')[:400]}",
                        "server": cfg.get("agent_code"),
                        "meta": {"task_id": task_id, "status": status,
                                 "active_id": active_id}}, key)
                    save_state(STATE_PATH, st)
                    print(f"TASK|{task_id}|{status} MISMATCH (active {active_id}) — не применено", flush=True)
                    notify_operator(f"⚠️ {project_ref}: агент отчитался по задаче {task_id}, "
                                    f"а активна {active_id} ({s.get('task_ref', '')}). "
                                    f"Отметку не поставил — разбираюсь.")
                    continue

                if status not in ALLOWED_STATUS:
                    http("POST", sys_url + "/api/event.php", {
                        "project_ref": s.get("task_ref") or project_ref,
                        "kind": "system", "level": "warn", "title": "task-status-unknown",
                        "text": f"TASK|{task_id}|{status} — статус не из списка "
                                f"({'/'.join(ALLOWED_STATUS)}), нужна проверка",
                        "server": cfg.get("agent_code"),
                        "meta": {"task_id": task_id, "status": status}}, key)
                    print(f"TASK|{task_id}|{status} UNKNOWN", flush=True)
                    continue

                http("POST", sys_url + "/api/event.php", {
                    "project_ref": s.get("task_ref") or project_ref,
                    "kind": "system", "level": "ok" if status == "DONE" else "info",
                    "title": "task-status", "text": body or status,
                    "server": cfg.get("agent_code"),
                    "meta": {"task_id": task_id, "status": status, "ts": iso(m.date)}}, key)
                s.setdefault("stall", {})[str(task_id)] = {
                    "report_ts": time_now(), "progress_ts": time_now(), "flag": False}
                s.setdefault("stall_body", {})[str(task_id)] = body
                save_state(STATE_PATH, st)
                print(f"TASK|{task_id}|{status} OK", flush=True)

                if status == "DONE":
                    _c, qres = http("POST", sys_url + "/api/queue_next.php", {
                        "project_ref": project_ref, "agent_code": cfg.get("agent_code"),
                        "agent_bot": cfg.get("agent_bot", "")}, key)
                    nxt = (qres.get('title') or '') if isinstance(qres, dict) else ''
                    nxtId = (qres.get('task_id') or 0) if isinstance(qres, dict) else 0
                    notify_operator(f"✅ {project_ref}: задача {task_id} закрыта агентом.\n"
                                    + (f"Следующая поставлена: {nxtId} — {nxt}" if nxtId
                                       else "Следующих задач нет — жду указаний."))
                elif status == "REPORT":
                    notify_operator(f"📊 {project_ref}: отчёт по задаче {task_id}.\n{(body or '')[:600]}")

            # --- сторож тишины ----------------------------------------------------
            last_ts = float(s.get("last_agent_ts") or 0)
            silent_sec = (time_now() - last_ts) if last_ts else 0
            if last_ts and silent_sec > stale_min * 60 and not s.get("silent_flag"):
                hb = float(s.get("last_hb_ts") or 0)
                working = bool(hb) and (time_now() - hb) < 180
                s["silent_flag"] = True
                save_state(STATE_PATH, st)
                print(f"agent {'alive' if working else 'silent'} ({int(silent_sec / 60)} мин)", flush=True)
                if not working:
                    notify_operator(f"⚠️ {project_ref}: агент молчит {int(silent_sec / 60)} мин. "
                                    f"Проверяю, идёт ли работа.")

            # --- зависшая задача: сами докапываемся -------------------------------
            for tid, stt in list(s.get("stall", {}).items()):
                if stt.get("flag"):
                    continue
                idle = time_now() - float(stt.get("progress_ts") or 0)
                if idle < stall_min * 60:
                    continue
                _c, tres = http("GET", sys_url + f"/api/task_state.php?task_id={tid}", None, key)
                if (tres or {}).get("status") == "done":
                    s["stall"].pop(tid, None)
                    continue
                body_txt = s.get("stall_body", {}).get(tid, "")
                http("POST", sys_url + "/api/event.php", {
                    "project_ref": s.get("task_ref") or project_ref,
                    "kind": "system", "level": "warn", "title": "task-stalled",
                    "text": f"Задача {tid} не закрыта: агент отчитался и остановился, "
                            f"по ней нет работы {int(idle / 60)} мин. Отчёт: {body_txt[:500]}",
                    "server": cfg.get("agent_code"),
                    "meta": {"task_id": int(tid), "idle_sec": int(idle)}}, key)
                stt["flag"] = True
                save_state(STATE_PATH, st)
                print(f"TASK-STALLED {tid} ({int(idle / 60)} мин)", flush=True)

                # причина неочевидна -> сами собираем факты диагностикой
                if facts_task_id:
                    # Скрипты агенту не шлём (оператор: агент сам пишет и диагностирует).
                    # Просим его снять факты своими средствами.
                    msg = (f"@win1956312899_bot Задача {facts_task_id} — разбор зависшей задачи {tid}. "
                           f"Собери факты сам, своими средствами (свои скрипты/команды на сервере): "
                           f"нагрузка CPU и память, сколько профилей и процессов открыто и кто их поднял, "
                           f"состояние парсеров и keeper, растёт ли лента, что именно ест ресурсы. "
                           f"Ничего не чини в этой задаче — только факты и вывод: в чём причина и "
                           f"что делать по шагам.\n\n"
                           f"Отчёт в конце: TASK|{facts_task_id}|REPORT")
                    http("POST", sys_url + "/api/queue_next.php", {
                        "project_ref": project_ref, "agent_code": cfg.get("agent_code"),
                        "agent_bot": cfg.get("agent_bot", ""),
                        "task_id": facts_task_id, "payload": msg}, key)
                    print("facts task queued", flush=True)
                    notify_operator(f"⏸ {project_ref}: задача {tid} зависла (агент отчитался и встал). "
                                    f"Ставлю агенту задачу по сбору ситуации — собираю факты по гипотезам.")

            # --- очередь -> чат --------------------------------------------------
            if cfg.get("send_enabled", False):
                _c, res = http("GET", sys_url + f"/api/pending.php?chat_id={cid}", None, key)
                for item in (res.get("items") or []) if isinstance(res, dict) else []:
                    text = str(item.get("payload") or "").strip()
                    if not text:
                        continue
                    try:
                        await client.send_message(cid, text)
                        http("POST", sys_url + f"/api/sent.php?id={int(item['id'])}", {"error": ""}, key)
                        print(f"tracker->tg dispatch={item['id']}", flush=True)
                    except Exception as e:
                        print("send err:", type(e).__name__, e, file=sys.stderr)
                        http("POST", sys_url + f"/api/sent.php?id={int(item['id'])}",
                             {"error": f"{type(e).__name__}: {e}"}, key)
        except Exception as e:
            print("loop err:", type(e).__name__, e, file=sys.stderr, flush=True)
            http("POST", sys_url + "/api/event.php", {
                "project_ref": project_ref, "kind": "system", "level": "error",
                "title": "bridge-error", "text": f"{type(e).__name__}: {e}",
                "server": cfg.get("agent_code")}, key)
        await asyncio.sleep(int(cfg.get("poll_sec", 20)))


def urllib_quote(s: str) -> str:
    import urllib.parse
    return urllib.parse.quote(s)


if __name__ == "__main__":
    try:
        sys.exit(asyncio.run(main()))
    except KeyboardInterrupt:
        sys.exit(0)
