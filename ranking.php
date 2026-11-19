<?php
// Ranking do Ace of Stars Game.
// Guarda os pontos num arquivo JSON fora da pasta pública (não é apagado pelo deploy do GitHub).
// GET  ranking.php?id=XYZ        -> placar geral e da semana
// POST ranking.php  {id,name,pts,win,nailed,hands,mz}  -> soma uma partida

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = dirname(__DIR__, 2) . '/ace-of-stars-ranking';
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
}
$file = $dir . '/ranking.json';

function week_key() { return gmdate('o-\WW', time() - 3 * 3600); } // semana ISO no horário de Brasília
function clean_id($s) { return substr(preg_replace('/[^a-zA-Z0-9-]/', '', (string)$s), 0, 64); }
function clean_name($s) {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s)));
    $s = mb_substr($s, 0, 14);
    return $s === '' ? 'Ace' : $s;
}
function clamp($v, $lo, $hi) { return max($lo, min($hi, (int)$v)); }
function load_data($fh) {
    rewind($fh);
    $raw = stream_get_contents($fh);
    $d = $raw ? json_decode($raw, true) : null;
    if (!is_array($d)) $d = [];
    if (!isset($d['all']) || !is_array($d['all'])) $d['all'] = [];
    if (!isset($d['weeks']) || !is_array($d['weeks'])) $d['weeks'] = [];
    return $d;
}
function add_game(&$map, $id, $g) {
    $r = isset($map[$id]) ? $map[$id] : ['pts' => 0, 'wins' => 0, 'games' => 0, 'nailed' => 0, 'hands' => 0, 'mz' => 0];
    $r['name'] = $g['name'];
    if ($g['av'] !== '') $r['av'] = $g['av'];
    $r['pts'] += $g['pts'];
    $r['wins'] += $g['win'];
    $r['games'] += 1;
    $r['nailed'] += $g['nailed'];
    $r['hands'] += $g['hands'];
    $r['mz'] += $g['mz'];
    $r['t'] = time();
    $map[$id] = $r;
}
function board($map, $me) {
    $rows = [];
    foreach ($map as $id => $r) { $r['me'] = ($id === $me); $rows[] = $r; }
    usort($rows, function ($a, $b) {
        if ($b['pts'] !== $a['pts']) return $b['pts'] <=> $a['pts'];
        return $b['wins'] <=> $a['wins'];
    });
    $out = []; $mine = null;
    foreach ($rows as $i => $r) {
        $p = [
            'pos' => $i + 1, 'name' => $r['name'], 'av' => $r['av'] ?? '', 'pts' => $r['pts'], 'wins' => $r['wins'], 'games' => $r['games'],
            'acc' => $r['hands'] ? (int)round(100 * $r['nailed'] / $r['hands']) : 0, 'mz' => $r['mz'], 'me' => $r['me'],
        ];
        if ($i < 50) $out[] = $p;
        if ($r['me']) $mine = $p;
    }
    return ['top' => $out, 'me' => $mine, 'total' => count($rows)];
}

$fh = @fopen($file, 'c+');
if (!$fh) { http_response_code(500); echo '{"error":"storage"}'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $id = is_array($in) ? clean_id($in['id'] ?? '') : '';
    if (strlen($id) < 8) { http_response_code(400); echo '{"error":"bad request"}'; exit; }
    $nailed = clamp($in['nailed'] ?? 0, 0, 80);
    $g = [
        'name' => clean_name($in['name'] ?? ''),
        'av' => substr(preg_replace('/[^0-9-]/', '', (string)($in['av'] ?? '')), 0, 40),
        'pts' => clamp($in['pts'] ?? 0, 0, 40),
        'win' => empty($in['win']) ? 0 : 1,
        'nailed' => $nailed,
        'hands' => clamp($in['hands'] ?? 0, $nailed, 80),
        'mz' => clamp($in['mz'] ?? 0, 0, 10),
    ];
    flock($fh, LOCK_EX);
    $d = load_data($fh);
    $prev = $d['all'][$id] ?? null;
    if ($prev && time() - ($prev['t'] ?? 0) < 15) { // evita envio repetido
        flock($fh, LOCK_UN); http_response_code(429); echo '{"error":"too fast"}'; exit;
    }
    $wk = week_key();
    if (!isset($d['weeks'][$wk])) $d['weeks'][$wk] = [];
    add_game($d['all'], $id, $g);
    add_game($d['weeks'][$wk], $id, $g);
    ksort($d['weeks']);
    $d['weeks'] = array_slice($d['weeks'], -8, null, true); // guarda só as últimas 8 semanas
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($d, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    echo json_encode(['all' => board($d['all'], $id), 'week' => board($d['weeks'][$wk], $id), 'weekKey' => $wk], JSON_UNESCAPED_UNICODE);
    exit;
}

$me = clean_id($_GET['id'] ?? '');
flock($fh, LOCK_SH);
$d = load_data($fh);
flock($fh, LOCK_UN);
$wk = week_key();
echo json_encode(['all' => board($d['all'], $me), 'week' => board($d['weeks'][$wk] ?? [], $me), 'weekKey' => $wk], JSON_UNESCAPED_UNICODE);
