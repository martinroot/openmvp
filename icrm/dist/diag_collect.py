#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Сбор ситуации по конвейеру Dolphin/Chrome/лента. Только чтение, ничего не чинит.

Запуск:  python diag_collect.py
Вывод:  компактный отчёт (в stdout) — его нужно отдать целиком.

Проверяет (гипотезы):
  Г1 Память: 9 профилей Dolphin (каждый — Chromium) + много вкладок → OOM.
  Г2 Chrome: версия браузера vs версия playwright-драйвера (connectOverCDP).
  Г3 Chrome-процессы: сколько штук, суммарный RSS, число вкладок через CDP.
  Г4 Порты: 9222 (Chrome CDP), 3001 (Dolphin API), 8080 (nginx), 8788 (sandbox), 8701 (JobLock).
  Г5 Парсеры и keeper: живы ли процессы парсеров, есть ли keeper-респавн.
  Г6 Лента: растёт ли feed-log.jsonl, когда была последняя запись.
  Г7 Диск: свободное место (профили и логи могут съесть место).
"""
import json
import os
import platform
import socket
import subprocess
import sys
import time
import urllib.request

PORTS = [9222, 3001, 8080, 8788, 8701]
FEED_CANDIDATES = [
    r"C:\Users\Administrator\browser-automation\logs\feed-log.jsonl",
    r"C:\Users\Administrator\browser-automation\feed-log.jsonl",
    r"C:\Users\Administrator\feed-log.jsonl",
    "feed-log.jsonl",
]
DIR_CANDIDATES = [
    r"C:\Users\Administrator\browser-automation",
    r"C:\Users\Administrator",
    ".",
]

out = []


def sec(t):
    out.append("")
    out.append("=== " + t)


def run(cmd, timeout=25):
    try:
        p = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=timeout)
        return (p.stdout or "").strip()
    except Exception as e:
        return f"(ошибка: {type(e).__name__}: {e})"


def http_get(url, timeout=6):
    try:
        with urllib.request.urlopen(url, timeout=timeout) as r:
            return r.read().decode("utf-8", "replace")
    except Exception as e:
        return None


# --- Г0 CPU и счётчики процессов ------------------------------------------
sec("Г0 CPU И ПРОЦЕССЫ")
if platform.system() == "Windows":
    out.append("Загрузка CPU (среднее за 1/5/15 мин, %): " + run(
        'powershell -NoProfile -Command '
        '"$c=(Get-Counter \\"\\Processor(_Total)\\% Processor Time\\" -SampleInterval 1 -MaxSamples 3); '
        '[math]::Round(($c.CounterSamples | Measure-Object CookedValue -Average).Average,1)"'))
    out.append("Процессов по имени: " + run(
        'powershell -NoProfile -Command '
        '"Get-Process | Group-Object Name | Sort-Object Count -Descending | Select-Object -First 12 '
        'Count,Name | Format-Table -AutoSize | Out-String -Width 80"'))
    out.append("Топ-10 по CPU-времени:")
    out.append(run('powershell -NoProfile -Command '
                   '"Get-Process | Sort-Object CPU -Descending | Select-Object -First 10 '
                   'Name,Id,@{N=\'CPUc\';E={[math]::Round($_.CPU,0)}},'
                   '@{N=\'MB\';E={[math]::Round($_.WorkingSet64/1MB)}} '
                   '| Format-Table -AutoSize | Out-String -Width 120"'))
else:
    out.append("load average: " + run("cat /proc/loadavg"))
    out.append("Процессов по имени: " + run("ps -eo comm | sort | uniq -c | sort -rn | head -12"))
    out.append("Топ-12 по CPU: " + run("ps -eo pid,pcpu,pmem,etime,comm --sort=-pcpu | head -13"))
sec("Г1 ПАМЯТЬ")
if platform.system() == "Windows":
    out.append(run('powershell -NoProfile -Command '
                   '"$os=Get-CimInstance Win32_OperatingSystem; '
                   '\'Всего МБ: {0:N0} | Свободно МБ: {1:N0} | Использовано: {2:N0} МБ ({3:N1}%)\' -f '
                   '($os.TotalVisibleMemorySize/1KB),($os.FreePhysicalMemory/1KB),'
                   '($os.TotalVisibleMemorySize-$os.FreePhysicalMemory)/1KB,'
                   '(($os.TotalVisibleMemorySize-$os.FreePhysicalMemory)/$os.TotalVisibleMemorySize*100)"'))
    out.append("Swap/файл подкачки: " + run('powershell -NoProfile -Command '
                                            '"Get-CimInstance Win32_PageFileUsage | '
                                            'Select-Object Name,AllocatedBaseSize,CurrentUsage | Format-List | Out-String"'))
else:
    out.append(run("free -m 2>/dev/null || cat /proc/meminfo | head -4"))
    out.append("swap: " + run("swapon --show 2>/dev/null || echo нет"))

# --- Г2/Г3 Chrome ----------------------------------------------------------
sec("Г2/Г3 CHROME")
ver = http_get("http://127.0.0.1:9222/json/version")
if ver:
    try:
        v = json.loads(ver)
        out.append(f"CDP :9222 отвечает. Браузер: {v.get('Browser')}  protocol: {v.get('Protocol-Version')}")
    except Exception:
        out.append("CDP :9222 отвечает: " + ver[:200])
else:
    out.append("CDP :9222 НЕ отвечает — Chrome/CDP не слушает или упал")
tabs = http_get("http://127.0.0.1:9222/json/list")
if tabs:
    try:
        tl = json.loads(tabs)
        out.append(f"Вкладок/целей через CDP: {len(tl)}")
        urls = [t.get('url', '') for t in tl][:40]
        dom = {}
        for u in urls:
            d = u.split('/')[2] if '://' in u else '?'
            dom[d] = dom.get(d, 0) + 1
        out.append("По доменам: " + ", ".join(f"{k}={v}" for k, v in sorted(dom.items(), key=lambda x: -x[1])[:15]))
    except Exception as e:
        out.append(f"Вкладки прочитать не удалось: {e}")
else:
    out.append("Список вкладок недоступен")
if platform.system() == "Windows":
    out.append("Процессов chrome: " + run('powershell -NoProfile -Command '
                                           '"(Get-Process chrome -ErrorAction SilentlyContinue).Count"'))
    out.append("Суммарный RSS chrome (МБ): " + run(
        'powershell -NoProfile -Command '
        '"$p=Get-Process chrome -ErrorAction SilentlyContinue; '
        'if($p){[math]::Round((($p | Measure-Object WorkingSet64 -Sum).Sum)/1MB)}else{0}"'))
    out.append("Топ-10 процессов по памяти:")
    out.append(run('powershell -NoProfile -Command '
                   '"Get-Process | Sort-Object WorkingSet64 -Descending | Select-Object -First 10 '
                   'Name,Id,@{N=\'MB\';E={[math]::Round($_.WorkingSet64/1MB)}} | Format-Table -AutoSize | Out-String -Width 120"'))
else:
    out.append("Процессов chrome: " + run("pgrep -c chrome || echo 0"))
    out.append("Топ-10 по RSS:")
    out.append(run("ps -eo pid,rss,comm --sort=-rss | head -11"))

# --- Г4 порты --------------------------------------------------------------
sec("Г4 ПОРТЫ")
for p in PORTS:
    s = socket.socket()
    s.settimeout(2.5)
    try:
        s.connect(("127.0.0.1", p))
        out.append(f"  :{p} — слушает")
    except Exception as e:
        out.append(f"  :{p} — НЕ отвечает ({type(e).__name__})")
    finally:
        s.close()

# --- Г5 парсеры/keeper -----------------------------------------------------
sec("Г5 ПАРСЕРЫ / KEEPER")
if platform.system() == "Windows":
    out.append(run('powershell -NoProfile -Command '
                   '"Get-CimInstance Win32_Process | Where-Object { $_.Name -match \'node|bun|python|pwsh\' } '
                   '| Select-Object ProcessId,Name,@{N=\'Cmd\';E={$_.CommandLine.Substring(0,[Math]::Min(150,$_.CommandLine.Length))}} '
                   '| Format-Table -AutoSize | Out-String -Width 200"'))
else:
    out.append(run("ps -eo pid,etime,rss,args | grep -Ei 'parser|keeper|bun|node' | grep -v grep | head -30"))

# --- Г6 лента --------------------------------------------------------------
sec("Г6 ЛЕНТА (feed-log.jsonl)")
for d in DIR_CANDIDATES:
    if not os.path.isdir(d):
        continue
    for root, _dirs, files in os.walk(d):
        depth = root[len(d):].count(os.sep)
        if depth > 3:
            _dirs[:] = []
            continue
        for f in files:
            if f == "feed-log.jsonl" or f.endswith(".jsonl") and "feed" in f:
                p = os.path.join(root, f)
                try:
                    st = os.stat(p)
                    age = int(time.time() - st.st_mtime)
                    out.append(f"  {p}  размер={st.st_size} Б  изменён {age} сек назад")
                    with open(p, "rb") as fh:
                        fh.seek(max(0, st.st_size - 1200))
                        tail = fh.read().decode("utf-8", "replace").strip().splitlines()[-3:]
                    for t in tail:
                        out.append("    | " + t[:220])
                except Exception as e:
                    out.append(f"  {p} — не прочитать: {e}")
        break  # только верхний уровень + один проход вглубь

# --- Г7 диск ---------------------------------------------------------------
sec("Г7 ДИСК")
if platform.system() == "Windows":
    out.append(run('powershell -NoProfile -Command '
                   '"Get-CimInstance Win32_LogicalDisk -Filter \'DeviceID=\\\'C:\\\'\' | '
                   'Select-Object @{N=\'Свободно ГБ\';E={[math]::Round($_.FreeSpace/1GB,1)}},'
                   '@{N=\'Всего ГБ\';E={[math]::Round($_.Size/1GB,1)}} | Format-List | Out-String"'))
else:
    out.append(run("df -h / 2>/dev/null | tail -2"))

sec("ИТОГ")
out.append(f"собрано {time.strftime('%Y-%m-%d %H:%M:%S')} на {platform.system()} {platform.release()}")

print("\n".join(out))
