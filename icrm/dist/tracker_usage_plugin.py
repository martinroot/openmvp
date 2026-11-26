"""tracker-usage — учёт вызовов моделей в трекере SYSTEM.

Плагин Hermes для агента. После каждого API-вызова отправляет трекеру
модель, токены (in / cache read / cache write / out / reasoning), длительность
и стоимость. Трекер сам привязывает отчёт к направлению (по ID чата) и к
текущей задаче.

Установка на сервере агента:

    mkdir -p ~/.hermes/plugins/tracker-usage
    curl -s http://5.144.179.170:8080/api/usage_plugin.py   -o ~/.hermes/plugins/tracker-usage/__init__.py
    curl -s http://5.144.179.170:8080/api/usage_plugin.yaml -o ~/.hermes/plugins/tracker-usage/plugin.yaml

Конфигурация (~/.hermes/tracker_usage.json):

    {
      "tracker": "http://5.144.179.170:8080",
      "key": "<X-Ingest-Key трекера>",
      "chat_id": -1003919000688,
      "agent_code": "fab-94"
    }

То же через переменные окружения: HERMES_TRACKER_URL, HERMES_TRACKER_KEY,
HERMES_TRACKER_CHAT_ID, HERMES_TRACKER_AGENT_CODE.

Без конфигурации плагин молчит и ничего не шлёт.
"""

from __future__ import annotations

import json
import logging
import os
import queue
import threading
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Dict, List, Optional

logger = logging.getLogger(__name__)

_CONFIG_FILES = (
    Path.home() / ".hermes" / "tracker_usage.json",
    Path.home() / ".hermes" / "plugins" / "tracker-usage" / "config.json",
)
_LOG_FILE = Path.home() / ".hermes" / "tracker_usage.log"

_QUEUE: "queue.Queue[Dict[str, Any]]" = queue.Queue(maxsize=20000)
_LOCK = threading.Lock()
_CONFIG_CACHE: Dict[str, Any] = {"at": 0.0, "cfg": None}
_WORKER: Optional[threading.Thread] = None
_COST_WARNED = False


def _log(msg: str) -> None:
    try:
        with _LOG_FILE.open("a", encoding="utf-8") as fh:
            fh.write("%s %s\n" % (time.strftime("%Y-%m-%d %H:%M:%S"), msg))
    except Exception:
        pass


def _load_config() -> Dict[str, Any]:
    now = time.time()
    with _LOCK:
        cached = _CONFIG_CACHE.get("cfg")
        if cached is not None and now - float(_CONFIG_CACHE.get("at") or 0) < 30:
            return cached
    cfg: Dict[str, Any] = {}
    for path in _CONFIG_FILES:
        try:
            if path.is_file():
                data = json.loads(path.read_text(encoding="utf-8"))
                if isinstance(data, dict):
                    cfg.update(data)
                break
        except Exception as exc:
            _log("config read failed: %r" % (exc,))
    env = os.environ
    if env.get("HERMES_TRACKER_URL"):
        cfg["tracker"] = env["HERMES_TRACKER_URL"]
    if env.get("HERMES_TRACKER_KEY"):
        cfg["key"] = env["HERMES_TRACKER_KEY"]
    if env.get("HERMES_TRACKER_CHAT_ID"):
        try:
            cfg["chat_id"] = int(env["HERMES_TRACKER_CHAT_ID"])
        except Exception:
            pass
    if env.get("HERMES_TRACKER_AGENT_CODE"):
        cfg["agent_code"] = env["HERMES_TRACKER_AGENT_CODE"]
    with _LOCK:
        _CONFIG_CACHE["cfg"] = cfg
        _CONFIG_CACHE["at"] = now
    return cfg


def _cost_usd(model: str, usage: Dict[str, Any], provider: str, base_url: str) -> float:
    """Стоимость считает сам Hermes — тем же кодом, что и его футер."""
    try:
        from agent.usage_pricing import CanonicalUsage, estimate_usage_cost  # type: ignore

        cu = CanonicalUsage(
            input_tokens=int(usage.get("input_tokens") or 0),
            output_tokens=int(usage.get("output_tokens") or 0),
            cache_read_tokens=int(usage.get("cache_read_tokens") or 0),
            cache_write_tokens=int(usage.get("cache_write_tokens") or 0),
            reasoning_tokens=int(usage.get("reasoning_tokens") or 0),
        )
        result = estimate_usage_cost(model, cu, provider=provider or None, base_url=base_url or None)
        return float(getattr(result, "amount_usd", 0) or 0)
    except Exception as exc:
        global _COST_WARNED
        if not _COST_WARNED:
            _COST_WARNED = True
            _log("cost unavailable (%r) — стоимость будет 0" % (exc,))
        return 0.0


def _post(payload: Dict[str, Any]) -> None:
    cfg = _load_config()
    url = str(cfg.get("tracker") or "http://5.144.179.170:8080").rstrip("/") + "/api/ingest.php"
    key = str(cfg.get("key") or "")
    if not key:
        return
    req = urllib.request.Request(
        url,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json", "X-Ingest-Key": key},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            resp.read()
    except urllib.error.HTTPError as exc:
        _log("HTTP %s: %s" % (exc.code, (exc.read() or b"")[:200]))
    except Exception as exc:
        _log("post failed: %r" % (exc,))


def _worker() -> None:
    while True:
        try:
            first = _QUEUE.get(timeout=5)
        except queue.Empty:
            continue
        batch: List[Dict[str, Any]] = [first]
        while len(batch) < 100:
            try:
                batch.append(_QUEUE.get_nowait())
            except queue.Empty:
                break
        for payload in batch:
            try:
                _post(payload)
            except Exception as exc:
                _log("send failed: %r" % (exc,))


def _ensure_worker() -> None:
    global _WORKER
    with _LOCK:
        if _WORKER is not None and _WORKER.is_alive():
            return
        _WORKER = threading.Thread(target=_worker, name="tracker-usage", daemon=True)
        _WORKER.start()


def _on_post_api_request(**kwargs: Any) -> None:
    """Хук Hermes: вызывается после каждого API-вызова модели."""
    try:
        usage = kwargs.get("usage") or {}
        if not isinstance(usage, dict) or not usage:
            return
        cfg = _load_config()
        if not cfg.get("key") or not cfg.get("chat_id"):
            return
        model = str(kwargs.get("response_model") or kwargs.get("model") or "")
        provider = str(kwargs.get("provider") or "")
        base_url = str(kwargs.get("base_url") or "")
        try:
            duration = float(kwargs.get("api_duration") or 0.0)
        except Exception:
            duration = 0.0
        payload = {
            "type": "call",
            "chat_id": int(cfg["chat_id"]),
            "agent_code": str(cfg.get("agent_code") or "unknown"),
            "model": model,
            "turn_id": str(kwargs.get("turn_id") or ""),
            "call_no": 1,
            "tok_in": int(usage.get("input_tokens") or 0),
            "tok_cache": int(usage.get("cache_read_tokens") or 0),
            "tok_cache_write": int(usage.get("cache_write_tokens") or 0),
            "tok_out": int(usage.get("output_tokens") or 0),
            "tok_think": int(usage.get("reasoning_tokens") or 0),
            "cost_usd": _cost_usd(model, usage, provider, base_url),
            "duration_ms": int(duration * 1000),
            "ts": int(time.time()),
        }
        _QUEUE.put_nowait(payload)
        _ensure_worker()
    except Exception as exc:
        _log("hook failed: %r" % (exc,))


def _on_session_end(**kwargs: Any) -> None:
    """На конец сессии — дописать всё, что накопилось."""
    try:
        pending = []
        while True:
            try:
                pending.append(_QUEUE.get_nowait())
            except queue.Empty:
                break
        for payload in pending:
            _post(payload)
    except Exception:
        pass


def register(ctx) -> None:
    ctx.register_hook("post_api_request", _on_post_api_request)
    ctx.register_hook("on_session_end", _on_session_end)
    ctx.register_hook("on_session_finalize", _on_session_end)
