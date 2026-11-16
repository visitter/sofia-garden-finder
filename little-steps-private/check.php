<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
try {
    if (!is_writable(storage_dir())) throw new RuntimeException('Data directory is not writable');
    $rows = normalize_directory(source_json('/public/kg/type/kinderGarden/all?filterType=by_region&kgType=0&regionId=0'));
    $d = normalize_detail(source_json('/public/0/dz/' . $rows[0]['id']), $rows[0]['id']);
    echo 'OK: PHP ' . PHP_VERSION . ', writable storage, HTTPS source access, ' . count($rows) . ' listings, ' . count($d['current']) . ' groups in first listing.' . PHP_EOL;
    echo 'Sofia time: ' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Sofia')))->format('c') . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
