<?php
// Ace of Stars Game: salas online.
// O celular de quem criou a sala (anfitrião) roda a partida e manda pra cada jogador
// só o que ele pode ver. Este arquivo guarda a sala, repassa as jogadas e as telas.
//
// POST {a:'create', id, name, av, lang}            -> {code, token, pid}
// POST {a:'join', code, id, name, av, lang}        -> {code, token, pid}
// POST {a:'lobby', code, token}                    -> estado da sala de espera
// POST {a:'start', code, token, seats}             -> anfitrião começa a partida
// POST {a:'push', code, token, views:{pid:view}}   -> anfitrião manda a tela de cada um
// POST {a:'poll', code, token, since}              -> jogador busca a própria tela
// POST {a:'act', code, token, act}                 -> jogador manda uma jogada
// POST {a:'take', code, token}                     -> anfitrião pega as jogadas
// POST {a:'reopen', code, token}                   -> anfitrião volta todo mundo pra sala de espera
// POST {a:'leave', code, token}                    -> sai da sala (anfitrião fecha a sala)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const MAX_HUMANS = 8;
const ROOM_TTL = 6 * 3600;

$base = dirname(__DIR__, 2) . '/ace-of-stars-ranking';
if (!is_dir($base) && !@mkdir($base, 0750, true)) $base = __DIR__ . '/data';
$dir = $base . '/rooms';
if (!is_dir($dir)) @mkdir($dir, 0750, true);

function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function clean_id($s) { return substr(preg_replace('/[^a-zA-Z0-9-]/', '', (string)$s), 0, 64); }
function clean_code($s) { return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$s)), 0, 6); }
function clean_name($s) { $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s))); $s = mb_substr($s, 0, 14); return $s === '' ? 'Ace' : $s; }
function clean_av($s) { return substr(preg_replace('/[^0-9-]/', '', (string)$s), 0, 40); }
function clean_lang($s) { return in_array($s, ['pt', 'en', 'es'], true) ? $s : 'pt'; }
function new_token() { return bin2hex(random_bytes(12)); }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) out(['error' => 'bad'], 400);
$a = $in['a'] ?? '';

// limpa salas velhas de vez em quando
if ($a === 'create' && mt_rand(1, 10) === 1) {
    foreach (glob($dir . '/*.json') ?: [] as $f) if (filemtime($f) < time() - ROOM_TTL) @unlink($f);
}

// abre a sala com trava, roda $fn e salva se mudou
function with_room($dir, $code, $fn, $create = false) {
    $f = $dir . '/' . $code . '.json';
    if (!$create && !is_file($f)) out(['error' => 'noroom'], 404);
    $h = fopen($f, 'c+'); flock($h, LOCK_EX);
    $raw = stream_get_contents($h); $R = $raw ? json_decode($raw, true) : null;
    if (!$create && !is_array($R)) { flock($h, LOCK_UN); fclose($h); out(['error' => 'noroom'], 404); }
    $res = $fn($R);
    if (is_array($res) && array_key_exists('__save', $res)) {
        $R = $res['__save']; unset($res['__save']);
        ftruncate($h, 0); rewind($h); fwrite($h, json_encode($R, JSON_UNESCAPED_UNICODE)); fflush($h);
    }
    flock($h, LOCK_UN); fclose($h);
    return $res;
}
function me_of(&$R, $token) { foreach ($R['players'] as $i => $p) if (hash_equals($p['token'], (string)$token)) return $i; return -1; }
function pub_players($R) {
    return array_map(fn($p) => ['pid' => $p['pid'], 'name' => $p['name'], 'av' => $p['av'], 'lang' => $p['lang'], 'left' => !empty($p['left']), 'seen' => $p['seen']], $R['players']);
}
function lobby_out($R, $me) {
    return ['code' => $R['code'], 'status' => $R['status'], 'hostPid' => $R['hostPid'], 'pid' => $R['players'][$me]['pid'],
            'players' => pub_players($R), 'now' => time()];
}

if ($a === 'create') {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $code = ''; for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        if (!is_file($dir . '/' . $code . '.json')) break;
    }
    $tok = new_token();
    return out(with_room($dir, $code, function ($R) use ($in, $code, $tok) {
        $R = ['code' => $code, 'created' => time(), 'status' => 'lobby', 'hostPid' => 'p1', 'n' => 1, 'ver' => 0,
              'players' => [['pid' => 'p1', 'id' => clean_id($in['id'] ?? ''), 'name' => clean_name($in['name'] ?? ''), 'av' => clean_av($in['av'] ?? ''),
                             'lang' => clean_lang($in['lang'] ?? ''), 'token' => $tok, 'seen' => time()]],
              'views' => new stdClass(), 'actions' => []];
        return ['__save' => $R, 'code' => $code, 'token' => $tok, 'pid' => 'p1'];
    }, true));
}

$code = clean_code($in['code'] ?? '');
if ($code === '') out(['error' => 'noroom'], 404);

if ($a === 'join') {
    out(with_room($dir, $code, function ($R) use ($in, $code) {
        $id = clean_id($in['id'] ?? '');
        foreach ($R['players'] as $i => $p) {
            if ($id !== '' && $p['id'] === $id) { // voltou pra mesma sala (reconexão)
                $R['players'][$i]['seen'] = time(); unset($R['players'][$i]['left']);
                return ['__save' => $R, 'code' => $code, 'token' => $p['token'], 'pid' => $p['pid'], 'status' => $R['status']];
            }
        }
        if ($R['status'] !== 'lobby') return ['error' => 'started'];
        $alive = count(array_filter($R['players'], fn($p) => empty($p['left'])));
        if ($alive >= MAX_HUMANS) return ['error' => 'full'];
        $R['n']++; $pid = 'p' . $R['n']; $tok = new_token();
        $R['players'][] = ['pid' => $pid, 'id' => $id, 'name' => clean_name($in['name'] ?? ''), 'av' => clean_av($in['av'] ?? ''),
                           'lang' => clean_lang($in['lang'] ?? ''), 'token' => $tok, 'seen' => time()];
        return ['__save' => $R, 'code' => $code, 'token' => $tok, 'pid' => $pid, 'status' => 'lobby'];
    }));
}

$token = (string)($in['token'] ?? '');

if ($a === 'lobby') {
    out(with_room($dir, $code, function ($R) use ($token) {
        $me = me_of($R, $token); if ($me < 0) return ['error' => 'auth'];
        $R['players'][$me]['seen'] = time();
        return ['__save' => $R] + lobby_out($R, $me);
    }));
}

if ($a === 'start') {
    out(with_room($dir, $code, function ($R) use ($token) {
        $me = me_of($R, $token); if ($me < 0 || $R['players'][$me]['pid'] !== $R['hostPid']) return ['error' => 'auth'];
        $R['status'] = 'playing'; $R['started'] = time();
        return ['__save' => $R] + lobby_out($R, $me);
    }));
}

if ($a === 'push') {
    out(with_room($dir, $code, function ($R) use ($token, $in) {
        $me = me_of($R, $token); if ($me < 0 || $R['players'][$me]['pid'] !== $R['hostPid']) return ['error' => 'auth'];
        $R['players'][$me]['seen'] = time();
        $R['ver']++;
        $views = is_array($in['views'] ?? null) ? $in['views'] : [];
        $R['views'] = new stdClass();
        foreach ($views as $pid => $v) $R['views']->{preg_replace('/[^a-z0-9]/', '', $pid)} = $v;
        if (!empty($in['over'])) $R['status'] = 'over';
        return ['__save' => $R, 'ver' => $R['ver']];
    }));
}

if ($a === 'poll') {
    out(with_room($dir, $code, function ($R) use ($token, $in) {
        $me = me_of($R, $token); if ($me < 0) return ['error' => 'auth'];
        $R['players'][$me]['seen'] = time();
        $pid = $R['players'][$me]['pid']; $host = null;
        foreach ($R['players'] as $p) if ($p['pid'] === $R['hostPid']) $host = $p;
        $o = ['status' => $R['status'], 'ver' => $R['ver'], 'hostSeen' => time() - ($host['seen'] ?? 0)];
        $views = (array)$R['views'];
        if ((int)($in['since'] ?? -1) < $R['ver'] && isset($views[$pid])) $o['view'] = $views[$pid];
        return ['__save' => $R] + $o;
    }));
}

if ($a === 'act') {
    out(with_room($dir, $code, function ($R) use ($token, $in) {
        $me = me_of($R, $token); if ($me < 0) return ['error' => 'auth'];
        $R['players'][$me]['seen'] = time();
        $act = is_array($in['act'] ?? null) ? $in['act'] : [];
        $R['actions'][] = ['pid' => $R['players'][$me]['pid'], 'act' => $act, 't' => time()];
        if (count($R['actions']) > 50) $R['actions'] = array_slice($R['actions'], -50);
        return ['__save' => $R, 'ok' => true];
    }));
}

if ($a === 'take') {
    out(with_room($dir, $code, function ($R) use ($token) {
        $me = me_of($R, $token); if ($me < 0 || $R['players'][$me]['pid'] !== $R['hostPid']) return ['error' => 'auth'];
        $R['players'][$me]['seen'] = time();
        $acts = $R['actions']; $R['actions'] = [];
        $seen = []; foreach ($R['players'] as $p) $seen[$p['pid']] = ['ago' => time() - $p['seen'], 'left' => !empty($p['left'])];
        return ['__save' => $R, 'actions' => $acts, 'seen' => $seen];
    }));
}

if ($a === 'reopen') {
    out(with_room($dir, $code, function ($R) use ($token) {
        $me = me_of($R, $token); if ($me < 0 || $R['players'][$me]['pid'] !== $R['hostPid']) return ['error' => 'auth'];
        $R['status'] = 'lobby'; $R['views'] = new stdClass(); $R['actions'] = []; $R['ver']++;
        $R['players'] = array_values(array_filter($R['players'], fn($p) => empty($p['left'])));
        return ['__save' => $R] + lobby_out($R, me_of($R, $token));
    }));
}

if ($a === 'leave') {
    out(with_room($dir, $code, function ($R) use ($token) {
        $me = me_of($R, $token); if ($me < 0) return ['error' => 'auth'];
        $R['players'][$me]['left'] = true;
        if ($R['players'][$me]['pid'] === $R['hostPid']) $R['status'] = 'closed';
        elseif ($R['status'] === 'lobby') array_splice($R['players'], $me, 1);
        return ['__save' => $R, 'ok' => true];
    }));
}

out(['error' => 'bad'], 400);
