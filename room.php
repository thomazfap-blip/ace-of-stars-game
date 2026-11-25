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
// POST {a:'say', code, token, text}               -> mensagem no chat da sala
//      (lobby, poll e take devolvem as mensagens novas: mande cs = id da última que já tem)
// POST {a:'reopen', code, token}                   -> anfitrião volta todo mundo pra sala de espera
// POST {a:'hello', id, name, av, lv, inv, room}     -> marca que está online; devolve quem está online, convites e mesas abertas
// POST {a:'dm', id, to, m}                          -> manda uma mensagem pronta (número da frase) pra quem está online
// POST {a:'invite', id, to, code, token}           -> convida alguém que está online pra sua sala
// POST {a:'setopen', code, token, open}            -> anfitrião deixa a sala aberta (aparece no saguão) ou fechada
// POST {a:'quick', id, name, av, lang}             -> jogo rápido: entra numa mesa rápida esperando gente ou cria uma
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
const DM_COUNT = 12;
const QUICK_WAIT = 30;
const ONLINE_SECS = 40;

// ---------- presença (quem está online) e convites ----------
function with_json($f, $fn) {
    $h = fopen($f, 'c+'); flock($h, LOCK_EX);
    $raw = stream_get_contents($h); $D = $raw ? (json_decode($raw, true) ?: []) : [];
    [$D, $res] = $fn($D);
    ftruncate($h, 0); rewind($h); fwrite($h, json_encode($D, JSON_UNESCAPED_UNICODE)); fflush($h);
    flock($h, LOCK_UN); fclose($h); return $res;
}
function open_rooms($dir, $skip = '') {
    $list = [];
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        if (filemtime($f) < time() - 120) continue;
        $R = json_decode(@file_get_contents($f), true);
        if (!is_array($R) || !in_array($R['status'] ?? '', ['lobby', 'playing'], true) || empty($R['open']) || $R['code'] === $skip) continue;
        $ps = array_values(array_filter($R['players'], fn($p) => empty($p['left'])));
        if (count($ps) >= MAX_HUMANS || count($ps) === 0) continue;
        $host = null; foreach ($ps as $p) if ($p['pid'] === $R['hostPid']) $host = $p;
        if (!$host || time() - $host['seen'] > 20) continue;
        $list[] = ['code' => $R['code'], 'playing' => $R['status'] === 'playing', 'host' => $host['name'], 'av' => $host['av'], 'n' => count($ps), 'lives' => $R['lives'] ?? null,
                   'quick' => !empty($R['quick']), 'startIn' => !empty($R['quick']) ? max(0, ($R['startAt'] ?? 0) - time()) : null];
    }
    usort($list, fn($a, $b) => $b['n'] <=> $a['n']);
    return array_slice($list, 0, 12);
}

if ($a === 'hello') {
    $id = clean_id($in['id'] ?? ''); if ($id === '') out(['error' => 'bad'], 400);
    $me = ['name' => clean_name($in['name'] ?? ''), 'av' => clean_av($in['av'] ?? ''), 'lv' => max(0, min(4, (int)($in['lv'] ?? 0))),
           'inv' => !empty($in['inv']), 'room' => clean_code($in['room'] ?? ''), 'play' => !empty($in['play']), 't' => time()];
    $online = with_json($base . '/presence.json', function ($D) use ($id, $me) {
        $D[$id] = $me;
        foreach ($D as $k => $v) if (time() - ($v['t'] ?? 0) > 600) unset($D[$k]);
        $list = [];
        foreach ($D as $k => $v) if ($k !== $id && time() - $v['t'] <= ONLINE_SECS && trim($v['name']) !== '' && $v['name'] !== 'Ace')
            $list[] = ['id' => $k, 'name' => $v['name'], 'av' => $v['av'], 'lv' => $v['lv'], 'inv' => $v['inv'], 'busy' => $v['play'], 'room' => $v['room']];
        usort($list, fn($a, $b) => ($a['busy'] <=> $b['busy']) ?: strcasecmp($a['name'], $b['name']));
        return [$D, array_slice($list, 0, 60)];
    });
    $invites = with_json($base . '/invites.json', function ($D) use ($id) {
        $mine = array_values(array_filter($D[$id] ?? [], fn($i) => time() - $i['t'] < 600));
        unset($D[$id]);
        foreach ($D as $k => $v) { $D[$k] = array_values(array_filter($v, fn($i) => time() - $i['t'] < 600)); if (!$D[$k]) unset($D[$k]); }
        return [$D, $mine];
    });
    $dms = with_json($base . '/dms.json', function ($D) use ($id) {
        $mine = array_values(array_filter($D[$id] ?? [], fn($i) => time() - $i['t'] < 600));
        unset($D[$id]);
        foreach ($D as $k => $v) { $D[$k] = array_values(array_filter($v, fn($i) => time() - $i['t'] < 600)); if (!$D[$k]) unset($D[$k]); }
        return [$D, $mine];
    });
    out(['online' => $online, 'invites' => $invites, 'dms' => $dms, 'rooms' => open_rooms($dir, $me['room']), 'count' => count($online) + 1]);
}

// mensagens prontas pra quem está online: só o número da frase viaja, nunca texto livre
if ($a === 'dm') {
    $id = clean_id($in['id'] ?? ''); $to = clean_id($in['to'] ?? ''); $m = (int)($in['m'] ?? -1);
    if ($id === '' || $to === '' || $id === $to || $m < 0 || $m >= DM_COUNT) out(['error' => 'bad'], 400);
    $P = json_decode(@file_get_contents($base . '/presence.json'), true) ?: [];
    if (!isset($P[$to]) || time() - $P[$to]['t'] > ONLINE_SECS) out(['error' => 'off']);
    $res = with_json($base . '/dms.json', function ($D) use ($id, $to, $m, $P) {
        $last = 0; foreach ($D[$to] ?? [] as $i) if ($i['from'] === $id) $last = max($last, $i['t']);
        if (time() - $last < 3) return [$D, ['error' => 'wait']];
        $D[$to][] = ['from' => $id, 'name' => $P[$id]['name'] ?? '', 'av' => $P[$id]['av'] ?? '', 'm' => $m, 't' => time()];
        $D[$to] = array_slice($D[$to], -10);
        return [$D, ['ok' => true]];
    });
    out($res);
}

if ($a === 'invite') {
    $id = clean_id($in['id'] ?? ''); $to = clean_id($in['to'] ?? ''); $code = clean_code($in['code'] ?? '');
    if ($id === '' || $to === '' || $code === '' || $id === $to) out(['error' => 'bad'], 400);
    $f = $dir . '/' . $code . '.json'; $R = is_file($f) ? json_decode(file_get_contents($f), true) : null;
    if (!$R || $R['status'] !== 'lobby' || me_of($R, $in['token'] ?? '') < 0) out(['error' => 'noroom'], 404);
    $P = json_decode(@file_get_contents($base . '/presence.json'), true) ?: [];
    if (!isset($P[$to]) || empty($P[$to]['inv']) || time() - $P[$to]['t'] > ONLINE_SECS) out(['error' => 'off']);
    $from = $P[$id]['name'] ?? clean_name($in['name'] ?? '');
    $res = with_json($base . '/invites.json', function ($D) use ($id, $to, $code, $from, $P) {
        foreach ($D[$to] ?? [] as $i) if ($i['from'] === $id && time() - $i['t'] < 180) return [$D, ['error' => 'wait']];
        $D[$to][] = ['from' => $id, 'name' => $from, 'av' => $P[$id]['av'] ?? '', 'code' => $code, 't' => time()];
        $D[$to] = array_slice($D[$to], -5);
        return [$D, ['ok' => true]];
    });
    out($res);
}

if ($a === 'quick') {
    $now = time(); $found = '';
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        if (filemtime($f) < $now - 60) continue;
        $R = json_decode(@file_get_contents($f), true);
        if (!is_array($R) || empty($R['quick']) || $R['status'] !== 'lobby' || ($R['startAt'] ?? 0) < $now + 3) continue;
        $ps = array_filter($R['players'], fn($p) => empty($p['left']));
        if (count($ps) < MAX_HUMANS) { $found = $R['code']; break; }
    }
    if ($found) { $in['code'] = $found; $a = 'join'; }
    else { $in['open'] = 1; $in['quick'] = 1; $a = 'create'; }
}

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
function chat_since($R, $in) { $cs = (int)($in['cs'] ?? 0); return array_values(array_filter($R['chat'] ?? [], fn($m) => $m['id'] > $cs)); }
function lobby_out($R, $me) {
    return ['open' => !empty($R['open']), 'powers' => !isset($R['powers']) || !empty($R['powers']), 'quick' => !empty($R['quick']), 'startIn' => !empty($R['quick']) ? max(0, ($R['startAt'] ?? 0) - time()) : null,'code' => $R['code'], 'status' => $R['status'], 'hostPid' => $R['hostPid'], 'pid' => $R['players'][$me]['pid'],
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
              'views' => new stdClass(), 'actions' => [], 'open' => !empty($in['open']), 'quick' => !empty($in['quick'])];
        if (!empty($in['quick'])) $R['startAt'] = time() + QUICK_WAIT;
        return ['__save' => $R, 'code' => $code, 'token' => $tok, 'pid' => 'p1', 'hostPid' => 'p1', 'quick' => !empty($in['quick'])];
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
                return ['__save' => $R, 'code' => $code, 'token' => $p['token'], 'pid' => $p['pid'], 'status' => $R['status'], 'hostPid' => $R['hostPid']];
            }
        }
        if ($R['status'] !== 'lobby' && $R['status'] !== 'playing') return ['error' => 'started'];
        $late = $R['status'] === 'playing'; // mesa já rolando: entra na próxima mão
        $alive = count(array_filter($R['players'], fn($p) => empty($p['left'])));
        if ($alive >= MAX_HUMANS) return ['error' => 'full'];
        $R['n']++; $pid = 'p' . $R['n']; $tok = new_token();
        $R['players'][] = ['pid' => $pid, 'id' => $id, 'name' => clean_name($in['name'] ?? ''), 'av' => clean_av($in['av'] ?? ''),
                           'lang' => clean_lang($in['lang'] ?? ''), 'token' => $tok, 'seen' => time()] + ($late ? ['late' => true] : []);
        return ['__save' => $R, 'code' => $code, 'token' => $tok, 'pid' => $pid, 'status' => $R['status'], 'hostPid' => $R['hostPid'], 'late' => $late];
    }));
}

$token = (string)($in['token'] ?? '');

if ($a === 'lobby') {
    out(with_room($dir, $code, function ($R) use ($token, $in) {
        $me = me_of($R, $token); if ($me < 0) return ['error' => 'auth'];
        $R['players'][$me]['seen'] = time();
        return ['__save' => $R, 'chat' => chat_since($R, $in)] + lobby_out($R, $me);
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
        if (is_array($in['snap'] ?? null)) $R['snap'] = $in['snap']; // retrato do começo da mão, pra outro jogador assumir a mesa
        return ['__save' => $R, 'ver' => $R['ver']];
    }));
}

if ($a === 'poll') {
    out(with_room($dir, $code, function ($R) use ($token, $in) {
        $me = me_of($R, $token); if ($me < 0) return ['error' => 'auth'];
        $R['players'][$me]['seen'] = time();
        $pid = $R['players'][$me]['pid']; $host = null;
        foreach ($R['players'] as $p) if ($p['pid'] === $R['hostPid']) $host = $p;
        $o = ['status' => $R['status'], 'ver' => $R['ver'], 'hostSeen' => time() - ($host['seen'] ?? 0), 'chat' => chat_since($R, $in)];
        // anfitrião saiu ou caiu: o primeiro jogador que ainda está na mesa assume a partida
        if ($R['status'] === 'playing' && $host && (!empty($host['left']) || time() - $host['seen'] > 30)) {
            $cand = null;
            foreach ($R['players'] as $q) if ($q['pid'] !== $R['hostPid'] && empty($q['left']) && time() - $q['seen'] <= 10) { $cand = $q['pid']; break; }
            if ($cand === $pid) {
                $R['hostPid'] = $pid; $R['actions'] = []; $R['migrated'] = ($R['migrated'] ?? 0) + 1;
                $o['becomeHost'] = true; $o['snap'] = $R['snap'] ?? null;
            }
        }
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
    out(with_room($dir, $code, function ($R) use ($token, $in) {
        $me = me_of($R, $token); if ($me < 0 || $R['players'][$me]['pid'] !== $R['hostPid']) return ['error' => 'auth'];
        $R['players'][$me]['seen'] = time();
        $acts = $R['actions']; $R['actions'] = [];
        $seen = []; foreach ($R['players'] as $p) $seen[$p['pid']] = ['ago' => time() - $p['seen'], 'left' => !empty($p['left'])];
        return ['__save' => $R, 'actions' => $acts, 'seen' => $seen, 'roster' => pub_players($R), 'chat' => chat_since($R, $in)];
    }));
}

if ($a === 'setopen') {
    out(with_room($dir, $code, function ($R) use ($token, $in) {
        $me = me_of($R, $token); if ($me < 0 || $R['players'][$me]['pid'] !== $R['hostPid']) return ['error' => 'auth'];
        $R['open'] = !empty($in['open']); if (isset($in['lives'])) $R['lives'] = max(1, min(5, (int)$in['lives'])); if (isset($in['powers'])) $R['powers'] = !empty($in['powers']);
        return ['__save' => $R] + lobby_out($R, $me);
    }));
}

if ($a === 'say') {
    out(with_room($dir, $code, function ($R) use ($token, $in) {
        $me = me_of($R, $token); if ($me < 0) return ['error' => 'auth'];
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['text'] ?? ''))));
        $text = mb_substr($text, 0, 140);
        if ($text === '') return ['error' => 'empty'];
        $p = &$R['players'][$me];
        if (time() - ($p['lastSay'] ?? 0) < 1) return ['error' => 'slow'];
        $p['lastSay'] = time(); $p['seen'] = time();
        $R['chatN'] = ($R['chatN'] ?? 0) + 1;
        $R['chat'][] = ['id' => $R['chatN'], 'pid' => $p['pid'], 'name' => $p['name'], 'text' => $text, 't' => time()];
        if (count($R['chat']) > 60) $R['chat'] = array_slice($R['chat'], -60);
        unset($p);
        return ['__save' => $R, 'ok' => true, 'chat' => chat_since($R, $in)];
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
        if ($R['players'][$me]['pid'] === $R['hostPid'] && $R['status'] !== 'playing') $R['status'] = 'closed'; // no meio da partida outro assume
        elseif ($R['status'] === 'lobby') array_splice($R['players'], $me, 1);
        return ['__save' => $R, 'ok' => true];
    }));
}

out(['error' => 'bad'], 400);
