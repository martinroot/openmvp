<?php
declare(strict_types=1);
/**
 * Каталог моделей OpenRouter, тесты и оценки.
 *
 * Модели синкаются из api/v1/models, тесты гоняются вручную кнопкой,
 * оценка (1..5) ставится оператором, приоритет = средняя оценка, затем цена.
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/or.php';

/** Значение настройки трекера. */
function models_setting(string $k, string $def = ''): string
{
    try {
        $r = one("SELECT v FROM settings WHERE k=?", 's', [$k]);
    } catch (Throwable $e) {
        return $def;
    }
    $v = trim((string)($r['v'] ?? ''));
    return $v !== '' ? $v : $def;
}

/** Записать настройку трекера. */
function models_setting_set(string $k, string $v): void
{
    q("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", 'ss', [$k, $v]);
}

/** Промт по умолчанию для теста: та же задача, что и у MLL-советника. */
function models_prompt_default(): string
{
    $v = models_setting('model_test_prompt');
    if ($v !== '') {
        return $v;
    }
    return <<<PROMPT
Ты советник по плану задач. Работаем по одному направлению: ADULT (лента → заявки → ответы).

Дай РОВНО 5 шагов по направлению. Правила:
1. Заголовок шага — максимально короткий: до 60 знаков, по-русски, с глагола, без воды.
2. Шаг — конкретное действие, которое агент может выполнить и подтвердить фактами.
3. Не повторяй то, что уже есть в плане, и не дублируй шаги между собой.
4. Учитывай контекст журнала: если там проблема — предложи шаг ровно на неё.
5. Порядок — по важности: сначала деньги, потом обслуживание.

Ответь ТОЛЬКО JSON без пояснений:
{"steps":[{"title":"КОРОТКИЙ ЗАГОЛОВОК"}]}
PROMPT;
}

/** Одна модель из каталога. */
function model_get(string $slug): ?array
{
    $r = one("SELECT * FROM models WHERE slug=?", 's', [$slug]);
    return $r ?: null;
}

/** Синхронизация каталога OpenRouter в таблицу models. */
function models_sync(): array
{
    $r = or_models_raw();
    if (!$r['ok']) {
        return ['ok' => false, 'count' => 0, 'error' => $r['error']];
    }
    $list = $r['json']['data'] ?? [];
    if (!is_array($list) || !$list) {
        return ['ok' => false, 'count' => 0, 'error' => 'пустой каталог'];
    }
    $now = date('Y-m-d H:i:s');
    $n   = 0;
    foreach ($list as $m) {
        $slug = trim((string)($m['id'] ?? ''));
        if ($slug === '') {
            continue;
        }
        $p     = $m['pricing'] ?? [];
        $pIn   = (float)($p['prompt'] ?? 0);
        $pOut  = (float)($p['completion'] ?? 0);
        // Роутеры OpenRouter имеют цену -1 (кредит $1 за запрос) — это не «free».
        $free  = ($pIn == 0.0 && $pOut == 0.0) ? 1 : 0;
        $arch  = (string)($m['architecture']['modality'] ?? '');
        $ctx   = (int)($m['context_length'] ?? 0);
        $name  = trim((string)($m['name'] ?? $slug));
        $creat = (int)($m['created'] ?? 0);
        $pCacheR = (float)($p['input_cache_read'] ?? 0);
        $pCacheW = (float)($p['input_cache_write'] ?? 0);
        q("INSERT INTO models (slug,name,ctx,p_in,p_out,is_free,modality,created,p_cache_read,p_cache_write,fetched_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE name=VALUES(name), ctx=VALUES(ctx), p_in=VALUES(p_in),
             p_out=VALUES(p_out), is_free=VALUES(is_free), modality=VALUES(modality),
             created=VALUES(created), p_cache_read=VALUES(p_cache_read),
             p_cache_write=VALUES(p_cache_write), fetched_at=VALUES(fetched_at)",
          'ssiddisidds',
          [$slug, $name, $ctx, $pIn, $pOut, $free, $arch, $creat, $pCacheR, $pCacheW, $now]);
        $n++;
    }
    return ['ok' => true, 'count' => $n, 'error' => ''];
}



/**
 * Объём моделей в OpenRouter за последнюю неделю + индекс качества.
 *
 * Недельный объём: GET https://openrouter.ai/api/frontend/v1/rankings/models?view=week
 *   элементы: model_permaslug, variant (standard|free|batch),
 *   total_prompt_tokens + total_completion_tokens, count (запросы).
 *   Слаг каталога: пермаслаг без даты + суффикс варианта (:free / :batch).
 * Качество: Intelligence Index со страницы openrouter.ai/rankings (массив "intelligence").
 */
function models_sync_rankings(): array
{
    $ctx  = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true,
                                              'header' => "User-Agent: Mozilla/5.0\r\n"]]);
    $raw  = @file_get_contents('https://openrouter.ai/api/frontend/v1/rankings/models?view=week', false, $ctx);
    $rows = [];
    if ($raw) {
        $json = json_decode($raw, true);
        $rows = is_array($json['data'] ?? null) ? $json['data'] : [];
    }
    $n = 0;
    if ($rows) {
        $vol = [];
        foreach ($rows as $r) {
            $base = preg_replace('/-\\d{8}$/', '', trim((string)($r['model_permaslug'] ?? '')));
            if ($base === '') {
                continue;
            }
            $variant = (string)($r['variant'] ?? 'standard');
            $slug = $variant === 'free' ? $base . ':free'
                  : ($variant === 'batch' ? $base . ':batch' : $base);
            $tok = (float)($r['total_prompt_tokens'] ?? 0) + (float)($r['total_completion_tokens'] ?? 0);
            $req = (float)($r['count'] ?? 0);
            if (!isset($vol[$slug])) {
                $vol[$slug] = ['tok' => 0.0, 'req' => 0.0];
            }
            $vol[$slug]['tok'] += $tok;
            $vol[$slug]['req'] += $req;
        }
        uasort($vol, static fn($a, $b) => $b['tok'] <=> $a['tok']);
        $rank = 0;
        foreach ($vol as $slug => $v) {
            $rank++;
            q("UPDATE models SET pop_score=?, pop_rank=?, pop_requests=? WHERE slug=?",
              'diis', [$v['tok'], $rank, (int)$v['req'], $slug]);
            $n++;
        }
    }

    // индекс качества (Intelligence Index) — со страницы рейтинга
    $html = @file_get_contents('https://openrouter.ai/rankings', false, $ctx);
    if ($html) {
        $needle = '\\"intelligence\\":[';
        $pos    = strpos($html, $needle);
        if ($pos === false) {
            $needle = '\\"models\\":[';
            $pos    = strpos($html, $needle);
        }
        if ($pos !== false) {
            $start = $pos + strlen($needle) - 1;
            $depth = 0;
            $end   = null;
            for ($i = $start, $len = strlen($html); $i < $len; $i++) {
                $ch = $html[$i];
                if ($ch === '[' || $ch === '{') {
                    $depth++;
                } elseif ($ch === ']' || $ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            if ($end !== null) {
                $json = json_decode(stripcslashes(substr($html, $start, $end - $start + 1)), true);
                if (is_array($json)) {
                    foreach ($json as $m) {
                        $slug = trim((string)($m['openrouter_slug'] ?? ''));
                        if ($slug === '') {
                            $slug = trim((string)($m['heuristic_openrouter_slug'] ?? ''));
                        }
                        if ($slug === '') {
                            $slug = preg_replace('/-\\d{8}$/', '', trim((string)($m['permaslug'] ?? '')));
                        }
                        if ($slug === '') {
                            continue;
                        }
                        $score = (float)($m['score'] ?? 0);
                        q("UPDATE models SET pop_quality=? WHERE slug=? OR slug=?",
                          'dss', [$score, $slug, $slug . ':free']);
                    }
                }
            }
        }
    }
    return ['ok' => $n > 0, 'count' => $n, 'error' => $n > 0 ? '' : 'не удалось получить недельный рейтинг'];
}



/**
 * Цены по провайдерам одной модели: OpenRouter отдаёт их в
 * GET /api/v1/models/{slug}/endpoints. У одной модели десятки провайдеров
 * с разными ценами — фактическая цена зависит от того, кто ответил.
 */
function models_sync_endpoints(array $slugs): array
{
    $n = 0;
    $err = '';
    foreach ($slugs as $slug) {
        $slug = trim((string)$slug);
        if ($slug === '') {
            continue;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 25, 'ignore_errors' => true,
                                                 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
        // слэш в пути кодировать нельзя — API ждёт /models/{author}/{name}/endpoints
        $path = str_replace('%2F', '/', rawurlencode($slug));
        $url  = 'https://openrouter.ai/api/v1/models/' . $path . '/endpoints';
        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) {
            $err = 'не удалось получить провайдеров: ' . $slug;
            continue;
        }
        $json = json_decode($raw, true);
        $eps  = $json['data']['endpoints'] ?? [];
        if (!$eps) {
            continue;
        }
        $ins = [];
        $outs = [];
        foreach ($eps as $e) {
            $p = $e['pricing'] ?? [];
            $i = (float)($p['prompt'] ?? 0);
            $o = (float)($p['completion'] ?? 0);
            if ($i > 0) {
                $ins[] = $i;
            }
            if ($o > 0) {
                $outs[] = $o;
            }
        }
        if (!$outs) {
            continue;
        }
        sort($outs);
        $minOut = $outs[0];
        $maxOut = $outs[count($outs) - 1];
        $avgOut = array_sum($outs) / count($outs);
        $minIn  = $ins ? min($ins) : 0.0;
        // самый дешёвый провайдер — его слаг можно вписать в provider.order
        $best = '';
        $bestOut = null;
        foreach ($eps as $e) {
            $o = (float)($e['pricing']['completion'] ?? 0);
            if ($o > 0 && ($bestOut === null || $o < $bestOut)) {
                $bestOut = $o;
                $best = strtolower(trim((string)($e['provider_name'] ?? '')));
            }
        }
        q("UPDATE models SET p_min_in=?, p_min_out=?, p_max_out=?, p_avg_out=?, providers=?, p_best_provider=? WHERE slug=?",
          'ddddiss', [$minIn, $minOut, $maxOut, $avgOut, count($eps), $best, $slug]);
        $n++;
    }
    return ['ok' => $n > 0, 'count' => $n, 'error' => $err];
}

/**
 * ТОП моделей по объёму в OpenRouter — с ценой, качеством и контекстом.
 * Фильтры: free — только бесплатные; maxout — ограничение цены выхода за 1M.
 */
function models_top(int $limit = 50, bool $onlyFree = false, float $maxOut = 0.0): array
{
    $where = ['pop_rank > 0'];
    if ($onlyFree) {
        $where[] = 'is_free=1';
    }
    if ($maxOut > 0) {
        // бесплатные (p_out = 0) пропускаем всегда, роутеры (-1) отсекаем
        $where[] = '(p_out = 0 OR (p_out > 0 AND p_out <= ' . (float)$maxOut . '))';
    }
    return all("SELECT m.*,
                       (SELECT COUNT(*) FROM model_tests t WHERE t.slug=m.slug) tests,
                       (SELECT AVG(t.score) FROM model_tests t WHERE t.slug=m.slug AND t.score IS NOT NULL) avg_score,
                       (SELECT t.note FROM model_tests t WHERE t.slug=m.slug AND t.kind='code' ORDER BY t.id DESC LIMIT 1) code_note,
                       (SELECT t.cost_usd FROM model_tests t WHERE t.slug=m.slug AND t.kind='code' ORDER BY t.id DESC LIMIT 1) code_cost,
                       (SELECT t.latency_ms FROM model_tests t WHERE t.slug=m.slug AND t.kind='code' ORDER BY t.id DESC LIMIT 1) code_ms,
                       (SELECT COUNT(DISTINCT c.task_id) FROM llm_calls c WHERE c.model=m.slug) real_tasks,
                       (SELECT SUM(c.cost_usd)/NULLIF(COUNT(DISTINCT c.task_id),0) FROM llm_calls c WHERE c.model=m.slug) per_task
                FROM models m
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.pop_rank ASC LIMIT " . (int)$limit) ?: [];
}

/**
 * Предложение модели для направления: дешевле всего та, что прошла кодинг-бенчмарк.
 * Если бенчмарка нет — самая дешёвая с лучшей оценкой, затем самая дешёвая бесплатная.
 */
function models_recommend(int $pid, int $limit = 3): array
{
    $rows = all("SELECT m.slug, m.name, m.p_in, m.p_out, m.is_free, m.ctx, m.pop_rank,
                        (SELECT t.note FROM model_tests t WHERE t.slug=m.slug AND t.kind='code' ORDER BY t.id DESC LIMIT 1) code_note,
                        (SELECT t.cost_usd FROM model_tests t WHERE t.slug=m.slug AND t.kind='code' ORDER BY t.id DESC LIMIT 1) code_cost,
                        (SELECT AVG(t.score) FROM model_tests t WHERE t.slug=m.slug AND t.score IS NOT NULL) avg_score,
                        (SELECT SUM(c.cost_usd)/NULLIF(COUNT(DISTINCT c.task_id),0) FROM llm_calls c WHERE c.model=m.slug) per_task
                 FROM models m
                 WHERE m.p_out >= 0
                 ORDER BY (code_note LIKE '%валиден%') DESC, code_cost ASC, m.p_out ASC, m.pop_rank ASC");
    $ok = [];
    foreach ($rows as $r) {
        if (strpos((string)($r['code_note'] ?? ''), 'валиден') !== false) {
            $ok[] = $r;
        }
        if (count($ok) >= $limit) {
            break;
        }
    }
    // добираем моделями из топа по объёму — чтобы было из чего ротировать
    $seen = [];
    foreach ($ok as $r) {
        $seen[(string)$r['slug']] = true;
    }
    foreach ($rows as $r) {
        if (count($ok) >= $limit) {
            break;
        }
        $slug = (string)$r['slug'];
        if (isset($seen[$slug])) {
            continue;
        }
        $seen[$slug] = true;
        $ok[] = $r;
    }
    return $ok;
}

/** Ротация: следующая по кругу модель из рекомендаций (текущая исключается). */
function models_rotate(int $pid): array
{
    $cur = project_model($pid);
    $rec = models_recommend($pid, 8);
    $next = null;
    foreach ($rec as $r) {
        if ((string)$r['slug'] !== (string)$cur) {
            $next = $r;
            break;
        }
    }
    if (!$next) {
        return ['ok' => false, 'error' => 'нет модели для ротации'];
    }
    project_model_set($pid, (string)$next['slug']);
    return ['ok' => true, 'slug' => (string)$next['slug'], 'name' => (string)$next['name']];
}

/**
 * Список моделей с оценками и последними тестами.
 * @param string $filter all|free|cheap|tested
 */
function models_list(string $filter = 'all', string $sort = 'score', float $maxOut = 2.0): array
{
    $where = [];
    $args  = [];
    $types = '';
    if ($filter === 'free') {
        $where[] = 'm.is_free=1';
    } elseif ($filter === 'cheap') {
        $where[] = 'm.p_out>0 AND m.p_out<=?';
        $args[]  = $maxOut / 1e6;
        $types  .= 'd';
    }
    $sql = "SELECT m.slug, m.name, m.ctx, m.p_in, m.p_out, m.is_free, m.modality, m.fetched_at,
                   COUNT(t.id) tests,
                   AVG(t.score) avg_score,
                   SUM(CASE WHEN t.ok=1 THEN 1 ELSE 0 END) ok_tests,
                   MAX(t.created_at) last_test,
                   MAX(t.latency_ms) last_latency,
                   MAX(t.cost_usd) last_cost
            FROM models m
            LEFT JOIN model_tests t ON t.slug=m.slug";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY m.slug, m.name, m.ctx, m.p_in, m.p_out, m.is_free, m.modality, m.fetched_at';
    if ($filter === 'tested') {
        $sql .= ' HAVING COUNT(t.id) > 0';
    }

    // MariaDB не даёт ссылаться на агрегатные алиасы — пишем выражения явно.
    $order = match ($sort) {
        'price'  => 'GREATEST(m.p_out, 0) ASC, GREATEST(m.p_in, 0) ASC',
        'ctx'    => 'm.ctx DESC',
        'name'   => 'm.name ASC',
        'tests'  => 'COUNT(t.id) DESC, m.name ASC',
        default  => '(AVG(t.score) IS NULL) ASC, AVG(t.score) DESC, m.p_out ASC',
    };
    $sql .= ' ORDER BY ' . $order;
    $rows = $args ? all($sql, $types, $args) : all($sql);
    return $rows ?: [];
}

/** Последний тест модели. */
function model_last_test(string $slug): ?array
{
    $r = one("SELECT * FROM model_tests WHERE slug=? ORDER BY id DESC LIMIT 1", 's', [$slug]);
    return $r ?: null;
}

/** Прогнать тест модели и записать результат. */
function model_run_test(string $slug, ?string $prompt = null): array
{
    $slug = trim($slug);
    $m    = model_get($slug);
    if (!$m) {
        return ['ok' => false, 'error' => 'модель не найдена в каталоге: ' . $slug, 'id' => 0];
    }
    $prompt = trim((string)($prompt ?? ''));
    if ($prompt === '') {
        $prompt = models_prompt_default();
    }
    $res  = or_chat($slug, $prompt);
    $text = (string)$res['text'];
    if (mb_strlen($text) > 20000) {
        $text = mb_substr($text, 0, 20000) . '…';
    }
    q("INSERT INTO model_tests (slug,prompt,ok,latency_ms,tok_in,tok_out,cost_usd,response,error,created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?)",
      'ssiiiidsss',
      [$slug, $prompt, $res['ok'] ? 1 : 0, (int)$res['latency'], (int)$res['in'], (int)$res['out'],
       (float)$res['cost'], $text, (string)$res['error'], date('Y-m-d H:i:s')]);
    $id = (int)db()->insert_id;
    return ['ok' => (bool)$res['ok'], 'error' => (string)$res['error'], 'id' => $id,
            'latency' => (int)$res['latency'], 'in' => (int)$res['in'], 'out' => (int)$res['out'],
            'cost' => (float)$res['cost'], 'text' => $text];
}

/** Поставить оценку тесту (1..5) и заметку. */
function model_set_score(int $id, int $score, string $note = ''): bool
{
    if ($score < 1 || $score > 5) {
        return false;
    }
    q("UPDATE model_tests SET score=?, note=? WHERE id=?", 'isi', [$score, mb_substr($note, 0, 250), $id]);
    return true;
}

/** Журнал последних тестов. */
function model_tests_list(int $limit = 40): array
{
    return all("SELECT t.id, t.slug, t.kind, t.ok, t.latency_ms, t.tok_in, t.tok_out, t.cost_usd,
                       t.score, t.note, t.created_at, t.error, m.name
                FROM model_tests t LEFT JOIN models m ON m.slug=t.slug
                ORDER BY t.id DESC LIMIT " . (int)$limit) ?: [];
}

/** Модель, назначенная направлению. */
function project_model(int $pid): ?string
{
    $r = one("SELECT slug FROM project_models WHERE project_id=?", 'i', [$pid]);
    return $r ? (string)$r['slug'] : null;
}

/** Назначить модель направлению (пусто — снять). */
function project_model_set(int $pid, ?string $slug): void
{
    $slug = trim((string)$slug);
    if ($slug === '') {
        q("DELETE FROM project_models WHERE project_id=?", 'i', [$pid]);
        return;
    }
    q("INSERT INTO project_models (project_id,slug,updated_at) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE slug=VALUES(slug), updated_at=VALUES(updated_at)",
      'iss', [$pid, $slug, date('Y-m-d H:i:s')]);
}

/* ------------------------------------------------------------------
 * КОДИНГ-БЕНЧМАРК: одна и та же задача для всех моделей + объективная
 * проверка ответа (синтаксис python) + цена именно этой задачи.
 * ------------------------------------------------------------------ */

/** Промт кодинг-бенчмарка. Одинаковый для всех моделей — иначе сравнение бессмысленно. */
function models_code_prompt(): string
{
    $p = trim(models_setting('code_prompt', ''));
    if ($p !== '') {
        return $p;
    }
    return "Ты Python-разработчик. Напиши функцию `parse_reviews(raw)`, которая:\n"
         . "1) принимает строку `raw` — сырой лог выгрузки, где отзывы идут блоками вида\n"
         . "   [2026-10-24 12:03] Отзыв: текст отзыва | оценка: 5\n"
         . "   и между блоками бывают пустые строки и мусорные заголовки вида '--- log ---';\n"
         . "2) возвращает список словарей: {'date': '2026-10-24 12:03', 'text': '...', 'score': int};\n"
         . "3) пропускает блоки без оценки, чинит битый UTF-8 (replacement char) и не падает на мусоре.\n"
         . "Ответь ТОЛЬКО блоком кода ```python ... ``` без пояснений.";
}

/** Достать python-код из ответа. */
function models_code_extract(string $text): string
{
    if (preg_match('/```(?:python|py)?[ \t]*\r?\n(.*?)```/is', $text, $m)) {
        return trim($m[1]);
    }
    return trim($text);
}

/** Проверить синтаксис python-кода через ast.parse. */
function models_code_syntax(string $code): array
{
    $code = trim($code);
    if ($code === '') {
        return ['ok' => false, 'detail' => 'в ответе нет кода'];
    }
    $py = "import ast,sys\ntry:\n ast.parse(sys.stdin.read())\n print('PARSED')\nexcept SyntaxError as e:\n print('SYNTAX: '+str(e))";
    $cmd  = 'python3 -c ' . escapeshellarg($py);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($p)) {
        return ['ok' => false, 'detail' => 'проверка синтаксиса недоступна'];
    }
    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = trim((string)stream_get_contents($pipes[1]));
    $err = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    if ($out === 'PARSED') {
        return ['ok' => true, 'detail' => 'синтаксис в порядке'];
    }
    return ['ok' => false, 'detail' => $out !== '' ? $out : ('ошибка: ' . $err)];
}

/** Прогнать кодинг-бенчмарк: та же задача, проверка кода и цена. */
function model_run_code_test(string $slug, ?string $prompt = null): array
{
    $slug = trim($slug);
    if (!model_get($slug)) {
        return ['ok' => false, 'error' => 'модель не найдена: ' . $slug, 'id' => 0];
    }
    $prompt = trim((string)($prompt ?? ''));
    if ($prompt === '') {
        $prompt = models_code_prompt();
    }
    $res  = or_chat($slug, $prompt, 8000);   // рассуждающим моделям нужно больше
    $text = (string)$res['text'];
    $chk  = models_code_syntax(models_code_extract($text));
    $note = $chk['ok'] ? 'код валиден' : ('код невалиден: ' . mb_substr($chk['detail'], 0, 140));
    $note .= ' · think=' . (int)($res['think'] ?? 0) . ' · out=' . (int)($res['out'] ?? 0);
    if (mb_strlen($text) > 20000) {
        $text = mb_substr($text, 0, 20000) . '…';
    }
    q("INSERT INTO model_tests (slug,kind,prompt,ok,latency_ms,tok_in,tok_out,cost_usd,response,error,note,created_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      'sssiiiidssss',
      [$slug, 'code', $prompt, (($res['ok'] && $chk['ok']) ? 1 : 0), (int)$res['latency'],
       (int)$res['in'], (int)$res['out'], (float)$res['cost'], $text,
       (string)$res['error'], $note, date('Y-m-d H:i:s')]);
    $id = (int)db()->insert_id;
    return ['ok' => (bool)$res['ok'], 'code_ok' => $chk['ok'], 'detail' => $note,
            'error' => (string)$res['error'], 'id' => $id, 'latency' => (int)$res['latency'],
            'in' => (int)$res['in'], 'out' => (int)$res['out'], 'cost' => (float)$res['cost'],
            'text' => $text];
}

/** Экономика моделей по реальным вызовам: сколько стоит одна задача. */
function models_economics(int $limit = 60): array
{
    return all("SELECT c.model,
                       COUNT(*) calls,
                       COUNT(DISTINCT c.task_id) tasks,
                       COALESCE(SUM(c.cost_usd),0) cost,
                       COALESCE(SUM(c.tok_in),0) ti,
                       COALESCE(SUM(c.tok_cache),0) tc,
                       COALESCE(SUM(c.tok_out),0) tout,
                       COALESCE(AVG(c.duration_ms),0) dur,
                       SUM(c.cost_usd)/NULLIF(COUNT(DISTINCT c.task_id),0) per_task,
                       (SUM(c.tok_in+c.tok_cache))/NULLIF(COUNT(*),0) ctx,
                       MAX(m.is_free) is_free, MAX(m.name) name, MAX(m.p_out) p_out
                FROM llm_calls c LEFT JOIN models m ON m.slug=c.model
                GROUP BY c.model
                ORDER BY (SUM(c.cost_usd)/NULLIF(COUNT(DISTINCT c.task_id),0)) IS NULL,
                         SUM(c.cost_usd)/NULLIF(COUNT(DISTINCT c.task_id),0)
                LIMIT " . (int)$limit) ?: [];
}

/** Экономика проектов: доход − расход, задачи и назначенная модель. */
function models_project_economics(): array
{
    return all("SELECT p.id, p.title, p.icon, p.income_usd,
                       COALESCE(SUM(c.cost_usd),0) cost,
                       COUNT(c.id) calls,
                       COUNT(DISTINCT c.task_id) tasks,
                       COALESCE(SUM(c.tok_in+c.tok_cache),0) ctx,
                       pm.slug model
                FROM projects p
                LEFT JOIN llm_calls c ON c.project_id=p.id
                LEFT JOIN project_models pm ON pm.project_id=p.id
                WHERE p.kind='project'
                GROUP BY p.id, p.title, p.icon, p.income_usd, pm.slug
                ORDER BY cost DESC") ?: [];
}

/** Рейтинг моделей: оценка (по убыванию), затем цена. */
function models_ranking(int $limit = 200): array
{
    $rows = models_list('all', 'score', 0.0);
    $out  = [];
    foreach ($rows as $r) {
        if ((int)($r['tests'] ?? 0) > 0 || (float)($r['avg_score'] ?? 0) > 0) {
            $out[] = $r;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}