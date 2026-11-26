<?php
declare(strict_types=1);
/**
 * Фоновый ретест моделей: берёт очередь из настроек, гоняет тесты, пишет результаты.
 * Запускается из раздела «Модели» кнопкой «Ретест».
 */
require __DIR__ . '/../lib/models.php';

$q     = json_decode(models_setting('model_retest_queue'), true) ?: [];
$slugs = $q['slugs'] ?? [];
if (!is_array($slugs) || !$slugs) {
    fwrite(STDERR, "очередь ретеста пуста\n");
    exit(0);
}
$total = count($slugs);
$log   = [];
$done  = 0;
$start = (string)($q['start'] ?? date('Y-m-d H:i:s'));

foreach ($slugs as $slug) {
    $slug = (string)$slug;
    $res  = model_run_test($slug);
    $done++;
    $line = $slug . ' — ' . ($res['ok'] ? 'ок' : 'ошибка') . ', ' . (int)$res['latency'] . ' мс, $'
          . number_format((float)$res['cost'], 6);
    if (!$res['ok']) {
        $line .= ' (' . mb_substr((string)$res['error'], 0, 120) . ')';
    }
    $log[] = $line;
    models_setting_set('model_retest_queue', json_encode([
        'slugs'  => $slugs,
        'status' => 'run',
        'done'   => $done,
        'total'  => $total,
        'log'    => $log,
        'start'  => $start,
    ], JSON_UNESCAPED_UNICODE));
    fwrite(STDOUT, $line . "\n");
}

models_setting_set('model_retest_queue', json_encode([
    'slugs'  => $slugs,
    'status' => 'done',
    'done'   => $done,
    'total'  => $total,
    'log'    => $log,
    'start'  => $start,
    'finish' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE));

fwrite(STDOUT, "готово: {$done}/{$total}\n");
