<?php
// ═══════════════════════════════════════════════════════════════════
// KAYAK POLO STATS — index.php
// ═══════════════════════════════════════════════════════════════════

date_default_timezone_set('Europe/Paris');

define('CACHE_TTL',  300);
define('VISIT_LOG',  __DIR__ . '/logs/visits.log');
define('STATS_KEY',  'kps_vidix_2026');

// ── Compétitions disponibles ──────────────────────────────────────
// 'group' = paramètre Group= dans l'URL KPI
$COMPETITIONS = [
    'N1H' => ['label' => 'Nationale 1 Hommes',       'group' => 'N1H', 'cat' => 'Nationales'],
    'N1D' => ['label' => 'Nationale 1 Dames',         'group' => 'N1D', 'cat' => 'Nationales'],
    'N2H' => ['label' => 'Nationale 2 Hommes',        'group' => 'N2H', 'cat' => 'Nationales'],
    'N2D' => ['label' => 'Nationale 2 Dames',         'group' => 'N2D', 'cat' => 'Nationales'],
    'N3'  => ['label' => 'Nationale 3',               'group' => 'N3',  'cat' => 'Nationales'],
    'NEM' => ['label' => 'Nationale Excellence Mixte','group' => 'NEM', 'cat' => 'Nationales'],
    'N18' => ['label' => 'Championnat U18',           'group' => 'N18', 'cat' => 'Nationales Jeunes'],
    'N15' => ['label' => 'Championnat U15',           'group' => 'N15', 'cat' => 'Nationales Jeunes'],
    'REG17' => ['label' => 'Grand-Est',           'group' => 'REG17', 'cat' => 'Championnat régional'],
    'REG20' => ['label' => 'Normandie',           'group' => 'REG20', 'cat' => 'Championnat régional'],
    'REG18' => ['label' => 'AURA',           'group' => 'REG18', 'cat' => 'Championnat régional'],
    'REG07' => ['label' => 'Bretagne',           'group' => 'REG07', 'cat' => 'Championnat régional'],
    'REG14' => ['label' => 'Ile de France',           'group' => 'REG14', 'cat' => 'Championnat régional'],
    'REG04' => ['label' => 'Pays de la Loire',           'group' => 'REG04', 'cat' => 'Championnat régional'],
];

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
    foreach (array_reverse($days, true) as $d => $u) echo "$d  " . count($u) . " visiteur(s) unique(s)\n";
    echo "\n--- Par compétition ---\n";
    foreach ($compets as $c => $n) echo "$c : $n visite(s)\n";
    echo "\n--- 20 dernières visites ---\n";
    foreach (array_slice($lines, -20) as $l) echo $l . "\n";
    exit;
}

// ── Cookie compétition ────────────────────────────────────────────
global $COMPETITIONS;
$selectedCompet = isset($_COOKIE['selected_compet']) ? $_COOKIE['selected_compet'] : null;
if (!isset($COMPETITIONS[$selectedCompet])) $selectedCompet = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_compet'])) {
    $compet = $_POST['compet'] ?? '';
    if (isset($COMPETITIONS[$compet])) {
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

$competInfo  = $selectedCompet ? $COMPETITIONS[$selectedCompet] : null;
$groupCode   = $competInfo ? $competInfo['group'] : 'N18';
$competLabel = $competInfo ? $competInfo['label'] : '';

$sourceUrl = 'https://www.kayak-polo.info/kpmatchs.php?Compet=*&Group=' . urlencode($groupCode) . '&Saison=2026';
$cacheFile = __DIR__ . '/cache/matches_' . $selectedCompet . '.json';

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
    // Vider aussi le cache buteurs
    $scorersFile = str_replace('matches_', 'scorers_', $cacheFile);
    if (file_exists($scorersFile)) unlink($scorersFile);
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

// ── Détection automatique des journées ────────────────────────────
// Regroupe les dates par blocs consécutifs (écart ≤ 1 jour = même journée)
function buildJourneesFromMatches(array $matches): array {
    $dates = [];
    foreach ($matches as $m) {
        if (!$m['date']) continue;
        $dt = DateTime::createFromFormat('d/m/Y', $m['date']);
        if ($dt) $dates[$m['date']] = $dt->getTimestamp();
    }
    if (!$dates) return [];

    arsort($dates); // tri par timestamp
    $dates = array_keys(array_reverse($dates, true)); // plus ancienne en premier
    // tri chronologique
    usort($dates, fn($a,$b) => (DateTime::createFromFormat('d/m/Y',$a)?->getTimestamp()??0)
                             <=> (DateTime::createFromFormat('d/m/Y',$b)?->getTimestamp()??0));
    $dates = array_unique($dates);

    $journees = [];
    $jNum     = 1;
    $bloc     = [];
    $prevTs   = null;

    foreach ($dates as $d) {
        $ts = DateTime::createFromFormat('d/m/Y', $d)?->getTimestamp() ?? 0;
        if ($prevTs !== null && ($ts - $prevTs) > 86400 * 2) {
            // Nouvel écart > 2 jours → nouvelle journée
            $jKey = 'J' . $jNum;
            $journees[$jKey] = ['dates' => $bloc, 'lieu' => ''];
            $jNum++;
            $bloc = [];
        }
        $bloc[] = $d;
        $prevTs = $ts;
    }
    if ($bloc) {
        $jKey = 'J' . $jNum;
        $journees[$jKey] = ['dates' => $bloc, 'lieu' => ''];
    }
    return $journees;
}

function detectJourneeFromMap(string $dateStr, array $journees): string {
    foreach ($journees as $nom => $info) {
        if (in_array($dateStr, $info['dates'], true)) return $nom;
    }
    return '?';
}

// ── Buteurs ────────────────────────────────────────────────────────
function getScorers(): array {
    global $sourceUrl, $cacheFile;
    $scorersFile = str_replace('matches_', 'scorers_', $cacheFile);
    if (file_exists($scorersFile) && (time() - filemtime($scorersFile)) < 1500) {
        $data = json_decode(file_get_contents($scorersFile), true);
        if (is_array($data) && count($data) > 0) return $data;
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
            'journee'            => '', // sera calculé après
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
    // Construire les journées automatiquement puis les assigner
    $journees = buildJourneesFromMatches($matches);
    foreach ($matches as &$m) {
        $m['journee'] = detectJourneeFromMap($m['date'], $journees);
    }
    unset($m);

    if (!is_dir(dirname($cacheFile))) mkdir(dirname($cacheFile), 0775, true);
    file_put_contents($cacheFile, json_encode($matches, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $matches;
}

function cleanName(string $name): string {
    $name = preg_replace('/\s+I\s*$/', '', trim($name));
    return preg_replace('/\s{2,}/', ' ', trim($name));
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
    unset($t);
    $s = array_values($teams);
    usort($s, fn($a,$b) => $b['pts'] <=> $a['pts']);
    $result = [];
    $i = 0;
    while ($i < count($s)) {
        $j = $i;
        while ($j < count($s) && $s[$j]['pts'] === $s[$i]['pts']) $j++;
        $group = array_slice($s, $i, $j - $i);
        if (count($group) > 1) {
            $groupNames = array_column($group, 'equipe');
            $h2h = array_fill_keys($groupNames, ['pts'=>0,'bp'=>0,'bc'=>0]);
            foreach ($matches as $m) {
                if (!$m['joue']) continue;
                $a = $m['equipe_a']; $b = $m['equipe_b'];
                if (!in_array($a,$groupNames,true) || !in_array($b,$groupNames,true)) continue;
                $ga = $m['buts_a']; $gb = $m['buts_b'];
                $h2h[$a]['bp'] += $ga; $h2h[$a]['bc'] += $gb;
                $h2h[$b]['bp'] += $gb; $h2h[$b]['bc'] += $ga;
                if ($ga > $gb)      { $h2h[$a]['pts'] += 4; $h2h[$b]['pts'] += 1; }
                elseif ($ga < $gb)  { $h2h[$b]['pts'] += 4; $h2h[$a]['pts'] += 1; }
                else                { $h2h[$a]['pts'] += 2; $h2h[$b]['pts'] += 2; }
            }
            usort($group, function($a, $b) use ($h2h) {
                $aH = $h2h[$a['equipe']]; $bH = $h2h[$b['equipe']];
                if ($aH['pts'] !== $bH['pts']) return $bH['pts'] <=> $aH['pts'];
                $aHGa = $aH['bp'] - $aH['bc']; $bHGa = $bH['bp'] - $bH['bc'];
                if ($aHGa !== $bHGa) return $bHGa <=> $aHGa;
                if ($a['ga'] !== $b['ga']) return $b['ga'] <=> $a['ga'];
                return $b['bp'] <=> $a['bp'];
            });
        }
        foreach ($group as $t) $result[] = $t;
        $i = $j;
    }
    foreach ($result as $i => &$t) $t['rang'] = $i + 1;
    return $result;
}

function getAllTeams(array $matches): array {
    $t = [];
    foreach ($matches as $m) { $t[$m['equipe_a']] = true; $t[$m['equipe_b']] = true; }
    ksort($t);
    return array_keys($t);
}

// ── Prochains matchs (avec tolérance 45 min) ─────────────────────
function findNextMatch(array $matches, string $equipe = ''): ?array {
    $now    = time();
    $avenir = array_filter($matches, function($m) use ($now) {
        if ($m['joue']) return false;
        $ts = matchTs($m);
        // Si l'heure est connue et dépassée de plus de 45 min → considéré passé
        if ($ts !== PHP_INT_MAX && $ts < $now - 45 * 60) return false;
        return true;
    });
    if ($equipe) {
        $q = mb_strtolower($equipe);
        $avenir = array_filter($avenir, fn($m) =>
            str_contains(mb_strtolower($m['equipe_a']), $q) ||
            str_contains(mb_strtolower($m['equipe_b']), $q)
        );
    }
    if (!$avenir) return null;
    usort($avenir, fn($a,$b) => matchTs($a) <=> matchTs($b));
    return reset($avenir);
}

function findNextArbitrage(array $matches, string $equipe, string $type = 'principal'): ?array {
    $now   = time();
    $q     = mb_strtolower($equipe);
    $field = $type === 'principal' ? 'arbitre_principal' : 'arbitre_secondaire';
    $avenir = array_filter($matches, function($m) use ($now, $q, $field) {
        if ($m['joue']) return false;
        $ts = matchTs($m);
        if ($ts !== PHP_INT_MAX && $ts < $now - 45 * 60) return false;
        return str_contains(mb_strtolower($m[$field]), $q);
    });
    if (!$avenir) return null;
    usort($avenir, fn($a,$b) => matchTs($a) <=> matchTs($b));
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
$matches     = $selectedCompet ? getMatches() : [];
$JOURNEES    = $selectedCompet ? buildJourneesFromMatches($matches) : [];
$allTeams    = getAllTeams($matches);
$standings   = buildStandings($matches);
$myNext      = $selectedTeam ? findNextMatch($matches, $selectedTeam)                   : null;
$myArbiP     = $selectedTeam ? findNextArbitrage($matches, $selectedTeam, 'principal')  : null;
$myArbiS     = $selectedTeam ? findNextArbitrage($matches, $selectedTeam, 'secondaire') : null;
$impact      = $selectedTeam ? simulateImpact($matches, $selectedTeam, $standings)      : null;
$myStats     = $selectedTeam ? teamStats($matches, $selectedTeam)                       : null;
$allScorers  = $selectedTeam ? getScorers()                                             : [];

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

// ── Données PDF ────────────────────────────────────────────────────
$pdfJournee = null;
foreach ($JOURNEES as $jNom => $jInfo) {
    if (in_array($defaultDay, $jInfo['dates'], true)) { $pdfJournee = $jNom; break; }
}
if (!$pdfJournee) {
    foreach ($JOURNEES as $jNom => $jInfo) {
        foreach ($jInfo['dates'] as $d) {
            $dt = DateTime::createFromFormat('d/m/Y', $d);
            if ($dt && $dt->getTimestamp() >= strtotime('today')) { $pdfJournee = $jNom; break 2; }
        }
    }
}
if (!$pdfJournee && $JOURNEES) $pdfJournee = array_key_first($JOURNEES);
$pdfDates  = $pdfJournee ? ($JOURNEES[$pdfJournee]['dates'] ?? []) : [];
$pdfLieu   = $pdfJournee ? ($JOURNEES[$pdfJournee]['lieu']  ?? '') : '';
$pdfTeamQ  = $selectedTeam ? mb_strtolower($myTeamName ?: $selectedTeam) : '';
$pdfByDay  = [];
foreach ($pdfDates as $d) {
    $dayMs = [];
    foreach ($matches as $m) {
        if ($m['date'] !== $d) continue;
        $isMyMatch = $pdfTeamQ && (
            str_contains(mb_strtolower($m['equipe_a']), $pdfTeamQ) ||
            str_contains(mb_strtolower($m['equipe_b']), $pdfTeamQ)
        );
        $isArbiP = $pdfTeamQ && !$isMyMatch && str_contains(mb_strtolower($m['arbitre_principal']), $pdfTeamQ);
        $isArbiS = $pdfTeamQ && !$isMyMatch && !$isArbiP && str_contains(mb_strtolower($m['arbitre_secondaire']), $pdfTeamQ);
        if (!$isMyMatch && !$isArbiP && !$isArbiS) continue;
        $isA = $pdfTeamQ && str_contains(mb_strtolower($m['equipe_a']), $pdfTeamQ);
        $dayMs[] = [
            'heure'              => $m['heure'],
            'terrain'            => $m['terrain'],
            'equipe_a'           => $m['equipe_a'],
            'equipe_b'           => $m['equipe_b'],
            'score'              => $m['score'],
            'joue'               => $m['joue'],
            'resultat_a'         => $m['resultat_a'],
            'resultat_b'         => $m['resultat_b'],
            'arbitre_principal'  => $m['arbitre_principal'],
            'arbitre_secondaire' => $m['arbitre_secondaire'],
            'type'               => $isMyMatch ? 'match' : ($isArbiP ? 'arbi_p' : 'arbi_s'),
            'is_team_a'          => $isA,
        ];
    }
    usort($dayMs, fn($a,$b) => strcmp($a['heure'],$b['heure']));
    if ($dayMs) $pdfByDay[$d] = $dayMs;
}
$pdfData = [
    'compet'  => $competLabel ?: ($selectedCompet ?? ''),
    'journee' => $pdfJournee ?? '',
    'lieu'    => $pdfLieu,
    'dates'   => $pdfDates,
    'team'    => $myTeamName ?: ($selectedTeam ?? ''),
    'byDay'   => $pdfByDay,
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kayak Polo Stats<?= $selectedTeam ? ' — '.h($myTeamName) : '' ?></title>
<meta name="description" content="<?= h($competLabel) ?> 2026 — Classements, matchs et statistiques">
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
.selector-screen > p {
  color: var(--text2);
  text-align: center;
  margin-bottom: 28px;
  font-size: .95rem;
}
.compet-section {
  width: 100%;
  max-width: 640px;
  margin-bottom: 24px;
}
.compet-section-title {
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text3);
  margin-bottom: 10px;
  padding-left: 2px;
}
.compet-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 8px;
}
.compet-btn {
  background: var(--bg2);
  border: 1.5px solid var(--border);
  border-radius: var(--radius);
  padding: 14px 18px;
  text-align: left;
  cursor: pointer;
  font-size: .95rem;
  font-weight: 500;
  color: var(--text);
  font-family: inherit;
  transition: border-color .15s, box-shadow .15s;
  box-shadow: var(--shadow);
  width: 100%;
}
.compet-btn:hover, .compet-btn:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(0,113,227,.15);
  outline: none;
}
.compet-btn-disabled {
  background: var(--bg3);
  border-color: var(--border);
  color: var(--text3);
  cursor: not-allowed;
  opacity: .6;
  box-shadow: none;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.compet-soon {
  font-size: .68rem;
  font-weight: 700;
  background: var(--border);
  color: var(--text3);
  border-radius: 20px;
  padding: 2px 8px;
  white-space: nowrap;
  flex-shrink: 0;
}
.team-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 10px;
  width: 100%;
  max-width: 640px;
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
  margin-top: 20px;
  width: 100%;
  max-width: 640px;
  background: var(--bg3);
  border-radius: var(--radius);
  padding: 14px 18px;
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
.topbar-team a { color: var(--accent); text-decoration: none; font-size: .78rem; }
.tabs-wrap { background: var(--bg2); border-bottom: 1px solid var(--border); }
.tabs {
  max-width: 720px;
  margin: 0 auto;
  padding: 0 20px;
  display: flex;
  overflow-x: auto;
  scrollbar-width: none;
}
.tabs::-webkit-scrollbar { display: none; }
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
  white-space: nowrap;
}
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
.main { max-width: 720px; margin: 0 auto; padding: 24px 20px 60px; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }
.card { background: var(--bg2); border-radius: var(--radius); padding: 20px; margin-bottom: 14px; box-shadow: var(--shadow); }
.card-highlight { border: 1.5px solid rgba(0,113,227,.25); background: rgba(0,113,227,.03); }
.card-label { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--text3); margin-bottom: 6px; }
.card-title { font-size: 1.05rem; font-weight: 600; margin-bottom: 4px; }
.card-sub { font-size: .85rem; color: var(--text2); margin-top: 2px; }
.card-big { font-size: 2rem; font-weight: 700; letter-spacing: -.04em; line-height: 1; margin: 4px 0; }
.card-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.vs { font-size: .88rem; color: var(--text2); }
.info-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.info-pill { background: var(--bg3); border-radius: 20px; padding: 4px 11px; font-size: .8rem; color: var(--text2); font-weight: 500; }
.info-pill strong { color: var(--text); font-weight: 600; }
.no-data { color: var(--text3); font-size: .88rem; font-style: italic; }
.countdown-badge { background: var(--accent); color: #fff; font-size: .72rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; white-space: nowrap; flex-shrink: 0; }
.badge-provisoire { background: #fff3cd; color: #92400e; border: 1px solid #fcd34d; font-size: .68rem; font-weight: 700; padding: 2px 9px; border-radius: 20px; text-transform: uppercase; letter-spacing: .04em; }
.result-badge { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 6px; font-size: .75rem; font-weight: 700; margin-right: 4px; }
.badge-v { background: #d1fae5; color: #065f46; }
.badge-d { background: #fee2e2; color: #991b1b; }
.badge-n { background: #fef9c3; color: #713f12; }
.standings-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
.standings-table th { text-align: right; color: var(--text3); font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; padding: 6px 8px 10px; border-bottom: 1px solid var(--border); }
.standings-table th:first-child, .standings-table th:nth-child(2) { text-align: left; }
.standings-table td { text-align: right; padding: 10px 8px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.standings-table td:first-child { text-align: center; color: var(--text3); font-weight: 600; font-size:.8rem; }
.standings-table td:nth-child(2) { text-align: left; font-weight: 500; }
.standings-table tr:last-child td { border-bottom: none; }
.standings-table tr.my-team td { background: rgba(0,113,227,.06); }
.standings-table tr.my-team td:nth-child(2) { color: var(--accent); font-weight: 700; }
.pts-cell { font-weight: 700; }
.ga-pos { color: var(--green); }
.ga-neg { color: var(--red); }
.impact-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 12px; }
.impact-item { background: var(--bg3); border-radius: var(--radius-sm); padding: 12px; text-align: center; }
.impact-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text3); font-weight: 600; margin-bottom: 4px; }
.impact-rank { font-size: 1.4rem; font-weight: 700; letter-spacing: -.03em; }
.impact-rank.better { color: var(--green); }
.impact-rank.worse  { color: var(--red); }
.impact-rank.same   { color: var(--text2); }
.match-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 0; border-bottom: 1px solid var(--border); font-size: .88rem; }
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
.stat-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-top: 8px; }
.stat-box { background: var(--bg3); border-radius: var(--radius-sm); padding: 14px 12px; text-align: center; }
.stat-box-val { font-size: 1.6rem; font-weight: 700; letter-spacing: -.03em; line-height: 1; }
.stat-box-lbl { font-size: .72rem; color: var(--text3); text-transform: uppercase; letter-spacing: .05em; margin-top: 4px; font-weight: 600; }
.section-title { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text3); margin: 24px 0 10px; }
.notice { background: var(--bg3); border-radius: var(--radius); padding: 16px 18px; font-size: .84rem; color: var(--text2); line-height: 1.55; margin-bottom: 14px; }
.notice strong { color: var(--text); }
.cal-day-sep { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text3); padding: 10px 4px 6px; border-top: 1px solid var(--border); margin-top: 8px; }
.cal-day-sep:first-child { border-top: none; margin-top: 0; padding-top: 0; }
.cal-journee { margin-bottom: 24px; }
.cal-journee-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 8px; }
.cal-journee-title { font-size: 1rem; font-weight: 700; letter-spacing: -.02em; }
.cal-journee-meta { font-size: .78rem; color: var(--text3); margin-top: 2px; }
.cal-journee-badge { background: var(--bg3); border-radius: 20px; padding: 3px 10px; font-size: .75rem; font-weight: 600; color: var(--text3); white-space: nowrap; }
.cal-match { background: var(--bg2); border-radius: var(--radius-sm); padding: 12px 14px; margin-bottom: 6px; display: flex; align-items: center; gap: 12px; box-shadow: var(--shadow); }
.cal-match-mine    { border: 1.5px solid var(--accent); }
.cal-match-arbi-p  { border: 1.5px solid #7c3aed; }
.cal-match-arbi-s  { border: 1.5px solid #a78bfa; }
.cal-match-time { min-width: 44px; text-align: center; font-size: .82rem; font-weight: 700; line-height: 1.3; }
.cal-terrain { font-size: .7rem; font-weight: 500; color: var(--text3); }
.cal-match-teams { flex: 1; font-size: .88rem; display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
.cal-vs { color: var(--text3); font-size: .75rem; margin: 0 2px; }
.cal-my-team { font-weight: 700; color: var(--accent); }
.cal-tag { display: inline-block; font-size: .68rem; font-weight: 600; padding: 2px 7px; border-radius: 20px; margin-left: 4px; }
.cal-tag-mine   { background: var(--accent); color: #fff; }
.cal-tag-arbi-p { background: #7c3aed; color: #fff; }
.cal-tag-arbi-s { background: #a78bfa; color: #fff; }
.cal-match-score { min-width: 56px; text-align: right; }
.cal-score { font-weight: 700; font-size: .9rem; }
.cal-score.win  { color: var(--green); }
.cal-score.loss { color: var(--red); }
.cal-score.draw { color: var(--orange); }
.cal-score-pending { font-size: .78rem; color: var(--text3); font-weight: 500; }
.day-picker { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
.day-btn { background: var(--bg2); border: 1.5px solid var(--border); border-radius: 20px; padding: 6px 14px; font-size: .82rem; font-weight: 600; color: var(--text2); cursor: pointer; font-family: inherit; transition: all .15s; white-space: nowrap; }
.day-btn:hover { border-color: var(--accent); color: var(--accent); }
.day-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }
.day-btn.has-match { border-color: var(--accent); color: var(--accent); }
.day-btn.has-match.active { background: var(--accent); color: #fff; }
.day-section { display: none; }
.day-section.active { display: block; }
.match-type-badge { display: inline-block; font-size: .68rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; margin-left: 6px; vertical-align: middle; }
.badge-joue   { background: #d1fae5; color: #065f46; }
.badge-arbi-p { background: #ede9fe; color: #5b21b6; }
.badge-arbi-s { background: #f3e8ff; color: #7c3aed; }
.badge-avenir { background: var(--bg3); color: var(--text2); }
.day-empty { color: var(--text3); font-size: .88rem; font-style: italic; padding: 12px 0; }
.fire-card { border: 1.5px solid #f97316; background: rgba(249,115,22,.04); }
.fire-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #f97316; margin-bottom: 10px; }
.fire-team { font-size: .95rem; font-weight: 600; color: var(--text); margin-bottom: 2px; }
.fire-sub { font-size: .82rem; color: var(--text2); }
.fire-streak { font-size: 2rem; font-weight: 800; letter-spacing: -.04em; color: #f97316; line-height: 1; }
.scorers-section { margin-bottom: 28px; }
.scorers-title { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--text3); padding: 0 0 10px; }
.scorers-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.scorers-table th { text-align: left; padding: 6px 8px; font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--text3); border-bottom: 1px solid var(--border); }
.scorers-table th:last-child, .scorers-table td:last-child { text-align: right; }
.scorers-table th:first-child, .scorers-table td:first-child { text-align: center; width: 38px; }
.scorers-table td { padding: 9px 8px; border-bottom: 1px solid var(--border); color: var(--text); }
.scorers-table tr:last-child td { border-bottom: none; }
.scorers-table tr.me td { background: rgba(0,113,227,.06); font-weight: 600; }
.scorer-rank { color: var(--text3); font-size: .82rem; font-weight: 600; }
.scorer-rank.top3 { color: var(--accent); font-weight: 700; }
.scorer-buts { font-weight: 700; color: var(--text); }
.scorer-team { color: var(--text2); font-size: .8rem; }
.scorer-general-rank { display: inline-block; background: var(--bg3); border: 1px solid var(--border); border-radius: 6px; padding: 1px 7px; font-size: .75rem; color: var(--text2); font-weight: 600; }
footer { text-align: center; padding: 32px 20px 24px; font-size: .75rem; color: var(--text3); }
footer a { color: var(--text3); text-decoration: underline; }
.muted { color: var(--text3); }
#loader-overlay { position: fixed; inset: 0; background: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 9999; transition: opacity .4s ease; }
#loader-overlay.fade-out { opacity: 0; pointer-events: none; }
.loader-spinner { width: 40px; height: 40px; border: 3px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin .8s linear infinite; margin-bottom: 16px; }
@keyframes spin { to { transform: rotate(360deg); } }
.loader-label { font-size: .82rem; color: var(--text2); font-weight: 500; }
.mt8  { margin-top: 8px; }
.mt12 { margin-top: 12px; }
@media (max-width: 480px) {
  .card { padding: 16px; }
  .impact-grid { gap: 8px; }
  .impact-rank { font-size: 1.2rem; }
  .stat-grid { grid-template-columns: repeat(3,1fr); gap: 8px; }
  .team-grid { grid-template-columns: 1fr; }
  .compet-grid { grid-template-columns: 1fr 1fr; }
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
<?php
// ── Vérifie quelles compétitions ont des données ──────────────────
function hasData(string $key, array $info): bool {
    $cacheFile = __DIR__ . '/cache/matches_' . $key . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data) && count($data) > 0) return true;
    }
    $url  = 'https://www.kayak-polo.info/kpmatchs.php?Compet=*&Group=' . urlencode($info['group']) . '&Saison=2026';
    $html = curlGet($url);
    if (!$html) return false;
    return (bool) preg_match('/<td[^>]*>\s*\d+\s*<\/td>/', $html);
}
$competAvailability = [];
foreach ($COMPETITIONS as $key => $info) {
    $competAvailability[$key] = hasData($key, $info);
}
?>
<!-- ── Sélection de la compétition ─────────────────────────────── -->
<div class="selector-screen">
  <img src="/kps.png" alt="KPS" style="width:80px;height:80px;border-radius:18px;margin-bottom:20px;box-shadow:0 4px 16px rgba(58,80,178,.2)">
  <h1>Kayak Polo Stats</h1>
  <div style="display:inline-flex;align-items:center;gap:7px;background:#3f5ab6;color:#fff;border-radius:10px;padding:6px 14px;font-size:.82rem;font-weight:700;letter-spacing:.04em;margin-bottom:18px;">
    Kpi mais en mieux
  </div>
  <p>Quelle compétition veux-tu suivre ?</p>
  <form method="post">
    <?php
      $byCategory = [];
      foreach ($COMPETITIONS as $key => $info) {
          $byCategory[$info['cat']][$key] = $info;
      }
    ?>
    <?php foreach ($byCategory as $cat => $compets): ?>
    <div class="compet-section">
      <div class="compet-section-title"><?= h($cat) ?></div>
      <div class="compet-grid">
        <?php foreach ($compets as $key => $info):
          $available = $competAvailability[$key] ?? false;
        ?>
        <?php if ($available): ?>
        <button type="submit" name="set_compet" value="1" class="compet-btn"
                onclick="document.querySelector('[name=compet]').value=<?= h(json_encode($key)) ?>">
          <?= h($info['label']) ?>
        </button>
        <?php else: ?>
        <div class="compet-btn compet-btn-disabled" title="Aucune donnée disponible">
          <?= h($info['label']) ?>
          <span class="compet-soon">Bientôt</span>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <input type="hidden" name="compet" value="">
  </form>
  <p class="selector-note">Ce choix est mémorisé sur cet appareil.</p>
  <div class="selector-info">
    Une compétition manquante ? Dm insta :
    <a href="https://www.instagram.com/victor.dst3/" target="_blank" rel="noopener" style="color:var(--accent);font-weight:600;">victor.dst3</a>
  </div>
</div>

<?php elseif (!$selectedTeam): ?>
<!-- ── Sélection de l'équipe ───────────────────────────────────── -->
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
    <strong><?= h($competLabel) ?></strong> · Saison 2026<br>
    Une compétition manquante ? Dm insta : <a href="https://www.instagram.com/victor.dst3/" target="_blank" rel="noopener" style="color:var(--accent);font-weight:600;">victor.dst3</a><br>
    <a href="?clear_compet=1" style="color:var(--accent)">Changer de compétition</a>
  </div>
</div>

<?php else: ?>
<!-- ── App principale ─────────────────────────────────────────── -->
<div class="topbar">
  <div class="topbar-inner">
    <span class="topbar-brand">
      <img src="/kps.png" alt="KPS" style="width:28px;height:28px;border-radius:6px">
      Kayak Polo Stats
    </span>
    <span class="topbar-team">
      <span style="color:var(--text3);font-size:.75rem"><?= h($competLabel) ?></span>
      <?= h($myTeamName) ?>
      <a href="?clear_compet=1">Changer</a>
    </span>
  </div>
</div>
<div class="tabs-wrap">
  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('infos',this)">Infos</button>
    <button class="tab-btn"        onclick="switchTab('matchs',this)">Matchs</button>
    <button class="tab-btn"        onclick="switchTab('stats',this)">Stats</button>
    <button class="tab-btn"        onclick="switchTab('calendrier',this)">Calendrier</button>
    <button class="tab-btn"        onclick="switchTab('buteurs',this)">Buteurs</button>
  </div>
</div>

<div class="main">

  <!-- ════════════ TAB INFOS ════════════ -->
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
        <?php if ($myNext['journee'] && $myNext['journee'] !== '?'): ?><span class="info-pill"><?= h($myNext['journee']) ?></span><?php endif; ?>
        <?php if ($myNext['lieu']): ?><span class="info-pill"><?= h($myNext['lieu']) ?></span><?php endif; ?>
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

  <!-- ════════════ TAB MATCHS ════════════ -->
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

    <div style="margin-bottom:14px">
      <button id="pdf-btn" onclick="generatePDF()" style="display:inline-flex;align-items:center;gap:7px;background:#0f172a;color:#fff;border:none;border-radius:10px;padding:9px 16px;font-size:.85rem;font-weight:600;letter-spacing:-.01em;cursor:pointer;font-family:inherit;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Programme PDF
      </button>
    </div>

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
      $dayLabel = fmtDate($d);
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
              <?= $m['lieu'] ? ' · '.h($m['lieu']) : '' ?>
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

  <!-- ════════════ TAB STATS ════════════ -->
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

    <div class="section-title"><?= h($competLabel) ?> 2026</div>
    <div class="card">
      <div class="stat-grid">
        <div class="stat-box"><div class="stat-box-val"><?= $totalJoues ?></div><div class="stat-box-lbl">Joués</div></div>
        <div class="stat-box"><div class="stat-box-val"><?= $totalAvenir ?></div><div class="stat-box-lbl">À venir</div></div>
        <div class="stat-box"><div class="stat-box-val"><?= array_sum(array_map(fn($m)=>$m['buts_a']+$m['buts_b'], array_values($jouesOnly))) ?></div><div class="stat-box-lbl">Buts</div></div>
      </div>
      <div class="card-sub mt12"><?= count($allTeams) ?> équipes · <?= $totalMatchs ?> matchs au programme</div>
    </div>

    <div class="notice">
      <strong><?= h($competLabel) ?> 2026.</strong><br>
      Données mises à jour toutes les 5 minutes depuis kayak-polo.info.
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

  <!-- ════════════ TAB CALENDRIER ════════════ -->
  <div id="tab-calendrier" class="tab-panel">
    <?php
      $matchesByJournee = [];
      foreach ($matches as $m) {
          $j = $m['journee'] ?: '?';
          $matchesByJournee[$j][] = $m;
      }
      // Tri chronologique des journées
      uksort($matchesByJournee, function($a, $b) use ($matchesByJournee) {
          $tsA = PHP_INT_MAX; $tsB = PHP_INT_MAX;
          foreach ($matchesByJournee[$a] as $m) { $ts = matchTs($m); if ($ts < $tsA) $tsA = $ts; }
          foreach ($matchesByJournee[$b] as $m) { $ts = matchTs($m); if ($ts < $tsB) $tsB = $ts; }
          if ($a === '?') return 1;
          if ($b === '?') return -1;
          return $tsA <=> $tsB;
      });
    ?>
    <?php foreach ($matchesByJournee as $jNom => $jMatches): ?>
    <?php
      usort($jMatches, fn($a,$b) => matchTs($a)<=>matchTs($b));
      $jouesCount = count(array_filter($jMatches, fn($m) => $m['joue']));
      // Récupérer les dates uniques de cette journée
      $jDatesUniq = array_unique(array_filter(array_column($jMatches, 'date')));
      usort($jDatesUniq, fn($a,$b) => (DateTime::createFromFormat('d/m/Y',$a)?->getTimestamp()??0) <=> (DateTime::createFromFormat('d/m/Y',$b)?->getTimestamp()??0));
      $datesStr = implode(' & ', array_map(fn($d) => fmtDate($d), $jDatesUniq));
      // Lieu majoritaire
      $lieux = array_filter(array_column($jMatches, 'lieu'));
      $lieuCounts = array_count_values($lieux);
      arsort($lieuCounts);
      $lieuStr = $lieuCounts ? array_key_first($lieuCounts) : '';
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

  <!-- ════════════ TAB BUTEURS ════════════ -->
  <div id="tab-buteurs" class="tab-panel">
    <?php
      $top20 = array_slice($allScorers, 0, 20);
      $myTeamScorers = [];
      if ($selectedTeam && $allScorers) {
          $q2 = mb_strtolower($myTeamName ?: $selectedTeam);
          foreach ($allScorers as $s) {
              if (str_contains(mb_strtolower($s['equipe']), $q2)) $myTeamScorers[] = $s;
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
        <thead><tr><th>#</th><th>Joueur</th><th>Rang général</th><th>Buts</th></tr></thead>
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
        <thead><tr><th>#</th><th>Joueur</th><th>Équipe</th><th>Buts</th></tr></thead>
        <tbody>
          <?php $myTeamQ = $selectedTeam ? mb_strtolower($myTeamName ?: $selectedTeam) : ''; ?>
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
  Données non-officielles extraites de <a href="https://www.kayak-polo.info" target="_blank" rel="noopener">kayak-polo.info</a><br>
  <a href="https://www.instagram.com/victor.dst3/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:5px;margin-top:8px;color:var(--text3);text-decoration:none;font-size:.75rem;">
    <img src="/insta.png" alt="Instagram" style="width:16px;height:16px;border-radius:50%;opacity:.6;">
    @victor.dst3
  </a>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
window._pdfData = <?= json_encode($pdfData, JSON_UNESCAPED_UNICODE) ?>;

async function generatePDF() {
  const btn = document.getElementById('pdf-btn');
  const origHTML = btn.innerHTML;
  btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Génération...';
  btn.disabled = true;
  try {
    const d = window._pdfData;
    if (!d || !d.team) { alert('Sélectionne une équipe pour générer le programme.'); return; }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4', compress: true });
    const W=210, H=297, M=12, CW=186;
    let y = M;

    const navy=[15,23,42], blue=[0,100,200], white=[255,255,255];
    const t1=[15,23,42], t2=[71,85,105], t3=[148,163,184];
    const rowBg=[250,251,252], lineC=[218,222,228];
    const green=[34,139,60], red=[185,40,40], amber=[155,105,10];
    const fc=c=>doc.setFillColor(...c), tc=c=>doc.setTextColor(...c);
    const dc=c=>doc.setDrawColor(...c);
    const fw=(f,s)=>{doc.setFont('helvetica',f);doc.setFontSize(s);};

    let logoB64=null;
    try { const r=await fetch('/kps.png'); const b=await r.blob(); logoB64=await new Promise(res=>{const fr=new FileReader();fr.onload=()=>res(fr.result);fr.readAsDataURL(b);}); } catch(e){}

    const qrB64='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAIjAQMAAADr5InyAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAGUExURQAAAP///6XZn90AAAAJcEhZcwAADsMAAA7DAcdvqGQAAAMFSURBVHja7ZxBduIwDIbV1wVLjuCj9GjTo3EUjtAlCx6a4kSxnYTOpCRg5O/fNPCSz7IUP9VSgszoj17ShzfVr3igvQ7yf3oSJWBLIxS8CwXKOpR3zXW8UgZ99ud8pK8OCRh18kgJ2IJ3m6Zw72pbkf7qT94lynWIK6XLJGPKub/gLbflTkpNtvijEOktbcG7rcSopftF46HpmxL/7hfasoQiFVECtjRC8RdpVmMrM3ogZa+5fuuXf1JqssXfjPBu/RR/3hW8u/1dl2lmH6BFAWhRRWkJRSqyhRltOSN/fiFGSqRfONITxZ6wJZVL8c9/dzhWWIUi7iihIgozasUvrEYivcwvOqfuuaCyHzBOOUX2WYOi7mxRvLshxd+MWEdb2gLlhrpEYAli0hPWlEmMcmNncz9F3FGCO1ugKCuASDuPdKbYVRgqStZ+sPawaTe0H+6kKJQNvVsTRZkR64hIVxOj1CUWu9TeEQvp/QBJFDvMU447ilRECdjSiC2sI/wiT/BusUko28lRWVdBjZKN3A3njiLuKMQIyuMpgRlV75dQfrMrMknUABxnEtNpLYpgy4a24F0ijXe92RK1n80B41pQd2LUOXULxhUlKCtTjswIW1hHULaldLJLi57woKwfMLcngQIFChQor0gZakGpJ1w+G2rKLn1PeeYEBQoUKFAeTZE1bbEyf8e1d8Qy4PhhIBtDht3E/ZSjO4pCmaUQ6VYo/mLk0Rad0yU1GDKdxxWlZAuUOYpiCxTul0ps8UdZy7szSg+ODvpIlEPfjbZ600nmtZgSKqKIO0pwZ4u/SBMj/PIcSvakkI7Th/1WhGncWYYCBQoUKC9PsfNsCzFUlD77Sw8yUaRcptUtKFCgQIHyRMpEWU9YB/XNYyhQoECB4oei9mxo9oufhc7FADVRxB3Fn3e5X4g0fqlsRpnK3UTMFV0BqBhOZOgJy82K0gtTpCJKcGdLwLt4t2nv1hbpiYxyvH4o3w/Yj4bTnzrLCyk12RLwy4a21DQjKK3EyN+MVqFI+AshEB/go70HYQAAAABJRU5ErkJggg==';
    const instaB64='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAV4AAAFeCAMAAAD69YcoAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAyRpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuMy1jMDExIDY2LjE0NTY2MSwgMjAxMi8wMi8wNi0xNDo1NjoyNyAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvIiB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIgeG1sbnM6c3RSZWY9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZVJlZiMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENTNiAoTWFjaW50b3NoKSIgeG1wTU06SW5zdGFuY2VJRD0ieG1wLmlpZDo2QjZGRThGREM3OEExMUU3OUNDNEZCOEFBMTRCRUZBRiIgeG1wTU06RG9jdW1lbnRJRD0ieG1wLmRpZDo2QjZGRThGRUM3OEExMUU3OUNDNEZCOEFBMTRCRUZBRiI+IDx4bXBNTTpEZXJpdmVkRnJvbSBzdFJlZjppbnN0YW5jZUlEPSJ4bXAuaWlkOjZCNkZFOEZCQzc4QTExRTc5Q0M0RkI4QUExNEJFRkFGIiBzdFJlZjpkb2N1bWVudElEPSJ4bXAuZGlkOjZCNkZFOEZDQzc4QTExRTc5Q0M0RkI4QUExNEJFRkFGIi8+IDwvcmRmOkRlc2NyaXB0aW9uPiA8L3JkZjpSREY+IDwveDp4bXBtZXRhPiA8P3hwYWNrZXQgZW5kPSJyIj8+3H61bAAAAYBQTFRFx8bGu7q60M/P/v7+3t3dwcDAy8rKtLOz1NTUtbS0/f39vby8xsXFtrW1/Pz85+fn2dnZ+vr69vb2uLe3t7a2vLu78/Pzurm5v76+ubi4+Pj4+/v78fHxyMfH+fn58PDw9/f3vr29xcTEzs3NtLS0zczM6urq4+Li4eHhwsHBz87OwL+/8vLy7Ozs9PT07u7u4uLi2NfX7e3t3d3d9fX1ysnJ1tXV+/r63Nzc5OTk2djYt7e30tHR5ubm3Nvb397e6enp09PT0dDQ/v39ycjIw8LC4ODg19fX2trazMvL1dTU1tbW39/f7+/v6unp6+vr7Ovr5eXl6ejo7+7uxcXFxMPD09LSvb2919bW6Ojo4eDg6Ofn4+Pj5uXl4uHhz8/P5eTkysrKxMTE29ra6+rqw8PD2tnZ5OPj9fT08vHx3dzc1dXV4N/f29vb0tLSwMDAzc3N7ezs8O/v+Pf33t7ezs7O5+bmycnJy8vL2NjY1NPTyMjIzMzM////s7Ky////0Z1EKwAAAIB0Uk5T/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////wA4BUtnAAAYTElEQVR42uyd52MaredPHgaVjwBQbm2LALe69927HJcWJkzjt0n65JJd2aXeXHKt//XGSu3sAaWF3Z7QS5fviXlzwrvRBSKPRzMhGmuIoWxNBE28Tb1NNvE28DCmRmfTJ6Gk4vDoxMfElHN4YzaZn5pt4QerKhtfvbO3stsVVtmKB/81u7e+dpiNNvAYUmRqYc7uCqn7FB/v2J05STbxVZoH2xbmMXTWrtp391RdNvEzlVue6gypc8czkaVcTb7HGHv7SpmKqY2Ux18T7YwW7voWL9j/Em/dSDY43/XxX5ShPy6uxRsWrXL1lV/krfzTeeHiV/q1O1Sq1fR1vKLwn3oRqrQKTIw2CN7nWoYrQ7utI3eNVzmc9qij5ekbrGm/yoE0Vq471+XrFm/3cq4pX8NZYHeJVwt2qLPrjap3h9Q90qDJpd1WpH7whp12VTfkJpT7whpwJVUbdtgIwb7z+T3LC/QH4V6W28SoTbarMGr5Xy3ivDKu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';

    // Header
    const hH=18;
    fc(navy); doc.roundedRect(M,y,CW,hH,3,3,'F');
    if(logoB64){ doc.addImage(logoB64,'PNG',M+4,y+3,12,12); }
    else { fc(blue); doc.roundedRect(M+4,y+3,12,12,2,2,'F'); fw('bold',7);tc(white);doc.text('KP',M+10,y+10.5,{align:'center'}); }
    fw('bold',11); tc(white);
    doc.text(d.compet+' 2026', M+20, y+8);
    fw('normal',7); tc(t3);
    doc.text('KAYAK POLO STATS', M+20, y+14);
    fw('bold',8.5); tc([210,220,235]);
    doc.text(d.journee, W-M-3, y+8, {align:'right'});
    fw('normal',6.5); tc(t3);
    doc.text(d.dates.map(x=>x.substring(0,5)).join(' & '), W-M-3, y+14, {align:'right'});
    y += hH+6;

    fw('bold',12); tc(t1); doc.text(d.team, M, y);
    y += 9;

    const rH=13, dayGap=4, dayHeaderH=10;
    for(const [dateStr,dayMs] of Object.entries(d.byDay)){
      if(!dayMs.length) continue;
      const p=dateStr.split('/');
      const dt2=new Date(+p[2],+p[1]-1,+p[0]);
      const dn=['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'][dt2.getDay()];
      const mn=['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'][dt2.getMonth()];
      const dayLabel=dn+' '+p[0]+' '+mn;
      dc(navy); doc.setLineWidth(0.5);
      doc.line(M, y+dayHeaderH-1, W-M, y+dayHeaderH-1);
      fw('bold',9); tc(navy); doc.text(dayLabel.toUpperCase(), M, y+7);
      fw('normal',7); tc(t3); doc.text(dayMs.length+' match'+(dayMs.length>1?'s':''), W-M-3, y+7, {align:'right'});
      y += dayHeaderH+2;
      for(const m of dayMs){
        fc(rowBg); dc(lineC); doc.setLineWidth(0.15);
        doc.roundedRect(M, y, CW, rH, 1.5, 1.5, 'FD');
        const barC=m.type==='match'?blue:(m.type==='arbi_p'?t2:t3);
        fc(barC); doc.rect(M, y, 3, rH, 'F');
        fw('bold',8.5); tc(t1); doc.text(m.heure||'--:--', M+5, y+5.5);
        if(m.terrain){ fw('bold',6); tc(t3); doc.text('T'+m.terrain, M+5, y+10.5); }
        const tx=M+22;
        if(m.type==='match'){
          fw('bold',8.5);
          doc.setFont('helvetica',m.is_team_a?'bold':'normal'); tc(t1);
          const wA=doc.getTextWidth(m.equipe_a); doc.text(m.equipe_a,tx,y+5.5);
          doc.setFontSize(7.5); doc.setFont('helvetica','normal'); tc(t3);
          const wV=doc.getTextWidth(' vs '); doc.text(' vs ',tx+wA,y+5.5);
          doc.setFontSize(8.5); doc.setFont('helvetica',m.is_team_a?'normal':'bold'); tc(t1);
          doc.text(m.equipe_b, tx+wA+wV, y+5.5);
        } else {
          fw('normal',8.5); tc(t1);
          doc.text(m.equipe_a+' vs '+m.equipe_b, tx, y+5.5);
        }
        const arbi=[];
        if(m.arbitre_principal) arbi.push('P: '+m.arbitre_principal);
        if(m.arbitre_secondaire) arbi.push('S: '+m.arbitre_secondaire);
        if(arbi.length){ fw('normal',6); tc(t3); doc.text(arbi.join('   '), tx, y+10.5); }
        if(m.joue&&m.score){
          let sc=t1; if(m.type==='match'){const r=m.is_team_a?m.resultat_a:m.resultat_b;sc=r==='V'?green:(r==='D'?red:amber);}
          fw('bold',11); tc(sc); doc.text(m.score, W-M-3, y+7.5, {align:'right'});
        } else if(!m.joue&&m.type!=='match'){
          fw('bold',6); tc(t2); doc.text(m.type==='arbi_p'?'ARBI PRINCIPAL':'ARBI SECOND.', W-M-3, y+7.5, {align:'right'});
        } else {
          fw('normal',7); tc(t3); doc.text('À jouer', W-M-3, y+7, {align:'right'});
        }
        y += rH+2;
      }
      y += dayGap;
    }
    if(!Object.keys(d.byDay).length){
      fw('normal',10); tc(t2); doc.text('Aucun match trouvé.', M, y+10); y+=20;
    }

    // Footer
    const qrS=18, instaS=14, footerY=H-M-qrS;
    dc(lineC); doc.setLineWidth(0.3); doc.line(M, footerY-5, W-M, footerY-5);
    doc.addImage(qrB64,'PNG', W-M-qrS, footerY, qrS, qrS);
    const instaY=footerY+(qrS-instaS)/2;
    doc.addImage(instaB64,'PNG', M, instaY, instaS, instaS);
    doc.link(M, instaY, instaS, instaS, {url:'https://www.instagram.com/victor.dst3/'});
    fw('normal',6.5); tc(t3);
    const cx=M+instaS+5;
    doc.text('kp-stats.duckdns.org', cx, footerY+5);
    doc.text('Made by Vidix', cx, footerY+10);
    doc.text('Données non-officielles · kayak-polo.info', cx, footerY+15);
    const now=new Date();
    fw('normal',5.5); tc(t3);
    doc.text('Généré le '+now.toLocaleDateString('fr-FR')+' à '+now.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}), cx, footerY+qrS);

    const slug=d.team.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-zA-Z0-9]+/g,'-').toLowerCase();
    const jSlug=(d.journee||'prog').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-zA-Z0-9]+/g,'-').toLowerCase();
    doc.save('kps-'+slug+'-'+jSlug+'.pdf');

  } catch(e) {
    console.error(e); alert('Erreur PDF : '+e.message);
  } finally {
    btn.innerHTML=origHTML; btn.disabled=false;
  }
}

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
