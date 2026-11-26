<?php
declare(strict_types=1);
/**
 * POST /api/event.php
 *   X-Ingest-Key: <key>
 *   {project_ref, kind, level, title, text, server}
 */
require __DIR__ . '/../lib/ingest.php';

ingest_check_key();
ingest_json(ingest_event(ingest_body()));
