<?php
// ═══════════════════════════════════════════════════════════════════
// KAYAK POLO STATS — index.php
// ═══════════════════════════════════════════════════════════════════

date_default_timezone_set('Europe/Paris');

define('CACHE_TTL',  300);
define('VISIT_LOG',  __DIR__ . '/logs/visits.log');
define('STATS_KEY',  'kps_vidix_2026');

function logVisit(string $compet, string $team): void {
    $dir = dirname(VISIT_LOG);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip  = trim(explode(',', $ip)[0]);
    $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120);
    $line = implode("\t", [
        date('Y-m-d H:i:s'),
        $ip,
        $compet ?: '-',
        $team   ?: '-',
        $ua,
    ]) . "\n";
    file_put_contents(VISIT_LOG, $line, FILE_APPEND | LOCK_EX);
}

if (isset($_GET['stats']) && $_GET['stats'] === STATS_KEY) {
    header('Content-Type: text/plain; charset=UTF-8');
    if (!file_exists(VISIT_LOG)) { echo "Aucune visite enregistrée.\n"; exit; }
    $lines = file(VISIT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $total = count($lines);
    $days  = $ips = $compets = [];
    foreach ($lines as $l) {
        $p = explode("\t", $l);
        if (count($p) < 4) continue;
        $days[substr($p[0],0,10)][$p[1]] = true;
        $ips[$p[1]] = true;
        $compets[$p[2]] = ($compets[$p[2]] ?? 0) + 1;
    }
    echo "=== Kayak Polo Stats — Visites ===\n\n";
    echo "Total visites     : $total\n";
    echo "Visiteurs uniques : " . count($ips) . "\n\n";
    echo "--- Par jour ---\n";
    foreach (array_reverse($days, true) as $d => $u) {
        echo "$d  " . count($u) . " visiteur(s) unique(s)\n";
    }
    echo "\n--- Par compétition ---\n";
    foreach ($compets as $c => $n) echo "$c : $n visite(s)\n";
    echo "\n--- 20 dernières visites ---\n";
    foreach (array_slice($lines, -20) as $l) echo $l . "\n";
    exit;
}

$JOURNEES_N18 = [
    'J1'      => ['dates' => ['28/03/2026','29/03/2026'],              'lieu' => 'Acigné'],
    'J2'      => ['dates' => ['25/04/2026'],                           'lieu' => 'Saint-Omer'],
    'J3'      => ['dates' => ['23/05/2026'],                           'lieu' => 'Avranches'],
    'Finales' => ['dates' => ['03/07/2026','04/07/2026','05/07/2026'], 'lieu' => 'TBD'],
];
$JOURNEES_N15 = [
    'J1'      => ['dates' => ['28/03/2026','29/03/2026'],              'lieu' => 'Acigné'],
    'J2'      => ['dates' => ['25/04/2026'],                           'lieu' => 'Saint-Omer'],
    'J3'      => ['dates' => ['23/05/2026'],                           'lieu' => 'Avranches'],
    'Finales' => ['dates' => ['03/07/2026','04/07/2026','05/07/2026'], 'lieu' => 'TBD'],
];

// ── Cookie compétition ────────────────────────────────────────────
$selectedCompet = isset($_COOKIE['selected_compet']) ? $_COOKIE['selected_compet'] : null;
if ($selectedCompet !== 'N15' && $selectedCompet !== 'N18') $selectedCompet = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_compet'])) {
    $compet = $_POST['compet'] ?? '';
    if ($compet === 'N15' || $compet === 'N18') {
        setcookie('selected_compet', $compet, ['expires'=>time()+365*24*3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        setcookie('selected_team', '', ['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        header('Location: /');
        exit;
    }
}
if (isset($_GET['clear_compet'])) {
    setcookie('selected_compet', '', ['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    setcookie('selected_team',   '', ['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    header('Location: /');
    exit;
}

$sourceUrl = 'https://www.kayak-polo.info/kpmatchs.php?Compet=*&Group=' . ($selectedCompet ?? 'N18') . '&Saison=2026';
$cacheFile = __DIR__ . '/cache/matches_' . ($selectedCompet ?? 'N18') . '.json';
$JOURNEES  = $selectedCompet === 'N15' ? $JOURNEES_N15 : $JOURNEES_N18;

// ── Cookie équipe ─────────────────────────────────────────────────
$selectedTeam = isset($_COOKIE['selected_team']) ? cleanName($_COOKIE['selected_team']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_team'])) {
    $team = cleanName(trim($_POST['team'] ?? ''));
    if ($team) {
        setcookie('selected_team', $team, ['expires'=>time()+365*24*3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?'));
        exit;
    }
}
if (isset($_GET['clear_team'])) {
    setcookie('selected_team', '', ['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    header('Location: /');
    exit;
}
if (isset($_GET['clear_cache'])) {
    if (file_exists($cacheFile)) unlink($cacheFile);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?'));
    exit;
}

// ── Helper HTTP ────────────────────────────────────────────────────
function curlGet(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html ?: '';
}

// ── Buteurs ────────────────────────────────────────────────────────
function getScorers(): array {
    global $sourceUrl, $cacheFile;
    $scorersFile = str_replace('matches_', 'scorers_', $cacheFile);
    if (file_exists($scorersFile) && (time() - filemtime($scorersFile)) < 1800) {
        $data = json_decode(file_get_contents($scorersFile), true);
        if (is_array($data)) return $data;
    }
    $html = curlGet($sourceUrl);
    if (!$html) return [];
    $teamIds = [];
    if (preg_match_all('/kpequipes\.php\?Equipe=(\d+)&(?:amp;)?Compet=([^&"]+)[^"]*"[^>]*title="Palmar/u', $html, $m)) {
        for ($i = 0; $i < count($m[0]); $i++) {
            $key = $m[1][$i].'_'.$m[2][$i];
            if (!isset($teamIds[$key])) $teamIds[$key] = ['id'=>$m[1][$i],'compet'=>$m[2][$i]];
        }
    }
    if (!$teamIds) return [];
    $all = [];
    foreach ($teamIds as $info) {
        $page = curlGet('https://www.kayak-polo.info/kpequipes.php?Equipe='.$info['id'].'&Compet='.$info['compet'].'&Css=');
        if (!$page) continue;
        $name = '';
        if (preg_match('/id="nomEquipe"[^>]*>([^<]+)</', $page, $nm)) $name = cleanName(trim($nm[1]));
        if (!preg_match('/id=[\'"]tableStats[\'"][^>]*>(.*?)<\/table>/s', $page, $tm)) continue;
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $tm[1], $rows);
        foreach ($rows[1] as $row) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cm);
            $cells = array_map(fn($c) => trim(preg_replace('/\s+/', ' ', strip_tags($c))), $cm[1]);
            if (count($cells) < 3) continue;
            $nom  = preg_replace('/\s+C\s*$/', '', trim($cells[1] ?? ''));
            $buts = (int)($cells[2] ?? 0);
            if (!$nom || $nom === 'Nom' || str_contains(mb_strtolower($nom), 'non disponible')) continue;
            $all[] = ['equipe' => $name ?: $info['id'], 'nom' => $nom, 'buts' => $buts];
        }
    }
    usort($all, fn($a,$b) => $b['buts'] <=> $a['buts']);
    foreach ($all as $i => &$s) $s['rang'] = $i + 1;
    if (!is_dir(dirname($scorersFile))) mkdir(dirname($scorersFile), 0775, true);
    file_put_contents($scorersFile, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $all;
}

// ── Scraping + Cache ───────────────────────────────────────────────
function getMatches(): array {
    global $sourceUrl, $cacheFile;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data) && count($data) > 0) return $data;
    }
    $ch = curl_init($sourceUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $html     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$html || $httpCode !== 200) {
        if (file_exists($cacheFile)) return json_decode(file_get_contents($cacheFile), true) ?: [];
        return [];
    }
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $tables = $dom->getElementsByTagName('table');
    if ($tables->length === 0) return [];
    $rows   = $tables->item(0)->getElementsByTagName('tr');
    $matches = [];
    $seen    = [];
    foreach ($rows as $row) {
        $cells = $row->getElementsByTagName('td');
        if ($cells->length < 8) continue;
        $cols = [];
        foreach ($cells as $cell) $cols[] = trim($cell->textContent);
        $num = $cols[0];
        if ($num === '' || $num === '#' || !is_numeric($num)) continue;
        if (isset($seen[$num])) continue;
        $seen[$num] = true;
        $joue  = (bool) preg_match('/(\d+)\s*-\s*(\d+)/', $cols[6] ?? '', $sm);
        $butsA = $joue ? (int)$sm[1] : null;
        $butsB = $joue ? (int)$sm[2] : null;
        $resA  = $resB = null;
        if ($joue) {
            if ($butsA > $butsB)     { $resA = 'V'; $resB = 'D'; }
            elseif ($butsA < $butsB) { $resA = 'D'; $resB = 'V'; }
            else                     { $resA = 'N'; $resB = 'N'; }
        }
        $detail   = end($cols);
        $dateStr  = $heureStr = $terrain = '';
        if (preg_match('/(\d{2}\/\d{2})\s+(\d{2}:\d{2})/', $detail, $dm)) {
            $dateStr  = $dm[1] . '/2026';
            $heureStr = $dm[2];
        }
        if (preg_match('/Terr\s*(\d+)/i', $detail, $tm)) $terrain = $tm[1];
        $matches[] = [
            'num'                => $num,
            'journee'            => detectJournee($dateStr),
            'date'               => $dateStr,
            'heure'              => $heureStr,
            'lieu'               => $cols[3] ?? '',
            'terrain'            => $terrain,
            'equipe_a'           => cleanName($cols[5] ?? ''),
            'equipe_b'           => cleanName($cols[7] ?? ''),
            'buts_a'             => $butsA,
            'buts_b'             => $butsB,
            'score'              => $joue ? "$butsA - $butsB" : null,
            'resultat_a'         => $resA,
            'resultat_b'         => $resB,
            'joue'               => $joue,
            'arbitre_principal'  => cleanName($cols[8] ?? ''),
            'arbitre_secondaire' => cleanName($cols[9] ?? ''),
        ];
    }
    if (!is_dir(dirname($cacheFile))) mkdir(dirname($cacheFile), 0775, true);
    file_put_contents($cacheFile, json_encode($matches, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $matches;
}

function cleanName(string $name): string {
    $name = preg_replace('/\s+I\s*$/', '', trim($name));
    return preg_replace('/\s{2,}/', ' ', trim($name));
}

function detectJournee(string $dateStr): string {
    global $JOURNEES;
    foreach ($JOURNEES as $nom => $info) {
        if (in_array($dateStr, $info['dates'], true)) return $nom;
    }
    return '?';
}

// ── Classement ────────────────────────────────────────────────────
function buildStandings(array $matches): array {
    $teams = [];
    foreach ($matches as $m) {
        if (!$m['joue']) continue;
        foreach ([$m['equipe_a'], $m['equipe_b']] as $t) {
            if (!isset($teams[$t])) {
                $teams[$t] = ['equipe'=>$t,'mj'=>0,'v'=>0,'n'=>0,'d'=>0,
                              'bp'=>0,'bc'=>0,'ga'=>0,'pts'=>0,'serie'=>[]];
            }
        }
        $a = $m['equipe_a']; $b = $m['equipe_b'];
        $ga = $m['buts_a'];  $gb = $m['buts_b'];
        $teams[$a]['mj']++; $teams[$b]['mj']++;
        $teams[$a]['bp'] += $ga; $teams[$a]['bc'] += $gb;
        $teams[$b]['bp'] += $gb; $teams[$b]['bc'] += $ga;
        if ($ga > $gb) {
            $teams[$a]['v']++; $teams[$a]['pts'] += 4; $teams[$a]['serie'][] = 'V';
            $teams[$b]['d']++; $teams[$b]['pts'] += 1; $teams[$b]['serie'][] = 'D';
        } elseif ($ga < $gb) {
            $teams[$b]['v']++; $teams[$b]['pts'] += 4; $teams[$b]['serie'][] = 'V';
            $teams[$a]['d']++; $teams[$a]['pts'] += 1; $teams[$a]['serie'][] = 'D';
        } else {
            $teams[$a]['n']++; $teams[$a]['pts'] += 2; $teams[$a]['serie'][] = 'N';
            $teams[$b]['n']++; $teams[$b]['pts'] += 2; $teams[$b]['serie'][] = 'N';
        }
    }
    // Ajouter les équipes sans matchs joués
    foreach (getAllTeams($matches) as $name) {
        if (!isset($teams[$name])) {
            $teams[$name] = ['equipe'=>$name,'mj'=>0,'v'=>0,'n'=>0,'d'=>0,
                             'bp'=>0,'bc'=>0,'ga'=>0,'pts'=>0,'serie'=>[]];
        }
    }
    foreach ($teams as &$t) {
        $t['ga']    = $t['bp'] - $t['bc'];
        $t['serie'] = array_slice($t['serie'], -5);
    }
    $s = array_values($teams);
    usort($s, fn($a,$b) => $b['pts']<=>$a['pts'] ?: $b['ga']<=>$a['ga'] ?: $b['bp']<=>$a['bp']);
    foreach ($s as $i => &$t) $t['rang'] = $i + 1;
    return $s;
}

function getAllTeams(array $matches): array {
    $t = [];
    foreach ($matches as $m) { $t[$m['equipe_a']] = true; $t[$m['equipe_b']] = true; }
    ksort($t);
    return array_keys($t);
}

// ── Prochains matchs ──────────────────────────────────────────────
function findNextMatch(array $matches, string $equipe = ''): ?array {
    $avenir = array_filter($matches, fn($m) => !$m['joue']);
    if ($equipe) {
        $q = mb_strtolower($equipe);
        $avenir = array_filter($avenir, fn($m) =>
            str_contains(mb_strtolower($m['equipe_a']), $q) ||
            str_contains(mb_strtolower($m['equipe_b']), $q)
        );
    }
    if (!$avenir) return null;
    usort($avenir, fn($a,$b) => matchTs($a)<=>matchTs($b));
    return reset($avenir);
}

function findNextArbitrage(array $matches, string $equipe, string $type = 'principal'): ?array {
    $q     = mb_strtolower($equipe);
    $field = $type === 'principal' ? 'arbitre_principal' : 'arbitre_secondaire';
    $avenir = array_filter($matches, fn($m) =>
        !$m['joue'] && str_contains(mb_strtolower($m[$field]), $q)
    );
    if (!$avenir) return null;
    usort($avenir, fn($a,$b) => matchTs($a)<=>matchTs($b));
    return reset($avenir);
}

function matchTs(array $m): int {
    if (!$m['date']) return PHP_INT_MAX;
    $d = DateTime::createFromFormat('d/m/Y', $m['date']);
    if (!$d) return PHP_INT_MAX;
    if ($m['heure']) {
        [$h, $i] = explode(':', $m['heure']);
        $d->setTime((int)$h, (int)$i);
    }
    return $d->getTimestamp();
}

// ── Simulation impact classement ──────────────────────────────────
function simulateImpact(array $matches, string $equipe, array $standings): ?array {
    $next = findNextMatch($matches, $equipe);
    if (!$next) return null;
    $currentRank = null;
    foreach ($standings as $t) {
        if ($t['equipe'] === $equipe) { $currentRank = $t['rang']; break; }
    }
    $q   = mb_strtolower($equipe);
    $isA = str_contains(mb_strtolower($next['equipe_a']), $q);
    $adv = $isA ? $next['equipe_b'] : $next['equipe_a'];
    $results = [];
    foreach (['win'=>[4,1], 'nul'=>[2,2], 'loss'=>[1,4]] as $sc => [$pMe,$pThem]) {
        $fake           = $next;
        $fake['joue']   = true;
        $fake['buts_a'] = $isA ? $pMe   : $pThem;
        $fake['buts_b'] = $isA ? $pThem : $pMe;
        $fake['score']  = $fake['buts_a'].' - '.$fake['buts_b'];
        $ba = $fake['buts_a']; $bb = $fake['buts_b'];
        $fake['resultat_a'] = $ba>$bb?'V':($ba<$bb?'D':'N');
        $fake['resultat_b'] = $bb>$ba?'V':($bb<$ba?'D':'N');
        $sim  = array_filter($matches, fn($m) => $m['num'] !== $next['num']);
        $sim[] = $fake;
        $simS  = buildStandings(array_values($sim));
        $rank  = null;
        foreach ($simS as $t) {
            if ($t['equipe'] === $equipe) { $rank = $t['rang']; break; }
        }
        $results[$sc] = $rank;
    }
    return ['current_rank'=>$currentRank, 'adversaire'=>$adv, 'next'=>$next, 'scenarios'=>$results];
}

// ── Stats équipe ──────────────────────────────────────────────────
function teamStats(array $matches, string $equipe): array {
    $q    = mb_strtolower($equipe);
    $mine = array_filter($matches, fn($m) =>
        str_contains(mb_strtolower($m['equipe_a']), $q) ||
        str_contains(mb_strtolower($m['equipe_b']), $q)
    );
    $s = ['mj'=>0,'v'=>0,'n'=>0,'d'=>0,'bp'=>0,'bc'=>0,'serie'=>[],'nom'=>$equipe,'matchs'=>[]];
    foreach ($mine as $m) {
        $s['matchs'][] = $m;
        if (!$m['joue']) continue;
        $isA = str_contains(mb_strtolower($m['equipe_a']), $q);
        $s['nom'] = $isA ? $m['equipe_a'] : $m['equipe_b'];
        $gp = $isA ? $m['buts_a'] : $m['buts_b'];
        $gc = $isA ? $m['buts_b'] : $m['buts_a'];
        $s['mj']++; $s['bp'] += $gp; $s['bc'] += $gc;
        if ($gp > $gc)      { $s['v']++; $s['serie'][] = 'V'; }
        elseif ($gp == $gc) { $s['n']++; $s['serie'][] = 'N'; }
        else                { $s['d']++; $s['serie'][] = 'D'; }
    }
    $s['ga']    = $s['bp'] - $s['bc'];
    $s['pts']   = $s['v']*4 + $s['n']*2 + $s['d']*1;
    $s['serie'] = array_slice($s['serie'], -5);
    return $s;
}

// ── Helpers d'affichage ───────────────────────────────────────────
function h(mixed $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmtDate(string $dateStr): string {
    if (!$dateStr) return 'Date inconnue';
    $d = DateTime::createFromFormat('d/m/Y', $dateStr);
    if (!$d) return $dateStr;
    $mois  = ['','janvier','février','mars','avril','mai','juin',
              'juillet','août','septembre','octobre','novembre','décembre'];
    $jours = ['lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche'];
    return ucfirst($jours[(int)$d->format('N')-1]).' '.$d->format('j').' '.$mois[(int)$d->format('n')];
}

function fmtRank(mixed $rank): string {
    if (!$rank) return '-';
    return $rank.($rank===1?'er':'e');
}

function countdown(string $dateStr, string $heureStr = ''): string {
    if (!$dateStr) return '';
    $d = DateTime::createFromFormat('d/m/Y', $dateStr);
    if (!$d) return '';
    if ($heureStr) {
        [$h, $m] = explode(':', $heureStr);
        $d->setTime((int)$h, (int)$m);
    } else {
        $d->setTime(23, 59);
    }
    $diff = (new DateTime())->diff($d);
    if ($diff->invert)    return 'Terminé';
    if ($diff->days === 0) return "Aujourd'hui";
    if ($diff->days === 1) return 'Demain';
    return 'Dans '.$diff->days.' jour'.($diff->days > 1 ? 's' : '');
}

function serieBadges(array $serie): string {
    $html = '';
    foreach ($serie as $r) {
        $c = $r==='V'?'badge-v':($r==='D'?'badge-d':'badge-n');
        $html .= "<span class=\"result-badge $c\">$r</span>";
    }
    return $html ?: '<span class="muted">-</span>';
}

function journeeLieu(string $journee): string {
    global $JOURNEES;
    return $JOURNEES[$journee]['lieu'] ?? '';
}

// ── Série de victoires ────────────────────────────────────────────
function winStreak(array $matches, string $equipe): int {
    $q      = mb_strtolower($equipe);
    $played = array_values(array_filter($matches, fn($m) => $m['joue'] && (
        str_contains(mb_strtolower($m['equipe_a']), $q) ||
        str_contains(mb_strtolower($m['equipe_b']), $q)
    )));
    usort($played, fn($a,$b) => matchTs($a) <=> matchTs($b));
    $streak = 0;
    for ($i = count($played)-1; $i >= 0; $i--) {
        $m   = $played[$i];
        $isA = str_contains(mb_strtolower($m['equipe_a']), $q);
        $res = $isA ? $m['resultat_a'] : $m['resultat_b'];
        if ($res === 'D') break;
        if ($res === 'V') $streak++;
    }
    return $streak;
}

// ── Données ────────────────────────────────────────────────────────
logVisit($selectedCompet ?? '', $selectedTeam ?? '');
$matches     = getMatches();
$allTeams    = getAllTeams($matches);
$standings   = buildStandings($matches);
$nextGlobal  = findNextMatch($matches);
$myNext      = $selectedTeam ? findNextMatch($matches, $selectedTeam)              : null;
$myArbiP     = $selectedTeam ? findNextArbitrage($matches, $selectedTeam, 'principal')   : null;
$myArbiS     = $selectedTeam ? findNextArbitrage($matches, $selectedTeam, 'secondaire')  : null;
$impact      = $selectedTeam ? simulateImpact($matches, $selectedTeam, $standings)  : null;
$myStats     = $selectedTeam ? teamStats($matches, $selectedTeam)                  : null;
$allScorers  = getScorers();

$totalMatchs = count($matches);
$totalJoues  = count(array_filter($matches, fn($m) => $m['joue']));
$totalAvenir = $totalMatchs - $totalJoues;
$jouesOnly   = array_filter($matches, fn($m) => $m['joue']);

$bestAttack = $bestDefense = $biggestGap = null;
if ($standings) {
    $bestAttack  = array_reduce($standings, fn($c,$t) => (!$c||$t['bp']>$c['bp'])?$t:$c);
    $bestDefense = array_reduce($standings, fn($c,$t) => (!$c||$t['bc']<$c['bc'])?$t:$c);
}
if ($jouesOnly) {
    $biggestGap = array_reduce($jouesOnly, function($c,$m) {
        $e = abs($m['buts_a']-$m['buts_b']);
        return (!$c || $e > abs($c['buts_a']-$c['buts_b'])) ? $m : $c;
    });
}

$myTeamName = $selectedTeam ?? '';
if ($selectedTeam && $standings) {
    $q = mb_strtolower($selectedTeam);
    foreach ($standings as $t) {
        if (str_contains(mb_strtolower($t['equipe']), $q)) { $myTeamName = $t['equipe']; break; }
    }
}

$streaks  = [];
foreach ($allTeams as $team) {
    $s = winStreak($matches, $team);
    if ($s > 0) $streaks[$team] = $s;
}
$maxStreak = $streaks ? max($streaks) : 0;
$hotTeams  = ($maxStreak >= 1) ? array_keys(array_filter($streaks, fn($s) => $s === $maxStreak)) : [];

$champDates = [];
foreach ($matches as $m) {
    if ($m['date']) $champDates[$m['date']] = true;
}
usort(array_keys($champDates), fn($a,$b) => matchTs(['date'=>$a,'heure'=>'']) <=> matchTs(['date'=>$b,'heure'=>'']));
$champDates = array_keys($champDates);
usort($champDates, fn($a,$b) => (DateTime::createFromFormat('d/m/Y',$a)?->getTimestamp()??0) <=> (DateTime::createFromFormat('d/m/Y',$b)?->getTimestamp()??0));

$today      = (new DateTime())->format('d/m/Y');
$defaultDay = in_array($today, $champDates) ? $today : null;
if (!$defaultDay) {
    foreach ($champDates as $d) {
        $dt = DateTime::createFromFormat('d/m/Y', $d);
        if ($dt && $dt->getTimestamp() >= strtotime('today')) { $defaultDay = $d; break; }
    }
}
if (!$defaultDay) $defaultDay = end($champDates) ?: $today;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kayak Polo Stats<?= $selectedTeam ? ' — '.h($myTeamName) : '' ?></title>
<meta name="description" content="Championnat de France <?= $selectedCompet === 'N15' ? 'U15' : 'U18' ?> 2026 — Classements, matchs et statistiques">
<link rel="icon" type="image/png" href="/kps.png">
<link rel="apple-touch-icon" href="/kps.png">
<style>
:root {
  --bg:          #f5f5f7;
  --bg2:         #ffffff;
  --bg3:         #f0f0f2;
  --text:        #1d1d1f;
  --text2:       #6e6e73;
  --text3:       #86868b;
  --border:      #d2d2d7;
  --accent:      #0071e3;
  --accent-dark: #0077ed;
  --green:       #28a745;
  --red:         #dc3545;
  --orange:      #fd7e14;
  --radius:      14px;
  --radius-sm:   10px;
  --shadow:      0 2px 12px rgba(0,0,0,.08);
  --shadow-md:   0 4px 20px rgba(0,0,0,.12);
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { font-size:16px; }
body {
  font-family: -apple-system, BlinkMacSystemFont, 'Helvetica Neue', Helvetica, Arial, sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  -webkit-font-smoothing: antialiased;
}
.selector-screen {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px 20px;
}
.selector-screen h1 {
  font-size: 2rem;
  font-weight: 700;
  letter-spacing: -.03em;
  text-align: center;
  margin-bottom: 8px;
}
.selector-screen p {
  color: var(--text2);
  text-align: center;
  margin-bottom: 36px;
  font-size: .95rem;
}
.team-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 10px;
  width: 100%;
  max-width: 620px;
}
.team-btn {
  background: var(--bg2);
  border: 1.5px solid var(--border);
  border-radius: var(--radius);
  padding: 16px 20px;
  text-align: left;
  cursor: pointer;
  font-size: .95rem;
  font-weight: 500;
  color: var(--text);
  font-family: inherit;
  transition: border-color .15s, box-shadow .15s;
  box-shadow: var(--shadow);
}
.team-btn:hover, .team-btn:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(0,113,227,.15);
  outline: none;
}
.selector-note {
  margin-top: 16px;
  font-size: .78rem;
  color: var(--text3);
  text-align: center;
}
.selector-info {
  margin-top: 28px;
  width: 100%;
  max-width: 620px;
  background: var(--bg3);
  border-radius: var(--radius);
  padding: 16px 20px;
  font-size: .82rem;
  color: var(--text2);
  line-height: 1.6;
  text-align: center;
  border: 1px solid var(--border);
}
.topbar {
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
  padding: 0 20px;
  position: sticky;
  top: 0;
  z-index: 100;
}
.topbar-inner {
  max-width: 720px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 52px;
}
.topbar-brand {
  font-size: .95rem;
  font-weight: 700;
  letter-spacing: -.02em;
  display: flex;
  align-items: center;
  gap: 8px;
}
.topbar-team {
  font-size: .82rem;
  color: var(--text2);
  display: flex;
  align-items: center;
  gap: 10px;
}
.topbar-team a {
  color: var(--accent);
  text-decoration: none;
  font-size: .78rem;
}
.tabs-wrap { background: var(--bg2); border-bottom: 1px solid var(--border); }
.tabs {
  max-width: 720px;
  margin: 0 auto;
  padding: 0 20px;
  display: flex;
}
.tab-btn {
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  padding: 12px 16px;
  font-size: .88rem;
  font-weight: 500;
  color: var(--text3);
  cursor: pointer;
  font-family: inherit;
  transition: color .15s;
  margin-bottom: -1px;
}
.tab-btn.active {
  color: var(--accent);
  border-bottom-color: var(--accent);
}
.main {
  max-width: 720px;
  margin: 0 auto;
  padding: 24px 20px 60px;
}
.tab-panel { display: none; }
.tab-panel.active { display: block; }
.card {
  background: var(--bg2);
  border-radius: var(--radius);
  padding: 20px;
  margin-bottom: 14px;
  box-shadow: var(--shadow);
}
.card-highlight {
  border: 1.5px solid rgba(0,113,227,.25);
  background: rgba(0,113,227,.03);
}
.card-label {
  font-size: .72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--text3);
  margin-bottom: 6px;
}
.card-title {
  font-size: 1.05rem;
  font-weight: 600;
  margin-bottom: 4px;
}
.card-sub {
  font-size: .85rem;
  color: var(--text2);
  margin-top: 2px;
}
.card-big {
  font-size: 2rem;
  font-weight: 700;
  letter-spacing: -.04em;
  line-height: 1;
  margin: 4px 0;
}
.card-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}
.vs {
  font-size: .88rem;
  color: var(--text2);
}
.info-row {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 10px;
}
.info-pill {
  background: var(--bg3);
  border-radius: 20px;
  padding: 4px 11px;
  font-size: .8rem;
  color: var(--text2);
  font-weight: 500;
}
.info-pill strong { color: var(--text); font-weight: 600; }
.no-data { color: var(--text3); font-size: .88rem; font-style: italic; }
.countdown-badge {
  background: var(--accent);
  color: #fff;
  font-size: .72rem;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 20px;
  white-space: nowrap;
  flex-shrink: 0;
}
.badge-provisoire {
  background: #fff3cd;
  color: #92400e;
  border: 1px solid #fcd34d;
  font-size: .68rem;
  font-weight: 700;
  padding: 2px 9px;
  border-radius: 20px;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.result-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px; height: 24px;
  border-radius: 6px;
  font-size: .75rem;
  font-weight: 700;
  margin-right: 4px;
}
.badge-v { background: #d1fae5; color: #065f46; }
.badge-d { background: #fee2e2; color: #991b1b; }
.badge-n { background: #fef9c3; color: #713f12; }
.standings-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .84rem;
}
.standings-table th {
  text-align: right;
  color: var(--text3);
  font-weight: 600;
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: .04em;
  padding: 6px 8px 10px;
  border-bottom: 1px solid var(--border);
}
.standings-table th:first-child,
.standings-table th:nth-child(2) { text-align: left; }
.standings-table td {
  text-align: right;
  padding: 10px 8px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.standings-table td:first-child { text-align: center; color: var(--text3); font-weight: 600; font-size:.8rem; }
.standings-table td:nth-child(2) { text-align: left; font-weight: 500; }
.standings-table tr:last-child td { border-bottom: none; }
.standings-table tr.my-team td { background: rgba(0,113,227,.06); }
.standings-table tr.my-team td:nth-child(2) { color: var(--accent); font-weight: 700; }
.pts-cell { font-weight: 700; }
.ga-pos { color: var(--green); }
.ga-neg { color: var(--red); }
.impact-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
  margin-top: 12px;
}
.impact-item {
  background: var(--bg3);
  border-radius: var(--radius-sm);
  padding: 12px;
  text-align: center;
}
.impact-label {
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--text3);
  font-weight: 600;
  margin-bottom: 4px;
}
.impact-rank { font-size: 1.4rem; font-weight: 700; letter-spacing: -.03em; }
.impact-rank.better { color: var(--green); }
.impact-rank.worse  { color: var(--red); }
.impact-rank.same   { color: var(--text2); }
.match-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 12px 0;
  border-bottom: 1px solid var(--border);
  font-size: .88rem;
}
.match-row:last-child { border-bottom: none; }
.match-teams { flex: 1; }
.match-teams .team-name { font-weight: 500; }
.match-teams .team-name.highlight { color: var(--accent); font-weight: 700; }
.match-vs { color: var(--text3); font-size: .78rem; margin: 2px 0; }
.match-score { font-weight: 700; font-size: .95rem; min-width: 50px; text-align: center; }
.match-score.win  { color: var(--green); }
.match-score.loss { color: var(--red); }
.match-score.draw { color: var(--orange); }
.match-meta { font-size: .75rem; color: var(--text3); min-width: 60px; text-align: right; }
.match-pending { color: var(--text3); font-size: .82rem; }
.stat-grid {
  display: grid;
  grid-template-columns: repeat(3,1fr);
  gap: 10px;
  margin-top: 8px;
}
.stat-box {
  background: var(--bg3);
  border-radius: var(--radius-sm);
  padding: 14px 12px;
  text-align: center;
}
.stat-box-val { font-size: 1.6rem; font-weight: 700; letter-spacing: -.03em; line-height: 1; }
.stat-box-lbl { font-size: .72rem; color: var(--text3); text-transform: uppercase; letter-spacing: .05em; margin-top: 4px; font-weight: 600; }
.section-title {
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--text3);
  margin: 24px 0 10px;
}
.notice {
  background: var(--bg3);
  border-radius: var(--radius);
  padding: 16px 18px;
  font-size: .84rem;
  color: var(--text2);
  line-height: 1.55;
  margin-bottom: 14px;
}
.notice strong { color: var(--text); }
.cal-day-sep {
  font-size: .75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--text3);
  padding: 10px 4px 6px;
  border-top: 1px solid var(--border);
  margin-top: 8px;
}
.cal-day-sep:first-child { border-top: none; margin-top: 0; padding-top: 0; }
.cal-journee { margin-bottom: 24px; }
.cal-journee-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 8px;
}
.cal-journee-title { font-size: 1rem; font-weight: 700; letter-spacing: -.02em; }
.cal-journee-meta { font-size: .78rem; color: var(--text3); margin-top: 2px; }
.cal-journee-badge {
  background: var(--bg3);
  border-radius: 20px;
  padding: 3px 10px;
  font-size: .75rem;
  font-weight: 600;
  color: var(--text3);
  white-space: nowrap;
}
.cal-match {
  background: var(--bg2);
  border-radius: var(--radius-sm);
  padding: 12px 14px;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: var(--shadow);
}
.cal-match-mine { border: 1.5px solid var(--accent); }
.cal-match-arbi-p { border: 1.5px solid #7c3aed; }
.cal-match-arbi-s { border: 1.5px solid #a78bfa; }
.cal-match-time {
  min-width: 44px;
  text-align: center;
  font-size: .82rem;
  font-weight: 700;
  line-height: 1.3;
}
.cal-terrain { font-size: .7rem; font-weight: 500; color: var(--text3); }
.cal-match-teams {
  flex: 1;
  font-size: .88rem;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
}
.cal-vs { color: var(--text3); font-size: .75rem; margin: 0 2px; }
.cal-my-team { font-weight: 700; color: var(--accent); }
.cal-tag {
  display: inline-block;
  font-size: .68rem;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 20px;
  margin-left: 4px;
}
.cal-tag-mine { background: var(--accent); color: #fff; }
.cal-tag-arbi-p { background: #7c3aed; color: #fff; }
.cal-tag-arbi-s { background: #a78bfa; color: #fff; }
.cal-match-score { min-width: 56px; text-align: right; }
.cal-score { font-weight: 700; font-size: .9rem; }
.cal-score.win  { color: var(--green); }
.cal-score.loss { color: var(--red); }
.cal-score.draw { color: var(--orange); }
.cal-score-pending { font-size: .78rem; color: var(--text3); font-weight: 500; }
.day-picker {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.day-btn {
  background: var(--bg2);
  border: 1.5px solid var(--border);
  border-radius: 20px;
  padding: 6px 14px;
  font-size: .82rem;
  font-weight: 600;
  color: var(--text2);
  cursor: pointer;
  font-family: inherit;
  transition: all .15s;
  white-space: nowrap;
}
.day-btn:hover { border-color: var(--accent); color: var(--accent); }
.day-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }
.day-btn.has-match { border-color: var(--accent); color: var(--accent); }
.day-btn.has-match.active { background: var(--accent); color: #fff; }
.day-section { display: none; }
.day-section.active { display: block; }
.match-type-badge {
  display: inline-block;
  font-size: .68rem;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 20px;
  margin-left: 6px;
  vertical-align: middle;
}
.badge-joue   { background: #d1fae5; color: #065f46; }
.badge-arbi-p { background: #ede9fe; color: #5b21b6; }
.badge-arbi-s { background: #f3e8ff; color: #7c3aed; }
.badge-avenir { background: var(--bg3); color: var(--text2); }
.day-empty { color: var(--text3); font-size: .88rem; font-style: italic; padding: 12px 0; }
.fire-card {
  border: 1.5px solid #f97316;
  background: rgba(249,115,22,.04);
}
.fire-label {
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: #f97316;
  margin-bottom: 10px;
}
.fire-team {
  font-size: .95rem;
  font-weight: 600;
  color: var(--text);
  margin-bottom: 2px;
}
.fire-sub {
  font-size: .82rem;
  color: var(--text2);
}
.fire-streak {
  font-size: 2rem;
  font-weight: 800;
  letter-spacing: -.04em;
  color: #f97316;
  line-height: 1;
}
/* ── Buteurs ─────────────────────────────────────────────────────── */
.scorers-section { margin-bottom: 28px; }
.scorers-title {
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text3);
  padding: 0 0 10px;
}
.scorers-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.scorers-table th {
  text-align: left;
  padding: 6px 8px;
  font-size: .72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--text3);
  border-bottom: 1px solid var(--border);
}
.scorers-table th:last-child,
.scorers-table td:last-child { text-align: right; }
.scorers-table th:first-child,
.scorers-table td:first-child { text-align: center; width: 38px; }
.scorers-table td {
  padding: 9px 8px;
  border-bottom: 1px solid var(--border);
  color: var(--text);
}
.scorers-table tr:last-child td { border-bottom: none; }
.scorers-table tr.me td { background: rgba(0,113,227,.06); font-weight: 600; }
.scorer-rank { color: var(--text3); font-size: .82rem; font-weight: 600; }
.scorer-rank.top3 { color: var(--accent); font-weight: 700; }
.scorer-buts { font-weight: 700; color: var(--text); }
.scorer-team { color: var(--text2); font-size: .8rem; }
.scorer-general-rank {
  display: inline-block;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 1px 7px;
  font-size: .75rem;
  color: var(--text2);
  font-weight: 600;
}
footer {
  text-align: center;
  padding: 32px 20px 24px;
  font-size: .75rem;
  color: var(--text3);
}
footer a { color: var(--text3); text-decoration: underline; }
.muted { color: var(--text3); }
#loader-overlay {
  position: fixed;
  inset: 0;
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  transition: opacity .4s ease;
}
#loader-overlay.fade-out { opacity: 0; pointer-events: none; }
.loader-spinner {
  width: 40px;
  height: 40px;
  border: 3px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin .8s linear infinite;
  margin-bottom: 16px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.loader-label {
  font-size: .82rem;
  color: var(--text2);
  font-weight: 500;
}
.mt8  { margin-top: 8px; }
.mt12 { margin-top: 12px; }
@media (max-width: 480px) {
  .card { padding: 16px; }
  .impact-grid { gap: 8px; }
  .impact-rank { font-size: 1.2rem; }
  .stat-grid { grid-template-columns: repeat(3,1fr); gap: 8px; }
  .team-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div id="loader-overlay">
  <img src="/kps.png" alt="KPS" style="width:60px;height:60px;border-radius:14px;margin-bottom:20px;box-shadow:0 4px 16px rgba(58,80,178,.2)">
  <div class="loader-spinner"></div>
  <div class="loader-label">Chargement...</div>
</div>

<?php if (!$selectedCompet): ?>
<div class="selector-screen">
  <img src="/kps.png" alt="KPS" style="width:80px;height:80px;border-radius:18px;margin-bottom:20px;box-shadow:0 4px 16px rgba(58,80,178,.2)">
  <h1>Kayak Polo Stats</h1>
  <p>Quelle compétition veux-tu suivre ?</p>
  <form method="post">
    <div class="team-grid" style="max-width:400px">
      <button type="submit" name="set_compet" value="1" class="team-btn"
              style="padding:22px 20px;font-size:1.1rem;font-weight:700"
              onclick="document.querySelector('[name=compet]').value='N18'">
        Championnat U18
      </button>
      <button type="submit" name="set_compet" value="1" class="team-btn"
              style="padding:22px 20px;font-size:1.1rem;font-weight:700"
              onclick="document.querySelector('[name=compet]').value='N15'">
        Championnat U15
      </button>
    </div>
    <input type="hidden" name="compet" value="">
  </form>
  <p class="selector-note">Ce choix est mémorisé sur cet appareil.</p>
  <div class="selector-info">
    Les données sont mises à jour toutes les 5 minutes depuis kayak-polo.info.
  </div>
</div>

<?php elseif (!$selectedTeam): ?>
<div class="selector-screen">
  <img src="/kps.png" alt="KPS" style="width:80px;height:80px;border-radius:18px;margin-bottom:20px;box-shadow:0 4px 16px rgba(58,80,178,.2)">
  <h1>Kayak Polo Stats</h1>
  <p>Sélectionne ton équipe pour voir ton tableau de bord personnalisé.</p>
  <form method="post">
    <div class="team-grid">
      <?php foreach ($allTeams as $team): ?>
        <button type="submit" name="set_team" value="1" class="team-btn"
                onclick="document.querySelector('[name=team]').value=<?= h(json_encode($team)) ?>">
          <?= h($team) ?>
        </button>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="team" value="">
  </form>
  <p class="selector-note">Ce choix est mémorisé sur cet appareil.</p>
  <div class="selector-info">
    Championnat de France <strong><?= $selectedCompet === 'N15' ? 'U15' : 'U18' ?></strong> 2026.<br>
    Les données sont mises à jour toutes les 5 minutes depuis kayak-polo.info.<br>
    <a href="?clear_compet=1" style="color:var(--accent)">Changer de compétition</a>
  </div>
</div>

<?php else: ?>
<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-brand">
      <img src="/kps.png" alt="KPS" style="width:28px;height:28px;border-radius:6px">
      Kayak Polo Stats
    </span>
    <span class="topbar-team">
      <span style="color:var(--text3);font-size:.75rem"><?= $selectedCompet === 'N15' ? 'U15' : 'U18' ?></span>
      <?= h($myTeamName) ?>
      <a href="?clear_compet=1">Changer</a>
    </span>
  </div>
</div>
<div class="tabs-wrap">
  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('infos',this)">Infos</button>
    <button class="tab-btn"       onclick="switchTab('matchs',this)">Matchs</button>
    <button class="tab-btn"       onclick="switchTab('stats',this)">Stats</button>
    <button class="tab-btn"       onclick="switchTab('calendrier',this)">Calendrier</button>
    <button class="tab-btn"       onclick="switchTab('buteurs',this)">Buteurs</button>
  </div>
</div>

<div class="main">

  <div id="tab-infos" class="tab-panel active">
    <?php
      $q      = mb_strtolower($selectedTeam);
      $myRank = null;
      foreach ($standings as $t) {
          if ($t['equipe'] === $myTeamName) { $myRank = $t; break; }
      }
    ?>

    <?php if ($myNext): ?>
    <?php
      $isMyA = str_contains(mb_strtolower($myNext['equipe_a']), $q);
      $myAdv = $isMyA ? $myNext['equipe_b'] : $myNext['equipe_a'];
      $cd    = countdown($myNext['date'], $myNext['heure']);
    ?>
    <div class="card card-highlight">
      <div style="display:flex;justify-content:space-between;align-items:flex-start">
        <div class="card-label">Mon prochain match</div>
        <?php if ($cd): ?><span class="countdown-badge"><?= h($cd) ?></span><?php endif; ?>
      </div>
      <div class="card-title" style="margin-top:6px"><?= h($myTeamName) ?> <span class="vs">vs</span> <?= h($myAdv) ?></div>
      <div class="info-row">
        <?php if ($myNext['date']): ?><span class="info-pill"><?= h(fmtDate($myNext['date'])) ?></span><?php endif; ?>
        <?php if ($myNext['heure']): ?><span class="info-pill"><strong><?= h($myNext['heure']) ?></strong></span><?php endif; ?>
        <?php if ($myNext['terrain']): ?><span class="info-pill">Terrain <?= h($myNext['terrain']) ?></span><?php endif; ?>
        <?php if ($myNext['journee']): ?><span class="info-pill"><?= h($myNext['journee']) ?> · <?= h(journeeLieu($myNext['journee'])) ?></span><?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-label">Mon prochain match</div>
      <p class="no-data mt8">Aucun match à venir pour ton équipe.</p>
    </div>
    <?php endif; ?>

    <?php if ($myRank): ?>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start">
        <div>
          <div class="card-label">Mon classement</div>
          <div class="card-big"><?= fmtRank($myRank['rang']) ?></div>
          <div class="card-sub"><?= $myRank['pts'] ?> pts &nbsp;·&nbsp; GA <?= ($myRank['ga']>=0?'+':'').$myRank['ga'] ?> &nbsp;·&nbsp; <?= $myRank['v'] ?>V <?= $myRank['n'] ?>N <?= $myRank['d'] ?>D</div>
        </div>
        <?php if ($myRank['serie']): ?>
        <div style="text-align:right">
          <div class="card-label">Série</div>
          <div style="margin-top:8px"><?= serieBadges($myRank['serie']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-label">Mon classement</div>
      <p class="no-data mt8">Pas encore de matchs joués.</p>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-label">Prochain arbitrage principal</div>
      <?php if ($myArbiP): ?>
        <?php $cdA = countdown($myArbiP['date'], $myArbiP['heure']); ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-top:4px">
          <div class="card-title" style="font-size:.95rem"><?= h($myArbiP['equipe_a']) ?> <span class="vs">vs</span> <?= h($myArbiP['equipe_b']) ?></div>
          <?php if ($cdA): ?><span class="countdown-badge"><?= h($cdA) ?></span><?php endif; ?>
        </div>
        <div class="info-row">
          <?php if ($myArbiP['date']): ?><span class="info-pill"><?= h(fmtDate($myArbiP['date'])) ?></span><?php endif; ?>
          <?php if ($myArbiP['heure']): ?><span class="info-pill"><strong><?= h($myArbiP['heure']) ?></strong></span><?php endif; ?>
          <?php if ($myArbiP['terrain']): ?><span class="info-pill">Terrain <?= h($myArbiP['terrain']) ?></span><?php endif; ?>
        </div>
      <?php else: ?><p class="no-data mt8">Aucun arbitrage principal à venir.</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-label">Prochain arbitrage secondaire</div>
      <?php if ($myArbiS): ?>
        <?php $cdB = countdown($myArbiS['date'], $myArbiS['heure']); ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-top:4px">
          <div class="card-title" style="font-size:.95rem"><?= h($myArbiS['equipe_a']) ?> <span class="vs">vs</span> <?= h($myArbiS['equipe_b']) ?></div>
          <?php if ($cdB): ?><span class="countdown-badge"><?= h($cdB) ?></span><?php endif; ?>
        </div>
        <div class="info-row">
          <?php if ($myArbiS['date']): ?><span class="info-pill"><?= h(fmtDate($myArbiS['date'])) ?></span><?php endif; ?>
          <?php if ($myArbiS['heure']): ?><span class="info-pill"><strong><?= h($myArbiS['heure']) ?></strong></span><?php endif; ?>
          <?php if ($myArbiS['terrain']): ?><span class="info-pill">Terrain <?= h($myArbiS['terrain']) ?></span><?php endif; ?>
        </div>
      <?php else: ?><p class="no-data mt8">Aucun arbitrage secondaire à venir.</p><?php endif; ?>
    </div>

    <div class="card" style="padding:0;overflow:hidden">
      <div style="padding:16px 20px 10px;display:flex;align-items:center;gap:8px">
        <div class="card-label" style="margin:0">Classement</div>
        <?php if ($totalAvenir > 0): ?><span class="badge-provisoire">Provisoire</span><?php endif; ?>
      </div>
      <?php if ($standings): ?>
      <table class="standings-table">
        <thead>
          <tr><th>#</th><th>Équipe</th><th>MJ</th><th>V</th><th>N</th><th>D</th><th>GA</th><th>PTS</th></tr>
        </thead>
        <tbody>
          <?php foreach ($standings as $t): ?>
          <tr <?= ($t['equipe']===$myTeamName)?'class="my-team"':'' ?>>
            <td><?= $t['rang'] ?></td>
            <td><?= h($t['equipe']) ?></td>
            <td><?= $t['mj'] ?></td>
            <td><?= $t['v'] ?></td>
            <td><?= $t['n'] ?></td>
            <td><?= $t['d'] ?></td>
            <td class="<?= $t['ga']>0?'ga-pos':($t['ga']<0?'ga-neg':'') ?>"><?= ($t['ga']>0?'+':'').$t['ga'] ?></td>
            <td class="pts-cell"><?= $t['pts'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php if ($impact): ?>
    <div class="card">
      <div class="card-label">Impact du prochain match</div>
      <div class="card-sub" style="margin:4px 0 12px">vs <?= h($impact['adversaire']) ?> — rang actuel : <strong><?= fmtRank($impact['current_rank'] ?? null) ?></strong></div>
      <?php $cur = $impact['current_rank'] ?? null; ?>
      <div class="impact-grid">
        <?php foreach (['win'=>'Victoire','nul'=>'Nul','loss'=>'Défaite'] as $key => $label): ?>
        <?php
          $r   = $impact['scenarios'][$key] ?? null;
          $cls = 'same';
          if ($r !== null && $cur !== null) {
              if ($r < $cur) $cls = 'better';
              elseif ($r > $cur) $cls = 'worse';
          }
        ?>
        <div class="impact-item">
          <div class="impact-label"><?= $label ?></div>
          <div class="impact-rank <?= $cls ?>"><?= $r ? fmtRank($r) : '-' ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <div id="tab-matchs" class="tab-panel">
    <?php
      $myQ = mb_strtolower($selectedTeam ?? '');
      $matchsByDay = [];
      foreach ($champDates as $d) $matchsByDay[$d] = [];
      foreach ($matches as $m) {
          if (!$m['date']) continue;
          $isMyMatch = $myQ && (str_contains(mb_strtolower($m['equipe_a']),$myQ) || str_contains(mb_strtolower($m['equipe_b']),$myQ));
          $isArbiP   = $myQ && str_contains(mb_strtolower($m['arbitre_principal']),$myQ);
          $isArbiS   = $myQ && !$isArbiP && str_contains(mb_strtolower($m['arbitre_secondaire']),$myQ);
          if ($isMyMatch || $isArbiP || $isArbiS) {
              $matchsByDay[$m['date']][] = ['match'=>$m,'type'=>$isMyMatch?'match':($isArbiP?'arbi_p':'arbi_s')];
          }
      }
    ?>

    <div class="day-picker">
      <?php foreach ($champDates as $d):
        $hasMatch = !empty($matchsByDay[$d]);
        $isActive = ($d === $defaultDay);
        $cls = 'day-btn'.($hasMatch?' has-match':'').($isActive?' active':'');
        $dt  = DateTime::createFromFormat('d/m/Y', $d);
        $lbl = $dt ? $dt->format('d/m') : substr($d,0,5);
      ?>
      <button class="<?= $cls ?>" onclick="switchDay('<?= h($d) ?>',this)"><?= h($lbl) ?></button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($champDates as $d):
      $isActive = ($d === $defaultDay);
      $dt = DateTime::createFromFormat('d/m/Y', $d);
      $dayLabel = $dt ? fmtDate($d) : $d;
    ?>
    <div class="day-section<?= $isActive?' active':'' ?>" data-day="<?= h($d) ?>">
      <div class="section-title" style="margin-top:0"><?= h($dayLabel) ?></div>
      <?php if (empty($matchsByDay[$d])): ?>
      <div class="card"><p class="day-empty">Aucun match ni arbitrage ce jour.</p></div>
      <?php else: ?>
      <?php
        $sorted = $matchsByDay[$d];
        usort($sorted, fn($a,$b) => matchTs($a['match']) <=> matchTs($b['match']));
      ?>
      <?php foreach ($sorted as $entry):
        $m    = $entry['match'];
        $type = $entry['type'];
        $isA  = str_contains(mb_strtolower($m['equipe_a']), $myQ);
        $gp   = $isA ? $m['buts_a'] : $m['buts_b'];
        $gc   = $isA ? $m['buts_b'] : $m['buts_a'];
        $scC  = '';
        if ($m['joue'] && $type==='match') $scC = $gp>$gc?'win':($gp<$gc?'loss':'draw');
      ?>
      <div class="card" style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
          <div style="flex:1">
            <?php if ($type === 'match'): ?>
              <div style="font-weight:600;font-size:.95rem">
                <span style="color:var(--accent)"><?= h($myTeamName) ?></span>
                <span class="vs"> vs </span>
                <span><?= h($isA ? $m['equipe_b'] : $m['equipe_a']) ?></span>
              </div>
              <span class="match-type-badge <?= $m['joue']?'badge-joue':'badge-avenir' ?>"><?= $m['joue']?'Joué':'À jouer' ?></span>
            <?php elseif ($type === 'arbi_p'): ?>
              <div style="font-weight:500;font-size:.9rem"><?= h($m['equipe_a']) ?> vs <?= h($m['equipe_b']) ?></div>
              <span class="match-type-badge badge-arbi-p">Arbitre principal</span>
            <?php else: ?>
              <div style="font-weight:500;font-size:.9rem"><?= h($m['equipe_a']) ?> vs <?= h($m['equipe_b']) ?></div>
              <span class="match-type-badge badge-arbi-s">Arbitre secondaire</span>
            <?php endif; ?>
            <div class="card-sub" style="margin-top:6px">
              <?= $m['heure'] ? h($m['heure']) : '—' ?>
              <?= $m['terrain'] ? ' · Terrain '.h($m['terrain']) : '' ?>
              <?= ($m['journee'] && $m['journee']!=='?') ? ' · '.h($m['journee']) : '' ?>
            </div>
          </div>
          <div style="text-align:right;flex-shrink:0">
            <?php if ($m['joue'] && $type==='match'): ?>
              <div class="match-score <?= $scC ?>" style="font-size:1.3rem"><?= $gp ?> – <?= $gc ?></div>
            <?php elseif ($m['joue']): ?>
              <div style="font-weight:600;font-size:1rem;color:var(--text2)"><?= h($m['score']) ?></div>
            <?php else: ?>
              <div style="color:var(--text3);font-size:.88rem">À venir</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div id="tab-stats" class="tab-panel">

    <?php if ($myStats && $myStats['mj'] > 0): ?>
    <div class="section-title">Statistiques — <?= h($myTeamName) ?></div>
    <div class="card">
      <div class="stat-grid">
        <div class="stat-box"><div class="stat-box-val"><?= $myStats['pts'] ?></div><div class="stat-box-lbl">Points</div></div>
        <div class="stat-box"><div class="stat-box-val"><?= $myStats['mj'] ?></div><div class="stat-box-lbl">Matchs</div></div>
        <div class="stat-box">
          <div class="stat-box-val <?= $myStats['ga']>0?'ga-pos':($myStats['ga']<0?'ga-neg':'') ?>"><?= ($myStats['ga']>0?'+':'').$myStats['ga'] ?></div>
          <div class="stat-box-lbl">Goal Avg</div>
        </div>
        <div class="stat-box"><div class="stat-box-val" style="color:var(--green)"><?= $myStats['v'] ?></div><div class="stat-box-lbl">Victoires</div></div>
        <div class="stat-box"><div class="stat-box-val" style="color:var(--orange)"><?= $myStats['n'] ?></div><div class="stat-box-lbl">Nuls</div></div>
        <div class="stat-box"><div class="stat-box-val" style="color:var(--red)"><?= $myStats['d'] ?></div><div class="stat-box-lbl">Défaites</div></div>
      </div>
      <div class="card-row mt12">
        <div><div class="card-label">Buts marqués</div><div style="font-size:1.2rem;font-weight:700"><?= $myStats['bp'] ?></div></div>
        <div><div class="card-label">Buts encaissés</div><div style="font-size:1.2rem;font-weight:700"><?= $myStats['bc'] ?></div></div>
        <div><div class="card-label">% Victoires</div><div style="font-size:1.2rem;font-weight:700"><?= $myStats['mj']>0?round($myStats['v']/$myStats['mj']*100).'%':'—' ?></div></div>
      </div>
    </div>
    <?php else: ?>
    <div class="card"><p class="no-data">Pas encore de matchs joués — les statistiques apparaîtront ici.</p></div>
    <?php endif; ?>

    <div class="section-title">Programme — <?= h($myTeamName) ?></div>
    <div class="card" style="padding:0 20px">
      <?php
        $myMatchList = $myStats['matchs'] ?? [];
        usort($myMatchList, fn($a,$b) => matchTs($a)<=>matchTs($b));
      ?>
      <?php foreach ($myMatchList as $m): ?>
      <?php
        $isA = str_contains(mb_strtolower($m['equipe_a']), $q);
        $adv = $isA ? $m['equipe_b'] : $m['equipe_a'];
        $gp  = $isA ? $m['buts_a']  : $m['buts_b'];
        $gc  = $isA ? $m['buts_b']  : $m['buts_a'];
        $scC = '';
        if ($m['joue']) $scC = $gp>$gc ? 'win' : ($gp<$gc ? 'loss' : 'draw');
      ?>
      <div class="match-row">
        <div class="match-teams">
          <div class="team-name highlight"><?= h($myTeamName) ?></div>
          <div class="match-vs">vs</div>
          <div class="team-name"><?= h($adv) ?></div>
        </div>
        <?php if ($m['joue']): ?>
        <div class="match-score <?= $scC ?>"><?= $gp ?> – <?= $gc ?></div>
        <?php else: ?>
        <div class="match-pending"><?= $m['heure'] ? h($m['heure']) : '—' ?></div>
        <?php endif; ?>
        <div class="match-meta">
          <?php if ($m['date']): ?><?= h($m['date']) ?><br><?php endif; ?>
          <?php if ($m['terrain']): ?>T.<?= h($m['terrain']) ?><?php endif; ?>
          <?php if ($m['journee'] && $m['journee'] !== '?'): ?><br><?= h($m['journee']) ?><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($myMatchList)): ?>
      <div style="padding:20px 0"><p class="no-data">Aucun match trouvé.</p></div>
      <?php endif; ?>
    </div>

    <?php if ($standings && count($standings) >= 2): ?>
    <div class="section-title">Performances du championnat</div>
    <div class="card">
      <?php if ($bestAttack): ?>
      <div class="card-row" style="margin-bottom:14px">
        <div><div class="card-label">Meilleure attaque</div><div style="font-weight:600"><?= h($bestAttack['equipe']) ?></div></div>
        <div style="text-align:right"><div class="card-big" style="font-size:1.5rem;color:var(--green)"><?= $bestAttack['bp'] ?></div><div class="card-sub">buts marqués</div></div>
      </div>
      <?php endif; ?>
      <?php if ($bestDefense): ?>
      <div class="card-row" style="border-top:1px solid var(--border);padding-top:14px;margin-bottom:14px">
        <div><div class="card-label">Meilleure défense</div><div style="font-weight:600"><?= h($bestDefense['equipe']) ?></div></div>
        <div style="text-align:right"><div class="card-big" style="font-size:1.5rem;color:var(--accent)"><?= $bestDefense['bc'] ?></div><div class="card-sub">buts encaissés</div></div>
      </div>
      <?php endif; ?>
      <?php if ($biggestGap): ?>
      <div style="border-top:1px solid var(--border);padding-top:14px">
        <div class="card-label">Plus grand écart</div>
        <div style="font-weight:500;margin-top:4px"><?= h($biggestGap['equipe_a']) ?> <?= h($biggestGap['score']) ?> <?= h($biggestGap['equipe_b']) ?></div>
        <div class="card-sub">Écart de <?= abs($biggestGap['buts_a']-$biggestGap['buts_b']) ?> buts</div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="section-title">Championnat de France <?= $selectedCompet === 'N15' ? 'U15' : 'U18' ?> 2026</div>
    <div class="card">
      <div class="stat-grid">
        <div class="stat-box"><div class="stat-box-val"><?= $totalJoues ?></div><div class="stat-box-lbl">Joués</div></div>
        <div class="stat-box"><div class="stat-box-val"><?= $totalAvenir ?></div><div class="stat-box-lbl">À venir</div></div>
        <div class="stat-box"><div class="stat-box-val"><?= array_sum(array_map(fn($m)=>$m['buts_a']+$m['buts_b'], array_values($jouesOnly))) ?></div><div class="stat-box-lbl">Buts</div></div>
      </div>
      <div class="card-sub mt12"><?= count($allTeams) ?> équipes · <?= $totalMatchs ?> matchs au programme</div>
    </div>

    <div class="notice">
      <strong>Championnat de France <?= $selectedCompet === 'N15' ? 'U15' : 'U18' ?> 2026.</strong><br>
      Les données sont mises à jour toutes les 5 minutes depuis kayak-polo.info.
    </div>
    <div style="text-align:right;margin-top:4px">
      <a href="?clear_cache=1" style="font-size:.75rem;color:var(--text3);text-decoration:none">Actualiser les données</a>
    </div>

    <?php if ($hotTeams): ?>
    <div class="section-title">🔥 En feu</div>
    <div class="card fire-card">
      <div class="fire-label">🔥 Série en cours — <?= $maxStreak ?> victoire<?= $maxStreak > 1 ? 's' : '' ?> sans défaite</div>
      <?php foreach ($hotTeams as $i => $team): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;<?= $i > 0 ? 'margin-top:12px;padding-top:12px;border-top:1px solid rgba(249,115,22,.2)' : '' ?>">
        <div>
          <div class="fire-team"><?= h($team) ?></div>
          <div class="fire-sub"><?= $maxStreak ?> victoire<?= $maxStreak > 1 ? 's' : '' ?> consécutive<?= $maxStreak > 1 ? 's' : '' ?></div>
        </div>
        <div class="fire-streak"><?= $maxStreak ?>V</div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>

  <div id="tab-calendrier" class="tab-panel">
    <?php
      $matchesByJournee = [];
      foreach ($matches as $m) {
          $j = $m['journee'] ?: '?';
          $matchesByJournee[$j][] = $m;
      }
      $journeeOrder = ['J1','J2','J3','Finales','?'];
      uksort($matchesByJournee, fn($a,$b) =>
          (array_search($a,$journeeOrder)??99) <=> (array_search($b,$journeeOrder)??99)
      );
    ?>
    <?php foreach ($matchesByJournee as $jNom => $jMatches): ?>
    <?php
      usort($jMatches, fn($a,$b) => matchTs($a)<=>matchTs($b));
      $jInfo      = $JOURNEES[$jNom] ?? null;
      $datesStr   = $jInfo ? implode(' & ', array_map(fn($d) => fmtDate($d), $jInfo['dates'])) : '';
      $lieuStr    = $jInfo['lieu'] ?? '';
      $jouesCount = count(array_filter($jMatches, fn($m) => $m['joue']));
    ?>
    <div class="cal-journee">
      <div class="cal-journee-header">
        <div>
          <div class="cal-journee-title"><?= h($jNom) ?></div>
          <?php if ($datesStr): ?><div class="cal-journee-meta"><?= h($datesStr) ?><?= $lieuStr ? ' · '.h($lieuStr) : '' ?></div><?php endif; ?>
        </div>
        <div class="cal-journee-badge"><?= $jouesCount ?>/<?= count($jMatches) ?></div>
      </div>
      <?php $currentDay = null; ?>
      <?php foreach ($jMatches as $m): ?>
      <?php
        if ($m['date'] && $m['date'] !== $currentDay) {
            $currentDay = $m['date'];
            echo '<div class="cal-day-sep">'.h(fmtDate($m['date'])).'</div>';
        }
      ?>
      <?php
        $isMine = $q && (
            str_contains(mb_strtolower($m['equipe_a']), $q) ||
            str_contains(mb_strtolower($m['equipe_b']), $q)
        );
        $isArbiP = $q && !$isMine && str_contains(mb_strtolower($m['arbitre_principal']), $q);
        $isArbiS = $q && !$isMine && !$isArbiP && str_contains(mb_strtolower($m['arbitre_secondaire']), $q);
        $cardClass = 'cal-match'.($isMine?' cal-match-mine':($isArbiP?' cal-match-arbi-p':($isArbiS?' cal-match-arbi-s':'')));
      ?>
      <div class="<?= $cardClass ?>">
        <div class="cal-match-time">
          <?= $m['heure'] ? h($m['heure']) : '--:--' ?>
          <?php if ($m['terrain']): ?><br><span class="cal-terrain">T<?= h($m['terrain']) ?></span><?php endif; ?>
        </div>
        <div class="cal-match-teams">
          <span class="<?= ($isMine && str_contains(mb_strtolower($m['equipe_a']), $q)) ? 'cal-my-team' : '' ?>"><?= h($m['equipe_a']) ?></span>
          <span class="cal-vs">vs</span>
          <span class="<?= ($isMine && str_contains(mb_strtolower($m['equipe_b']), $q)) ? 'cal-my-team' : '' ?>"><?= h($m['equipe_b']) ?></span>
          <?php if ($isMine): ?><span class="cal-tag cal-tag-mine">Mon match</span><?php endif; ?>
          <?php if ($isArbiP): ?><span class="cal-tag cal-tag-arbi-p">Arbitre principal</span><?php endif; ?>
          <?php if ($isArbiS): ?><span class="cal-tag cal-tag-arbi-s">Arbitre secondaire</span><?php endif; ?>
        </div>
        <div class="cal-match-score">
          <?php if ($m['joue']): ?>
          <?php
            $isA   = $q && str_contains(mb_strtolower($m['equipe_a']), $q);
            $myRes = $isMine ? ($isA ? $m['resultat_a'] : $m['resultat_b']) : null;
            $scCl  = $myRes==='V'?'win':($myRes==='D'?'loss':($myRes==='N'?'draw':''));
          ?>
          <span class="cal-score <?= $scCl ?>"><?= h($m['score']) ?></span>
          <?php else: ?>
          <span class="cal-score-pending">—</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php if (empty($matchesByJournee)): ?>
    <div class="card"><p class="no-data">Aucun match trouvé.</p></div>
    <?php endif; ?>
  </div>

  <div id="tab-buteurs" class="tab-panel">
    <?php
      $top20 = array_slice($allScorers, 0, 20);
      $myTeamScorers = [];
      if ($selectedTeam && $allScorers) {
          $q2 = mb_strtolower($myTeamName ?: $selectedTeam);
          foreach ($allScorers as $s) {
              if (str_contains(mb_strtolower($s['equipe']), $q2)) {
                  $myTeamScorers[] = $s;
              }
          }
      }
    ?>
    <?php if (!$allScorers): ?>
      <div class="card"><p class="no-data">Données buteurs non disponibles pour l'instant.</p></div>
    <?php else: ?>

    <?php if ($myTeamScorers): ?>
    <div class="card scorers-section">
      <div class="scorers-title">Buteurs — <?= h($myTeamName ?: $selectedTeam) ?></div>
      <table class="scorers-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Joueur</th>
            <th>Rang général</th>
            <th>Buts</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($myTeamScorers as $idx => $s): ?>
          <tr>
            <td class="scorer-rank <?= ($idx+1)<=3?'top3':'' ?>"><?= $idx+1 ?></td>
            <td><?= h($s['nom']) ?></td>
            <td><span class="scorer-general-rank"><?= $s['rang'] ?>e</span></td>
            <td class="scorer-buts"><?= $s['buts'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="card scorers-section">
      <div class="scorers-title">Top 20 — Classement général</div>
      <?php if (empty($top20)): ?>
        <p class="no-data">Aucune donnée disponible.</p>
      <?php else: ?>
      <table class="scorers-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Joueur</th>
            <th>Équipe</th>
            <th>Buts</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $myTeamQ = $selectedTeam ? mb_strtolower($myTeamName ?: $selectedTeam) : '';
          ?>
          <?php foreach ($top20 as $s): ?>
          <?php $isMe = $myTeamQ && str_contains(mb_strtolower($s['equipe']), $myTeamQ); ?>
          <tr <?= $isMe ? 'class="me"' : '' ?>>
            <td class="scorer-rank <?= $s['rang']<=3?'top3':'' ?>"><?= $s['rang'] ?></td>
            <td><?= h($s['nom']) ?></td>
            <td class="scorer-team"><?= h($s['equipe']) ?></td>
            <td class="scorer-buts"><?= $s['buts'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php endif; ?>
  </div>


</div>
<?php endif; ?>

<footer>
  Kayak Polo Stats &mdash; Made by <strong>Vidix</strong><br>
  Données non-officielles extraites de <a href="https://www.kayak-polo.info" target="_blank" rel="noopener">kayak-polo.info</a>
</footer>

<script>
function switchDay(day, btn) {
  document.querySelectorAll('.day-section').forEach(s => s.classList.remove('active'));
  btn.closest('.day-picker').querySelectorAll('.day-btn').forEach(b => b.classList.remove('active'));
  var sec = document.querySelector('.day-section[data-day="' + day + '"]');
  if (sec) sec.classList.add('active');
  btn.classList.add('active');
}
function switchTab(id, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
}
(function() {
  var delay = Math.random() * 1000;
  var overlay = document.getElementById('loader-overlay');
  setTimeout(function() {
    overlay.classList.add('fade-out');
    setTimeout(function() { overlay.style.display = 'none'; }, 400);
  }, delay);
})();
</script>
</body>
</html>
