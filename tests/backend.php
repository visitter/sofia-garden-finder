<?php
declare(strict_types=1);
require dirname(__DIR__) . '/little-steps-private/lib.php';
function ok(bool $v, string $message): void { if (!$v) throw new RuntimeException($message); }
$dir = sys_get_temp_dir() . '/little-steps-test-' . bin2hex(random_bytes(5)); mkdir($dir);
$rows = [['id'=>1,'nameStr'=>'Тест 1'],['id'=>2,'nameStr'=>'Тест 2']];
$payload = static fn(int $id): array => ['items'=>['schoolYear'=>'2026/2027','dz'=>['id'=>$id,'currentGroups'=>[['active'=>1,'label'=>'1-ва група, родени 2023 г.','draft'=>2023,'groupId'=>1,'spots'=>[['type'=>'general','svobodni'=>2],['type'=>'sop','svobodni'=>-1]]],['active'=>0,'spots'=>[['type'=>'general','svobodni'=>99]]]],'featureGroups'=>null]]];
$fetch = static function(string $path) use ($rows, $payload): array { if (str_contains($path,'/type/')) return ['items'=>['kinderGardens'=>$rows]]; return $payload((int)basename($path)); };
try {
 $d = normalize_detail($payload(1),1); ok(count($d['current'])===1,'inactive groups'); ok($d['current'][0]['spots']->sop===null,'negative sentinel');
 $before = run_batch($dir,$fetch,new DateTimeImmutable('2026-11-17 05:59:00',new DateTimeZone('Europe/Sofia')),1); ok($before['status']==='waiting','before 06:00');
 $at = new DateTimeImmutable('2026-11-17T04:00:00Z');
 $r = run_batch($dir,$fetch,$at,1); ok($r['status']==='running' && $r['processed']===1,'first batch'); ok(!file_exists($dir.'/snapshot.json'),'no incomplete publish');
 $r = run_batch($dir,$fetch,$at,1); ok($r['status']==='complete','resume'); $s=read_json($dir.'/snapshot.json'); ok(count($s['details'])===2,'persistent data');
 $never = static function(string $p): array { throw new RuntimeException('should not fetch'); };
 ok(run_batch($dir,$never,$at)['status']==='complete','same day idempotent');
 $partial = static function(string $p) use ($fetch): array { if (str_ends_with($p,'/2')) throw new RuntimeException('source down'); return $fetch($p); };
 $old=$s['details']['2']['checkedAt'];run_batch($dir,$partial,$at->modify('+1 day'),10);$s=read_json($dir.'/snapshot.json');ok($s['refresh']['failedIds']===[2],'partial warning');ok($s['details']['2']['checkedAt']===$old,'retains stale timestamp');
 $saved=file_get_contents($dir.'/snapshot.json');try{run_batch($dir,$never,$at->modify('+2 day'));}catch(RuntimeException $e){}ok(file_get_contents($dir.'/snapshot.json')===$saved,'directory failure preserves snapshot');
 $lock=fopen($dir.'/refresh.lock','c');flock($lock,LOCK_EX);ok(run_batch($dir,$fetch,$at->modify('+2 day'))['status']==='locked','overlap guard');flock($lock,LOCK_UN);fclose($lock);
 // Summer 06:00 Sofia is 03:00 UTC.
 ok(run_batch($dir,$fetch,new DateTimeImmutable('2027-06-01T02:59:00Z'))['status']==='waiting','summer DST');
 echo "PASS: normalization, batches, atomic publish, resume, daily schedule, DST, locking, and source failure fallback\n";
} finally {foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
