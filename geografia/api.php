<?php
// Geografía 1º ESO · Estela Caballero Moreno — © Jose Caballero (Cazorla 2026)
// Servidor sencillo para los récords compartidos y las salas online.
// Guarda todo en la carpeta "datos" junto a este archivo. No guarda datos personales:
// solo iniciales, clase (opcional) y puntuaciones.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const MAX_JUGADORES = 8;
const VIDA_SALA = 4 * 3600;           // las salas se borran a las 4 horas
const ESCALAS = ['MU', 'AF', 'AM', 'AS', 'OC', 'EU', 'TD'];

$DIR = __DIR__ . '/datos';
if (!is_dir($DIR)) { @mkdir($DIR, 0755, true); }
if (!is_dir("$DIR/salas")) { @mkdir("$DIR/salas", 0755, true); }
if (!file_exists("$DIR/.htaccess")) { @file_put_contents("$DIR/.htaccess", "Require all denied\nDeny from all\n"); }
if (!is_writable($DIR)) { fallo('El servidor no puede escribir en la carpeta datos. Dale permisos de escritura (755 o 775).', 500); }

function responder($datos) { echo json_encode(['ok' => true] + $datos, JSON_UNESCAPED_UNICODE); exit; }
function fallo($msg, $cod = 400) { http_response_code($cod); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }
function ms() { return (int) round(microtime(true) * 1000); }
function token() { return bin2hex(random_bytes(8)); }
function limpio($s, $max) {
  $s = trim(strip_tags((string) $s));
  $s = preg_replace('/[\x00-\x1F<>"\'`]/u', '', $s);
  return mb_substr($s, 0, $max);
}

// Lee y modifica un archivo JSON con bloqueo, para que dos móviles no se pisen
function con_bloqueo($ruta, $fn, $crear = false) {
  if (!$crear && !file_exists($ruta)) return null;
  $f = fopen($ruta, 'c+');
  if (!$f) fallo('No se pudo abrir el archivo de datos', 500);
  flock($f, LOCK_EX);
  $txt = stream_get_contents($f);
  $dato = $txt ? json_decode($txt, true) : null;
  $res = $fn($dato);
  if (is_array($res) && array_key_exists('__guardar', $res)) {
    ftruncate($f, 0); rewind($f);
    fwrite($f, json_encode($res['__guardar'], JSON_UNESCAPED_UNICODE));
    fflush($f);
    $res = $res['__devolver'] ?? null;
  }
  flock($f, LOCK_UN); fclose($f);
  return $res;
}
function leer($ruta) { if (!file_exists($ruta)) return null; $f = fopen($ruta, 'r'); flock($f, LOCK_SH); $t = stream_get_contents($f); flock($f, LOCK_UN); fclose($f); return $t ? json_decode($t, true) : null; }

$entrada = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$p = $entrada + $_GET;
$a = $p['a'] ?? '';

// ======================= RÉCORDS =======================
if ($a === 'ping') responder(['version' => 1, 'ahora' => ms()]);

if ($a === 'records') {
  $esc = in_array($p['esc'] ?? '', ESCALAS) ? $p['esc'] : 'MU';
  $clase = limpio($p['clase'] ?? '', 6);
  $todos = leer("$DIR/records.json") ?: [];
  $lista = $todos[$esc] ?? [];
  if ($clase !== '') $lista = array_values(array_filter($lista, fn($r) => mb_strtoupper($r['c']) === mb_strtoupper($clase)));
  responder(['lista' => array_slice($lista, 0, 10)]);
}

if ($a === 'guardar') {
  $esc = in_array($p['esc'] ?? '', ESCALAS) ? $p['esc'] : null;
  if (!$esc) fallo('Mapa no válido');
  $ini = mb_strtoupper(preg_replace('/[^A-Za-zÑñ]/u', '', (string) ($p['ini'] ?? '')));
  $ini = mb_substr($ini, 0, 3);
  if (mb_strlen($ini) < 1) fallo('Faltan las iniciales');
  $clase = mb_strtoupper(limpio($p['clase'] ?? '', 6));
  $pts = (int) ($p['puntos'] ?? 0);
  $ac = (int) ($p['aciertos'] ?? 0); $tot = (int) ($p['total'] ?? 0);
  // Límite razonable: una partida arcade no puede dar más de unos 1600 puntos por acierto
  if ($pts <= 0 || $pts > 200000 || $ac < 0 || $tot < $ac || $tot > 500 || $pts > max(1, $ac) * 1600) fallo('Puntuación no válida');
  $pos = con_bloqueo("$DIR/records.json", function ($todos) use ($esc, $ini, $clase, $pts, $ac, $tot) {
    $todos = $todos ?: [];
    $lista = $todos[$esc] ?? [];
    $lista[] = ['i' => $ini, 'c' => $clase, 'p' => $pts, 'a' => $ac, 't' => $tot, 'f' => date('Y-m-d')];
    usort($lista, fn($x, $y) => $y['p'] <=> $x['p']);
    $lista = array_slice($lista, 0, 500);
    $todos[$esc] = $lista;
    $pos = 0; foreach ($lista as $k => $r) { if ($r['p'] === $pts && $r['i'] === $ini) { $pos = $k + 1; break; } }
    return ['__guardar' => $todos, '__devolver' => $pos];
  }, true);
  responder(['puesto' => $pos]);
}

// ======================= SALAS ONLINE =======================
function ruta_sala($cod) { global $DIR; return "$DIR/salas/" . preg_replace('/\D/', '', $cod) . '.json'; }
function publica($s, $yo = null) {
  $fase = $s['fase'];
  $jug = [];
  foreach ($s['jugadores'] as $id => $j) {
    $r = $s['respuestas'][$id] ?? null;
    $e = ['id' => $id, 'nombre' => $j['nombre'], 'puntos' => $j['puntos'], 'aciertos' => $j['aciertos'], 'respondido' => $r !== null];
    if ($fase === 'resultado' || $fase === 'fin') { if ($r) { $e['k'] = $r['k']; $e['bien'] = $r['bien']; $e['ganado'] = $r['pts']; } }
    elseif ($r && $id === $yo) { $e['k'] = $r['k']; }
    $jug[] = $e;
  }
  $out = ['codigo' => $s['codigo'], 'fase' => $fase, 'tv' => $s['tv'], 'jefe' => $s['jefe'], 'host' => $s['hostJugador'], 'esc' => $s['esc'], 'cat' => $s['cat'],
          'ronda' => $s['ronda'], 'rondas' => $s['rondas'], 'q' => $s['q'], 'inicio' => $s['inicio'], 'dur' => $s['dur'], 'revelado' => $s['revelado'], 'jugadores' => $jug];
  if ($fase === 'resultado' || $fase === 'fin') $out['correcta'] = $s['correcta'];
  return $out;
}
function cargar_sala($cod) { $s = leer(ruta_sala($cod)); if (!$s) fallo('Esa sala no existe o ya ha terminado', 404); return $s; }
function es_host($s, $p) { return isset($p['host']) && hash_equals($s['hostToken'], (string) $p['host']); }
function es_jugador($s, $p) { $id = (string) ($p['yo'] ?? ''); return isset($s['jugadores'][$id]) && hash_equals($s['jugadores'][$id]['token'], (string) ($p['token'] ?? '')); }

if ($a === 'crear') {
  foreach (glob("$DIR/salas/*.json") as $f) { if (filemtime($f) < time() - VIDA_SALA) @unlink($f); }
  $esc = in_array($p['esc'] ?? '', ESCALAS) ? $p['esc'] : 'MU';
  $cat = in_array($p['cat'] ?? '', ['todo', 'relieve', 'costas', 'aguas']) ? $p['cat'] : 'todo';
  $rondas = max(3, min(30, (int) ($p['rondas'] ?? 10)));
  $tv = !empty($p['tv']);
  do { $cod = (string) random_int(1000, 9999); } while (file_exists(ruta_sala($cod)));
  $s = ['codigo' => $cod, 'creada' => ms(), 'tv' => $tv, 'esc' => $esc, 'cat' => $cat, 'rondas' => $rondas, 'ronda' => 0, 'fase' => 'espera',
        'hostToken' => token(), 'hostJugador' => null, 'jefe' => null, 'jugadores' => [], 'respuestas' => [], 'q' => null, 'correcta' => null, 'inicio' => 0, 'dur' => 20, 'revelado' => 0];
  $yo = null;
  if (!$tv) {
    $nombre = limpio($p['nombre'] ?? '', 12) ?: 'Jugador';
    $yo = 'j1'; $s['jugadores'][$yo] = ['nombre' => $nombre, 'token' => token(), 'puntos' => 0, 'aciertos' => 0, 'visto' => ms()];
    $s['hostJugador'] = $yo; $s['jefe'] = $yo;
  }
  file_put_contents(ruta_sala($cod), json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX);
  responder(['codigo' => $cod, 'host' => $s['hostToken'], 'yo' => $yo, 'token' => $yo ? $s['jugadores'][$yo]['token'] : null, 'ahora' => ms(), 'sala' => publica($s, $yo)]);
}

if ($a === 'unir') {
  $nombre = limpio($p['nombre'] ?? '', 12) ?: 'Jugador';
  $r = con_bloqueo(ruta_sala($p['sala'] ?? ''), function ($s) use ($nombre) {
    if (!$s) return ['error' => 'Esa sala no existe. Revisa el código.'];
    if ($s['fase'] !== 'espera') return ['error' => 'La partida ya ha empezado.'];
    if (count($s['jugadores']) >= MAX_JUGADORES) return ['error' => 'La sala está llena (máximo ' . MAX_JUGADORES . ').'];
    $base = $nombre; $n = 2; $usados = array_map(fn($j) => mb_strtolower($j['nombre']), $s['jugadores']);
    while (in_array(mb_strtolower($nombre), $usados)) { $nombre = mb_substr($base, 0, 10) . ' ' . $n++; }
    $id = 'j' . (count($s['jugadores']) + 1);
    while (isset($s['jugadores'][$id])) $id .= 'b';
    $tok = token();
    $s['jugadores'][$id] = ['nombre' => $nombre, 'token' => $tok, 'puntos' => 0, 'aciertos' => 0, 'visto' => ms()];
    if (!$s['jefe']) $s['jefe'] = $id;
    return ['__guardar' => $s, '__devolver' => ['yo' => $id, 'token' => $tok, 'sala' => publica($s, $id)]];
  });
  if ($r === null) fallo('Esa sala no existe. Revisa el código.', 404);
  if (isset($r['error'])) fallo($r['error']);
  responder($r + ['ahora' => ms()]);
}

if ($a === 'estado') {
  $s = cargar_sala($p['sala'] ?? '');
  $yo = (string) ($p['yo'] ?? '');
  responder(['ahora' => ms(), 'sala' => publica($s, $yo)]);
}

// Acciones que cambian la sala
$acciones = ['empezar', 'pregunta', 'responder', 'revelar', 'fin', 'expulsar'];
if (in_array($a, $acciones, true)) {
  $r = con_bloqueo(ruta_sala($p['sala'] ?? ''), function ($s) use ($a, $p) {
    if (!$s) return ['error' => 'Esa sala no existe o ya ha terminado'];
    $ahora = ms();
    switch ($a) {
      case 'empezar':
        $jefe = $s['jefe'] && es_jugador($s, $p) && $p['yo'] === $s['jefe'];
        if (!es_host($s, $p) && !$jefe) return ['error' => 'Solo quien creó la sala puede empezar'];
        if (count($s['jugadores']) < 1) return ['error' => 'Todavía no hay jugadores'];
        if ($s['fase'] === 'espera') $s['fase'] = 'lista';
        break;
      case 'pregunta':
        if (!es_host($s, $p)) return ['error' => 'No autorizado'];
        if (!in_array($s['fase'], ['lista', 'resultado'])) return ['error' => 'Ahora no toca pregunta'];
        if ($s['ronda'] >= $s['rondas']) { $s['fase'] = 'fin'; break; }
        $q = $p['q'] ?? null; if (!is_array($q)) return ['error' => 'Pregunta no válida'];
        $s['ronda']++; $s['fase'] = 'pregunta'; $s['q'] = $q; $s['correcta'] = (string) ($p['correcta'] ?? '');
        $s['dur'] = max(8, min(40, (int) ($p['dur'] ?? 20))); $s['inicio'] = $ahora + 600; $s['respuestas'] = []; $s['revelado'] = 0;
        break;
      case 'responder':
        if (!es_jugador($s, $p)) return ['error' => 'No autorizado'];
        if ($s['fase'] !== 'pregunta') return ['error' => 'Ya no se puede responder'];
        $id = $p['yo'];
        if (isset($s['respuestas'][$id])) return ['error' => 'Ya has respondido'];
        $t = max(0, $ahora - $s['inicio']); $durMs = $s['dur'] * 1000;
        if ($t > $durMs + 1500) return ['error' => 'Se acabó el tiempo'];
        $k = (string) ($p['k'] ?? ''); $bien = $k !== '' && $k === $s['correcta'];
        $pts = $bien ? (int) round(100 + 100 * max(0, 1 - $t / $durMs)) : 0;
        $s['respuestas'][$id] = ['k' => $k, 'bien' => $bien, 'pts' => $pts, 't' => $t];
        $s['jugadores'][$id]['visto'] = $ahora;
        break;
      case 'revelar':
        if (!es_host($s, $p)) return ['error' => 'No autorizado'];
        if ($s['fase'] !== 'pregunta') break;
        foreach ($s['respuestas'] as $id => $r) { if (isset($s['jugadores'][$id])) { $s['jugadores'][$id]['puntos'] += $r['pts']; if ($r['bien']) $s['jugadores'][$id]['aciertos']++; } }
        $s['fase'] = 'resultado'; $s['revelado'] = $ahora;
        break;
      case 'fin':
        if (!es_host($s, $p)) return ['error' => 'No autorizado'];
        $s['fase'] = 'fin';
        break;
      case 'expulsar':
        if (!es_host($s, $p)) return ['error' => 'No autorizado'];
        unset($s['jugadores'][(string) ($p['id'] ?? '')]);
        break;
    }
    return ['__guardar' => $s, '__devolver' => ['sala' => publica($s, (string) ($p['yo'] ?? ''))]];
  });
  if ($r === null) fallo('Esa sala no existe o ya ha terminado', 404);
  if (isset($r['error'])) fallo($r['error']);
  responder($r + ['ahora' => ms()]);
}

fallo('Acción desconocida');
