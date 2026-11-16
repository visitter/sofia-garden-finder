<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
try {
    $result = run_batch(storage_dir(), 'source_json', new DateTimeImmutable('now', new DateTimeZone('Europe/Sofia')));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, 'Little Steps refresh: ' . $e->getMessage() . PHP_EOL); exit(1); }
