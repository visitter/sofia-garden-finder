<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
$dir = getenv('LITTLE_STEPS_DATA_DIR') ?: dirname(__DIR__) . '/little-steps-private/data';
$path = $dir . '/snapshot.json';
if (!is_readable($path)) { http_response_code(503); echo json_encode(['error'=>'Данните временно не са достъпни.'], JSON_UNESCAPED_UNICODE); exit; }
readfile($path);
