# SYSTEM — трекер задач maximhot

Простой трекер: проект (с каналом) → плоский список задач. У задачи два
статуса: **готово / не готово**. Одна строка на задачу, без второй строки.
Отметил задачу — следующая по порядку стала активной.

- **Папками (с детьми) могут быть только узлы с каналом** (`agent_chat_id`).
  Узел без канала — лист, его дети поднимаются на уровень выше.
- Лог агента из Telegram-чата пишется в карточку задачи автоматически
  (мост `tg-bridge.service`).
- Отправка задач агенту — только вручную, по кнопке в диспетчере.

## Доступ

```
http://5.144.179.170:8080/
логин:  admin
пароль: a10152ba62fd9ff0abbb607c
```

Basic-auth на уровне nginx. Ingest-API (`/api/*`) — без basic-auth,
своя авторизация по заголовку `X-Ingest-Key`.

## Что внутри

| Раздел | Что делает |
|---|---|
| **Дашборд** | Цели (день/месяц), проекты с чекбоксами задач, живой лог агента |
| **Список задач** | Всё дерево: проект → задачи. Одна строка, чекбокс, статус справа |
| **Карточка задачи** | Чекбокс, суть, лог агента, список задач по порядку, «следующая задача» |
| **Лента** | Все записи лога (из чата агента и вручную) |
| **Диспетчер** | Очередь задач агентам. Отправка — только вручную кнопкой |
| **Цели** | Глобальная и дневная цель, факт, прогресс |
| **Настройки** | Параметры, контракт ingest API |

## Ключевой сценарий

1. Открыл узел задачи → кнопка **«Закрыть задачу (приклеить лог из чата)»**.
2. Сервис тянет из агентского чата (`chat.php`) сообщения по этому агенту/задаче
   и приклеивает их в лог задачи (`events`).
3. Задача переводится в `done`, пишется `closed_at`.
4. Следующую задачу ставим вместе либо через интерфейс — автоматической
   оркестрации нет, как и требовалось.

## Модель данных

```
projects    дерево: parent_id, kind (project|subproject|task|subtask),
            icon, agent_code, agent_chat_id, agent_name, server_ip, model,
            status, priority, goal_amount, cost_total, tok_in/tok_cache/tok_out,
            runs_count, duration_ms, last_run_at, opened_at, closed_at
events      лог по узлу: kind (agent|chat|system|metric|file|note), level,
            title, text, server, chat_msg_id
agent_runs  метрики запусков: agent_code, model, tok_in/tok_cache/tok_out,
            cost_usd, duration_ms, job_id
goals       цели: scope (global|day|week|month), period_key, target_usd
settings    k/v
dispatch_queue  очередь задач агентам: agent_code, payload, status
```

**Источник истины по метрикам — `agent_runs`.** Поля `projects.cost_total` и
прочие — кэш для быстрого показа на конкретном узле; по поддереву агрегаты
считаются из `agent_runs`, поэтому задвоения нет.

## Мост Telegram-чат агента <-> трекер

`dist/tg_bridge.py` — systemd-юнит `tg-bridge.service` (автозапуск, лог в
`/var/log/tg_bridge.log`). Конфиг: `/root/system/dist/tg_bridge.json`
(владелец `main:root`, 600).

Что делает:
- читает новые сообщения из Telegram-чата агента и пишет их в лог задачи
  трекера (`POST /api/event.php`, `kind=agent` для сообщений бота агента);
- отправляет в чат задачи из очереди диспетчера (`GET /api/pending.php` →
  сообщение в чат → `POST /api/sent.php`).

**Отправка выключена по умолчанию** — флаг `"send_enabled": false` в секции чата.
Пока флаг выключен, мост только читает и ведёт лог, агент не получает от нас
ничего. Включать — когда оператор даст отвечать.

Автоответов нет по правилу: агенту пишем только когда он явно идёт не туда или
выполнил предыдущую задачу. На каждое его сообщение не отвечаем.

Сессия Telegram принадлежит мосту: любой другой процесс, открывающий тот же
`.session`, получит `database is locked`. Для разовых проверок — копия сессии
во временный файл (мост при этом остановить).

Добавить второй чат — дописать объект в `chats` конфига и перезапустить юнит.

## Установка сборщика метрик на сервер агента

```bash
cp /root/system/dist/collect_metrics.py /root/netagent/collect_metrics.py
chmod +x /root/netagent/collect_metrics.py

cat > /etc/cron.d/system-metrics <<EOF
*/5 * * * * main HERMES_HOME=/home/main/.hermes INGEST_KEY=<KEY> AGENT_CODE=<код> PROJECT_REF="<проект>" /usr/bin/python3 /root/netagent/collect_metrics.py >/dev/null 2>&1
EOF
chmod 644 /etc/cron.d/system-metrics
```

Ключи: `HERMES_HOME` — где лежит `state.db`, `INGEST_KEY` — из «Настройки»,
`AGENT_CODE` — код сервера (совпадает с кодом в трекере),
`PROJECT_REF` — название проекта в трекере (куда складывать метрики).

Источник данных — `state.db`, таблица `sessions`: `input_tokens`,
`output_tokens`, `cache_read_tokens`, `cache_write_tokens`,
`estimated_cost_usd`, `model`, `started_at`/`ended_at`.

## Ingest API

```bash
# метрика запуска
curl -X POST http://5.144.179.170:8080/api/ingest.php \
  -H 'X-Ingest-Key: <KEY>' -H 'Content-Type: application/json' \
  -d '{"agent_code":"<код-агента>","server":"<код-агента>","model":"<модель>",
       "project_ref":"<точное название узла в трекере>","tok_in":0,
       "tok_cache":0,"tok_out":0,"cost_usd":0,"duration_ms":0}'

# событие в лог задачи
curl -X POST http://5.144.179.170:8080/api/event.php \
  -H 'X-Ingest-Key: <KEY>' -H 'Content-Type: application/json' \
  -d '{"project_ref":"<точное название узла в трекере>","kind":"agent","level":"ok",
       "title":"...","text":"...","server":"<код-агента>"}'
```

`project_ref` — точное название узла в трекере; если узла нет (или пусто) — метрика
запишется без привязки и появится в общей статистике.

## Обслуживание

```bash
# логи
tail -f /var/log/nginx/system.error.log
tail -f /var/log/php8.3-fpm.log

# перезапуск после правки кода
php -l /root/system/index.php
nginx -t && systemctl reload nginx

# БД
mysql -u system -pCHANGE_ME system
```

Каталог: `/root/system` (public/ — веб-корни, lib/ — ядро, views/ — экраны,
api/ — ingest, bin/ — seed.php, dist/ — сборщик для агентов).
