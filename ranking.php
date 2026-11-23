<?php
// Ace of Stars Game: ranking, níveis, estoque de drinques e cadastro por e-mail.
// Os dados ficam fora da pasta pública (o deploy automático do GitHub não apaga).
//
// GET  ranking.php?id=XYZ                      -> placar geral/semanal + perfil do jogador
// POST {a:'game', id, name, av, pts, ...}      -> soma uma partida
// POST {a:'use', id, item:'beer'|'jack'}       -> gasta um drinque do estoque
// POST {a:'code', id, email, lang}             -> manda código de confirmação pro e-mail
// POST {a:'verify', id, email, code, optin, src, name, av, lang} -> liga o aparelho à conta

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const FANS = 'fans@staraceband.com';
const TESTERS = ['thomazfap@gmail.com'];
const LV_PTS  = [0, 50, 150, 300, 600];
const LV_BEER = [0, 1, 1, 0, 2];
const LV_JACK = [0, 0, 0, 1, 1];

$dir = dirname(__DIR__, 2) . '/ace-of-stars-ranking';
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
}
$rankFile = $dir . '/ranking.json';
$playFile = $dir . '/players.json';

function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function week_key() { return gmdate('o-\WW', time() - 3 * 3600); }
function clean_id($s) { return substr(preg_replace('/[^a-zA-Z0-9-]/', '', (string)$s), 0, 64); }
function clean_name($s) {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s)));
    $s = mb_substr($s, 0, 14);
    return $s === '' ? 'Ace' : $s;
}
function clean_av($s) { return substr(preg_replace('/[^0-9-]/', '', (string)$s), 0, 40); }
function clamp($v, $lo, $hi) { return max($lo, min($hi, (int)$v)); }
function jload($f) { $r = @file_get_contents($f); $d = $r ? json_decode($r, true) : null; return is_array($d) ? $d : []; }
function jsave($f, $d) { $t = $f . '.tmp'; file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE)); rename($t, $f); }
function level_of($pts) { $l = 0; foreach (LV_PTS as $i => $p) if ($pts >= $p) $l = $i; return $l; }
function earned($lvl, $arr) { $s = 0; for ($i = 0; $i <= $lvl; $i++) $s += $arr[$i]; return $s; }
function mask_email($e) { [$u, $d] = explode('@', $e, 2); return mb_substr($u, 0, 1) . '•••@' . $d; }

function norm(&$R, &$P) {
    foreach (['all', 'weeks'] as $k) if (!isset($R[$k]) || !is_array($R[$k])) $R[$k] = [];
    foreach (['links', 'acc', 'inv', 'codes'] as $k) if (!isset($P[$k]) || !is_array($P[$k])) $P[$k] = [];
}
function key_for($P, $id) { return $P['links'][$id] ?? $id; }

function add_game(&$map, $key, $g) {
    $r = $map[$key] ?? ['pts' => 0, 'wins' => 0, 'games' => 0, 'nailed' => 0, 'hands' => 0, 'mz' => 0];
    $r['name'] = $g['name'];
    if ($g['av'] !== '') $r['av'] = $g['av'];
    foreach (['pts', 'nailed', 'hands', 'mz'] as $f) $r[$f] += $g[$f];
    $r['wins'] += $g['win']; $r['games'] += 1; $r['t'] = time();
    $map[$key] = $r;
}
function merge_row(&$map, $from, $to) {
    if (!isset($map[$from])) return;
    $a = $map[$from]; $b = $map[$to] ?? null;
    if ($b) {
        foreach (['pts', 'wins', 'games', 'nailed', 'hands', 'mz'] as $f) $b[$f] = ($b[$f] ?? 0) + ($a[$f] ?? 0);
        if (($a['t'] ?? 0) > ($b['t'] ?? 0)) { $b['name'] = $a['name']; if (!empty($a['av'])) $b['av'] = $a['av']; $b['t'] = $a['t']; }
        $map[$to] = $b;
    } else $map[$to] = $a;
    unset($map[$from]);
}
function board($map, $me, $all) {
    $rows = [];
    foreach ($map as $k => $r) { $r['me'] = ($k === $me); $r['lv'] = level_of($all[$k]['pts'] ?? 0); $rows[] = $r; }
    usort($rows, function ($a, $b) { return $b['pts'] !== $a['pts'] ? $b['pts'] <=> $a['pts'] : $b['wins'] <=> $a['wins']; });
    $out = []; $mine = null;
    foreach ($rows as $i => $r) {
        $p = ['pos' => $i + 1, 'name' => $r['name'], 'av' => $r['av'] ?? '', 'lv' => $r['lv'], 'pts' => $r['pts'], 'wins' => $r['wins'],
              'games' => $r['games'], 'acc' => $r['hands'] ? (int)round(100 * $r['nailed'] / $r['hands']) : 0, 'mz' => $r['mz'], 'me' => $r['me']];
        if ($i < 50) $out[] = $p;
        if ($r['me']) $mine = $p;
    }
    return ['top' => $out, 'me' => $mine, 'total' => count($rows)];
}
function profile($R, $P, $key) {
    $pts = $R['all'][$key]['pts'] ?? 0; $lv = level_of($pts);
    $used = $P['inv'][$key] ?? ['b' => 0, 'j' => 0];
    $acc = $P['acc'][$key] ?? null;
    // contas de teste: tudo liberado e estoque cheio (o ranking continua com os pontos reais)
    if ($acc && in_array($acc['email'], TESTERS, true)) {
        return ['pts' => max($pts, 2000), 'level' => 4, 'next' => null, 'beer' => 9, 'jack' => 9, 'tester' => true,
                'linked' => true, 'email' => mask_email($acc['email']), 'name' => $acc['name'] ?? null, 'av' => $acc['av'] ?? null];
    }
    return ['pts' => $pts, 'level' => $lv, 'next' => LV_PTS[$lv + 1] ?? null,
            'beer' => max(0, earned($lv, LV_BEER) - ($used['b'] ?? 0)), 'jack' => max(0, earned($lv, LV_JACK) - ($used['j'] ?? 0)),
            'linked' => (bool)$acc, 'email' => $acc ? mask_email($acc['email']) : null,
            'name' => $acc['name'] ?? null, 'av' => $acc['av'] ?? null];
}
function full($R, $P, $key) {
    $wk = week_key();
    return ['all' => board($R['all'], $key, $R['all']), 'week' => board($R['weeks'][$wk] ?? [], $key, $R['all']), 'weekKey' => $wk, 'profile' => profile($R, $P, $key)];
}
// Envio pelo Gmail do fans@ (SMTP). A senha de app fica em public_html/ace-mail.php, fora do GitHub:
//   <?php define('ACE_SMTP_USER','fans@staraceband.com'); define('ACE_SMTP_PASS','xxxx xxxx xxxx xxxx');
$cfg = dirname(__DIR__) . '/ace-mail.php';
if (is_file($cfg)) include_once $cfg;

function smtp_send($to, $subj, $body, $replyTo = null) {
    $user = ACE_SMTP_USER; $pass = str_replace(' ', '', ACE_SMTP_PASS);
    $fp = @stream_socket_client('ssl://smtp.gmail.com:465', $en, $es, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) { $d = ''; while (($l = fgets($fp, 1024)) !== false) { $d .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $d; };
    $cmd = function ($c, $ok) use ($fp, $read) { if ($c !== null) fwrite($fp, $c . "\r\n"); $r = $read(); return strpos($r, (string)$ok) === 0; };
    $ok = $cmd(null, 220) && $cmd('EHLO staraceband.com', 250) && $cmd('AUTH LOGIN', 334)
        && $cmd(base64_encode($user), 334) && $cmd(base64_encode($pass), 235)
        && $cmd("MAIL FROM:<$user>", 250) && $cmd("RCPT TO:<$to>", 250) && $cmd('DATA', 354);
    if ($ok) {
        $h = "From: StarAce <$user>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subj) . "?=\r\n"
           . "Date: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@staraceband.com>\r\n"
           . ($replyTo ? "Reply-To: <$replyTo>\r\n" : '')
           . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        fwrite($fp, $h . "\r\n" . chunk_split(base64_encode($body)) . "\r\n.\r\n");
        $ok = strpos($read(), '250') === 0;
    }
    @fwrite($fp, "QUIT\r\n"); fclose($fp);
    return $ok;
}
function send_mail($to, $subj, $body, $replyTo = null) {
    if (defined('ACE_SMTP_USER') && defined('ACE_SMTP_PASS')) return smtp_send($to, $subj, $body, $replyTo);
    $h = "From: StarAce <" . FANS . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    if ($replyTo) $h .= "\r\nReply-To: " . $replyTo;
    return @mail($to, '=?UTF-8?B?' . base64_encode($subj) . '?=', $body, $h, '-f' . FANS);
}

$lock = fopen($dir . '/.lock', 'c');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $id = clean_id($_GET['id'] ?? '');
    flock($lock, LOCK_SH);
    $R = jload($rankFile); $P = jload($playFile); norm($R, $P);
    flock($lock, LOCK_UN);
    out(full($R, $P, key_for($P, $id)));
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) out(['error' => 'bad request'], 400);
$id = clean_id($in['id'] ?? '');
if (strlen($id) < 8) out(['error' => 'bad request'], 400);
$a = $in['a'] ?? 'game';
$lang = in_array($in['lang'] ?? '', ['pt', 'en', 'es'], true) ? $in['lang'] : 'pt';

flock($lock, LOCK_EX);
$R = jload($rankFile); $P = jload($playFile); norm($R, $P);
$key = key_for($P, $id);

if ($a === 'game') {
    $nailed = clamp($in['nailed'] ?? 0, 0, 80);
    $g = ['name' => clean_name($in['name'] ?? ''), 'av' => clean_av($in['av'] ?? ''), 'pts' => clamp($in['pts'] ?? 0, 0, 150),
          'win' => empty($in['win']) ? 0 : 1, 'nailed' => $nailed, 'hands' => clamp($in['hands'] ?? 0, $nailed, 80), 'mz' => clamp($in['mz'] ?? 0, 0, 10)];
    $prev = $R['all'][$key] ?? null;
    if ($prev && time() - ($prev['t'] ?? 0) < 15) { flock($lock, LOCK_UN); out(['error' => 'too fast'], 429); }
    $prevLevel = level_of($prev['pts'] ?? 0);
    $wk = week_key();
    if (!isset($R['weeks'][$wk])) $R['weeks'][$wk] = [];
    add_game($R['all'], $key, $g);
    if (isset($P['acc'][$key])) { $P['acc'][$key]['name'] = $g['name']; if ($g['av'] !== '') $P['acc'][$key]['av'] = $g['av']; jsave($playFile, $P); }
    add_game($R['weeks'][$wk], $key, $g);
    ksort($R['weeks']);
    $R['weeks'] = array_slice($R['weeks'], -8, null, true);
    jsave($rankFile, $R);
    flock($lock, LOCK_UN);
    out(full($R, $P, $key) + ['prevLevel' => $prevLevel]);
}

if ($a === 'profile') {
    if (isset($P['acc'][$key])) {
        $P['acc'][$key]['name'] = clean_name($in['name'] ?? '');
        $av = clean_av($in['av'] ?? ''); if ($av !== '') $P['acc'][$key]['av'] = $av;
        jsave($playFile, $P);
        // atualiza o nome e o personagem também no ranking
        $nm = $P['acc'][$key]['name'];
        if (isset($R['all'][$key])) { $R['all'][$key]['name'] = $nm; if ($av !== '') $R['all'][$key]['av'] = $av; }
        foreach ($R['weeks'] as $wk => $m) if (isset($m[$key])) { $R['weeks'][$wk][$key]['name'] = $nm; if ($av !== '') $R['weeks'][$wk][$key]['av'] = $av; }
        jsave($rankFile, $R);
    }
    flock($lock, LOCK_UN);
    out(['profile' => profile($R, $P, $key)]);
}

if ($a === 'use') {
    $item = ($in['item'] ?? '') === 'jack' ? 'j' : 'b';
    $pr = profile($R, $P, $key);
    if (($item === 'j' ? $pr['jack'] : $pr['beer']) < 1) { flock($lock, LOCK_UN); out(['error' => 'empty', 'profile' => $pr], 409); }
    if (!empty($pr['tester'])) { flock($lock, LOCK_UN); out(['profile' => $pr]); }
    $u = $P['inv'][$key] ?? ['b' => 0, 'j' => 0];
    $u[$item] = ($u[$item] ?? 0) + 1;
    $P['inv'][$key] = $u;
    jsave($playFile, $P);
    flock($lock, LOCK_UN);
    out(['profile' => profile($R, $P, $key)]);
}

if ($a === 'code') {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) { flock($lock, LOCK_UN); out(['error' => 'email'], 400); }
    $h = sha1($email); $c = $P['codes'][$h] ?? ['sent' => []];
    $now = time();
    $c['sent'] = array_values(array_filter($c['sent'] ?? [], function ($t) use ($now) { return $now - $t < 3600; }));
    if ((count($c['sent']) && $now - end($c['sent']) < 45) || count($c['sent']) >= 6) { flock($lock, LOCK_UN); out(['error' => 'wait'], 429); }
    $code = (string)random_int(100000, 999999);
    $c = ['code' => $code, 'exp' => $now + 1800, 'tries' => 0, 'dev' => $id, 'sent' => array_merge($c['sent'], [$now])];
    $P['codes'][$h] = $c;
    // limpa códigos velhos
    foreach ($P['codes'] as $k => $v) if (($v['exp'] ?? 0) < $now - 86400) unset($P['codes'][$k]);
    jsave($playFile, $P);
    flock($lock, LOCK_UN);
    $T = [
        'pt' => ["Seu código do Ace of Stars: $code", "Olá!\n\nSeu código pra salvar o progresso no Ace of Stars Game é:\n\n    $code\n\nEle vale por 30 minutos. Se não foi você, é só ignorar este e-mail.\n\nStarAce\nhttps://staraceband.com/game\n"],
        'en' => ["Your Ace of Stars code: $code", "Hi!\n\nYour code to save your progress in Ace of Stars Game is:\n\n    $code\n\nIt’s valid for 30 minutes. If this wasn’t you, just ignore this email.\n\nStarAce\nhttps://staraceband.com/game\n"],
        'es' => ["Tu código de Ace of Stars: $code", "¡Hola!\n\nTu código para guardar tu progreso en Ace of Stars Game es:\n\n    $code\n\nVale por 30 minutos. Si no fuiste tú, ignora este correo.\n\nStarAce\nhttps://staraceband.com/game\n"],
    ][$lang];
    $ok = send_mail($email, $T[0], $T[1]);
    out($ok ? ['ok' => true] : ['error' => 'mail'], $ok ? 200 : 502);
}

if ($a === 'verify' || $a === 'link') {
    $email = strtolower(trim((string)($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) { flock($lock, LOCK_UN); out(['error' => 'email'], 400); }
    $h = sha1($email);
    if ($a === 'verify') {
        $c = $P['codes'][$h] ?? null;
        if (!$c || ($c['exp'] ?? 0) < time() || ($c['tries'] ?? 0) >= 5) { flock($lock, LOCK_UN); out(['error' => 'expired'], 400); }
        if (!hash_equals((string)$c['code'], preg_replace('/\D/', '', (string)($in['code'] ?? '')))) {
            $P['codes'][$h]['tries'] = ($c['tries'] ?? 0) + 1; jsave($playFile, $P); flock($lock, LOCK_UN); out(['error' => 'code'], 400);
        }
        unset($P['codes'][$h]);
    }
    $acctKey = 'u' . substr(sha1($email), 0, 16);
    $isNew = !isset($P['acc'][$acctKey]);
    $mode = $in['mode'] ?? '';
    $devName = clean_name($in['name'] ?? ''); $devAv = clean_av($in['av'] ?? '');
    // e-mail já tem outro personagem: pergunta antes de mexer em qualquer coisa
    if (!$isNew && $key !== $acctKey && $mode === '') {
        $acc0 = $P['acc'][$acctKey];
        if (($acc0['name'] ?? '') !== $devName || (!empty($acc0['av']) && $devAv !== '' && $acc0['av'] !== $devAv)) {
            flock($lock, LOCK_UN);
            out(['conflict' => true, 'acc' => ['name' => $acc0['name'] ?? '', 'av' => $acc0['av'] ?? '']]);
        }
    }
    // ficar com o personagem deste aparelho: o antigo continua no ranking, só sem o e-mail
    if (!$isNew && $mode === 'takeover' && $key !== $acctKey) {
        $orphan = 'o' . substr(sha1($acctKey . microtime()), 0, 16);
        merge_row($R['all'], $acctKey, $orphan);
        foreach ($R['weeks'] as $wk => $m) { merge_row($m, $acctKey, $orphan); $R['weeks'][$wk] = $m; }
        if (isset($P['inv'][$acctKey])) { $P['inv'][$orphan] = $P['inv'][$acctKey]; unset($P['inv'][$acctKey]); }
        foreach ($P['links'] as $d => $k) if ($k === $acctKey) $P['links'][$d] = $orphan;
        $P['acc'][$acctKey]['name'] = $devName;
        if ($devAv !== '') $P['acc'][$acctKey]['av'] = $devAv; else unset($P['acc'][$acctKey]['av']);
    }
    // junta o progresso deste aparelho na conta
    if ($key !== $acctKey) {
        merge_row($R['all'], $key, $acctKey);
        foreach ($R['weeks'] as $wk => $m) { merge_row($m, $key, $acctKey); $R['weeks'][$wk] = $m; }
        if (isset($P['inv'][$key])) {
            $u = $P['inv'][$acctKey] ?? ['b' => 0, 'j' => 0];
            $u['b'] += $P['inv'][$key]['b'] ?? 0; $u['j'] += $P['inv'][$key]['j'] ?? 0;
            $P['inv'][$acctKey] = $u; unset($P['inv'][$key]);
        }
        foreach ($P['links'] as $d => $k) if ($k === $key) $P['links'][$d] = $acctKey;
    }
    $P['links'][$id] = $acctKey;
    $src = ($in['src'] ?? '') === 'app' ? 'app' : 'site';
    $name = clean_name($in['name'] ?? '');
    $optin = !empty($in['optin']);
    $acc = $P['acc'][$acctKey] ?? ['email' => $email, 'created' => time(), 'src' => $src, 'lang' => $lang];
    $acc['optin'] = $optin || !empty($acc['optin']);
    if ($isNew || empty($acc['name'])) $acc['name'] = $name;
    $av = clean_av($in['av'] ?? '');
    if ($isNew || empty($acc['av'])) { if ($av !== '') $acc['av'] = $av; }
    $P['acc'][$acctKey] = $acc;
    jsave($rankFile, $R); jsave($playFile, $P);
    flock($lock, LOCK_UN);
    if ($isNew) {
        $via = $src === 'app' ? 'app StarAce' : 'site staraceband.com';
        $when = gmdate('d/m/Y H:i', time() - 3 * 3600);
        send_mail(FANS, "[ACE OF STARS GAME] Novo cadastro via " . ($src === 'app' ? 'APP' : 'SITE') . ": $name",
            "Novo jogador cadastrado pelo jogo Ace of Stars Game.\n\n" .
            "Origem: Ace of Stars Game (staraceband.com/game)\n" .
            "Jogou pelo: $via\n" .
            "Nome de Ace: $name\n" .
            "E-mail: $email\n" .
            "Aceita receber novidades: " . ($optin ? 'SIM' : 'não') . "\n" .
            "Idioma: $lang\n" .
            "Data: $when (Brasília)\n", $email);
    }
    out(full($R, $P, $acctKey) + ['ok' => true, 'new' => $isNew]);
}

flock($lock, LOCK_UN);
out(['error' => 'bad request'], 400);
