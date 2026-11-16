<?php
declare(strict_types=1);
const SOURCE_BASE = 'https://kg.sofia.bg/api';
function storage_dir(): string { return getenv('LITTLE_STEPS_DATA_DIR') ?: __DIR__ . '/data'; }
function read_json(string $path): array {
    $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid JSON object');
    return $data;
}
function write_json(string $path, array $value): void {
    $tmp = tempnam(dirname($path), '.write-');
    if ($tmp === false) throw new RuntimeException('Cannot create temporary file');
    try {
        if (file_put_contents($tmp, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Cannot write data');
        chmod($tmp, 0640);
        if (!rename($tmp, $path)) throw new RuntimeException('Cannot publish data');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function source_json(string $path): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');
    $url = SOURCE_BASE . $path;
    $last = 'Source unavailable';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'LittleSteps/1.0 public kindergarten directory']);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $last = curl_error($ch) ?: 'HTTP ' . $code;
        curl_close($ch);
        if ($body !== false && $code === 200) {
            try { $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR); if (is_array($data) && empty($data['error'])) return $data; } catch (JsonException $e) { $last = 'Invalid source JSON'; }
        }
    }
    throw new RuntimeException($last);
}
function normalize_directory(array $payload): array {
    $list = $payload['items']['kinderGardens'] ?? null;
    if (!is_array($list) || count($list) === 0) throw new RuntimeException('Empty source directory');
    $result = []; $seen = [];
    foreach ($list as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) throw new RuntimeException('Invalid or duplicate listing ID');
        $seen[$id] = true;
        $result[] = ['id'=>$id, 'name'=>$r['nameStr'] ?? $r['name']['publicName'] ?? '', 'type'=>$r['name']['publicType'] ?? '', 'region'=>$r['region'] ?? '', 'address'=>$r['address'] ?? ''];
    }
    return $result;
}
function normalize_detail(array $payload, int $id): array {
    $d = $payload['items']['dz'] ?? null;
    if (!is_array($d) || (int)($d['id'] ?? 0) !== $id) throw new RuntimeException('Wrong source listing');
    $convert = static function(array $groups, bool $current): array {
        $result = [];
        foreach ($groups as $g) {
            if ($current ? empty($g['active']) : (isset($g['active']) && !$g['active'])) continue;
            $spots = [];
            foreach (($g['spots'] ?? []) as $s) {
                $v = $s['svobodni'] ?? null;
                $spots[$s['type']] = (is_int($v) || is_float($v)) && $v >= 0 ? $v : null;
            }
            $result[] = ['id'=>$g['groupId'] ?? null, 'label'=>$g['label'] ?? 'Група без наименование', 'birthYear'=>$g['draft'] ?? null, 'spots'=>(object)$spots];
        }
        return $result;
    };
    return ['id'=>$id, 'schoolYear'=>$payload['items']['schoolYear'] ?? null, 'nextSchoolYear'=>$payload['items']['schoolYearNext'] ?? null, 'current'=>$convert($d['currentGroups'] ?? [], true), 'next'=>$convert($d['featureGroups'] ?? [], false), 'checkedAt'=>gmdate('c')];
}
/** A single bounded batch. Published data is replaced only after a full cycle. */
function run_batch(string $dir, callable $fetch, DateTimeImmutable $now, int $batchSize = 10, float $budget = 12): array {
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('Cannot create storage');
    $lock = fopen($dir . '/refresh.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['status'=>'locked'];
    try {
        $now = $now->setTimezone(new DateTimeZone('Europe/Sofia'));
        $today = $now->format('Y-m-d');
        if ((int)$now->format('H') < 6) return ['status'=>'waiting'];
        $statePath = $dir . '/state.json';
        $state = is_file($statePath) ? read_json($statePath) : [];
        if (($state['date'] ?? '') === $today && ($state['status'] ?? '') === 'complete') return $state;
        if (($state['date'] ?? '') !== $today) {
            // Fetch before replacing state, preserving the last successful snapshot on failure.
            $rows = normalize_directory($fetch('/public/kg/type/kinderGarden/all?filterType=by_region&kgType=0&regionId=0'));
            $state = ['date'=>$today, 'status'=>'running', 'startedAt'=>$now->format('c'), 'rows'=>$rows, 'details'=>[], 'offset'=>0, 'failed'=>[]];
            write_json($statePath, $state);
        }
        $start = microtime(true); $done = 0;
        while ($state['offset'] < count($state['rows']) && $done < $batchSize && microtime(true) - $start < $budget) {
            $r = $state['rows'][$state['offset']]; $id = $r['id'];
            try { $state['details'][(string)$id] = normalize_detail($fetch('/public/0/dz/' . $id), $id); }
            catch (Throwable $e) { $state['failed'][] = $id; error_log('Little Steps: detail ' . $id . ' failed: ' . $e->getMessage()); }
            $state['offset']++; $done++;
            write_json($statePath, $state); // Resume safely even if the host terminates a later request.
        }
        if ($state['offset'] >= count($state['rows'])) {
            // Keep earlier per-listing data for failed requests, with its original timestamp.
            $old = is_file($dir . '/snapshot.json') ? read_json($dir . '/snapshot.json') : ['details'=>[]];
            foreach ($state['failed'] as $id) if (isset($old['details'][(string)$id])) $state['details'][(string)$id] = $old['details'][(string)$id];
            $snapshot = ['rows'=>$state['rows'], 'details'=>(object)$state['details'], 'checkedAt'=>gmdate('c'), 'refresh'=>['date'=>$today, 'failedIds'=>$state['failed'], 'successful'=>count($state['rows'])-count($state['failed']), 'total'=>count($state['rows'])]];
            write_json($dir . '/snapshot.json', $snapshot);
            $state['status'] = 'complete'; $state['finishedAt'] = gmdate('c');
            write_json($statePath, $state);
        }
        return ['status'=>$state['status'], 'processed'=>$state['offset'], 'total'=>count($state['rows']), 'failed'=>count($state['failed'])];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
