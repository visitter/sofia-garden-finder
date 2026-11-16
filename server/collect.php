<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/little-steps-private/lib.php';
try {
    // The Ubuntu timer decides when to run. Manual runs also work before 06:00.
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Sofia'));
    if ((int)$now->format('H') < 6) $now = $now->setTime(6, 0);
    do {
        $r = run_batch(storage_dir(), 'source_json', $now);
        if (($r['status'] ?? '') === 'locked') throw new RuntimeException('Another collector is running');
        if (($r['status'] ?? '') !== 'complete') usleep(300000);
    } while (($r['status'] ?? '') !== 'complete');
    $s = read_json(storage_dir() . '/snapshot.json');
    echo json_encode(['status'=>'complete','total'=>count($s['rows']),'failed'=>count($s['refresh']['failedIds'] ?? [])], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, 'Collection failed: ' . $e->getMessage() . PHP_EOL); exit(1); }
