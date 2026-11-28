<?php
// Правила шахмат для партий чат-менеджера (api/lib/cm/chess.php) — только
// логика, без базы и Telegram. Сервер — единственный судья: мини-приложение
// получает от него позицию и список разрешённых ходов, само правил не знает.
//
// Позиция — массив: b — 64 клетки (индекс = горизонталь * 8 + вертикаль,
// a1 = 0, h1 = 7, a8 = 56), в клетке '' или буква фигуры как в FEN (белые
// заглавными: P N B R Q K); t — чей ход 'w' | 'b'; c — права рокировки
// подстрокой 'KQkq' ('' — никаких); ep — клетка взятия на проходе или -1;
// h — полуходы без взятия и хода пешкой (правило 50 ходов); f — номер хода.
// Ходы — в UCI: e2e4, превращение — e7e8q, рокировка — ход короля e1g1.
//
// Chess960 (шахматы Фишера, просьба владельца 28.11.2026): x — true, права
// рокировки в c — буквами вертикалей ладей, как в Shredder-FEN (HAha: белые
// заглавными, у каждой стороны сначала дальняя по алфавиту). Рокировка —
// ход короля на свою ладью (e1h1, как UCI_Chess960): в 960 король может
// стоять на g1 и рядом с ладьёй, и «два поля» не отличить от простого хода.
// Король встаёт на g/c, ладья на f/d; между ними всё пусто, кроме самих
// короля и ладьи; король не под шахом и не проходит через битое поле.
// Обычная позиция — та же с x = false: старые партии читаются как раньше.

const CHESS_START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
const CHESS960_KNIGHTS = [[0, 1], [0, 2], [0, 3], [0, 4], [1, 2], [1, 3], [1, 4], [2, 3], [2, 4], [3, 4]];

const CHESS_KNIGHT = [[1, 2], [2, 1], [2, -1], [1, -2], [-1, -2], [-2, -1], [-2, 1], [-1, 2]];
const CHESS_KING   = [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]];
const CHESS_DIAG   = [[1, 1], [1, -1], [-1, 1], [-1, -1]];
const CHESS_ORTH   = [[1, 0], [-1, 0], [0, 1], [0, -1]];

// 'e4' → 28, неверное — -1.
function chess_sq(string $s): int {
    if (!preg_match('/^[a-h][1-8]\z/', $s)) { return -1; }
    return (ord($s[1]) - 49) * 8 + (ord($s[0]) - 97);
}

function chess_sq_name(int $i): string {
    return chr(97 + $i % 8) . chr(49 + intdiv($i, 8));
}

// 'w', 'b' или '' для пустой клетки.
function chess_color(string $pc): string {
    if ($pc === '') { return ''; }
    return ctype_upper($pc) ? 'w' : 'b';
}

function chess_other(string $c): string {
    return $c === 'w' ? 'b' : 'w';
}

// Клетка со сдвигом (df по вертикали, dr по горизонтали) или -1 за краем доски.
function chess_step(int $sq, int $df, int $dr): int {
    $f = $sq % 8 + $df;
    $r = intdiv($sq, 8) + $dr;
    return ($f < 0 || $f > 7 || $r < 0 || $r > 7) ? -1 : $r * 8 + $f;
}

// $c960 — читать права как X-FEN: KQkq — крайняя ладья со своей стороны от
// короля. Буквы вертикалей (HAha) включают 960 и без него.
function chess_from_fen(string $fen, bool $c960 = false): ?array {
    $p = preg_split('/\s+/', trim($fen));
    if (count($p) !== 6) { return null; }
    $rows = explode('/', $p[0]);
    if (count($rows) !== 8) { return null; }
    $b = array_fill(0, 64, '');
    foreach ($rows as $i => $row) {
        $r = 7 - $i;
        $f = 0;
        foreach (str_split($row) as $ch) {
            if ($ch >= '1' && $ch <= '8') {
                $f += (int)$ch;
            } elseif (strpos('pnbrqkPNBRQK', $ch) !== false) {
                if ($f > 7) { return null; }
                $b[$r * 8 + $f] = $ch;
                $f++;
            } else {
                return null;
            }
        }
        if ($f !== 8) { return null; }
    }
    if ($p[1] !== 'w' && $p[1] !== 'b') { return null; }
    $x = $c960 || preg_match('/[A-Ha-h]/', $p[2]) === 1;
    if (!preg_match($x ? '/^(?:-|[KQA-H]{1,2}[kqa-h]{0,2}|[kqa-h]{1,2})\z/' : '/^(?:-|K?Q?k?q?)\z/', $p[2]) || $p[2] === '') { return null; }
    $ep = $p[3] === '-' ? -1 : chess_sq($p[3]);
    if ($p[3] !== '-' && $ep < 0) { return null; }
    if (!ctype_digit($p[4]) || !ctype_digit($p[5])) { return null; }
    if (count(array_keys($b, 'K', true)) !== 1 || count(array_keys($b, 'k', true)) !== 1) { return null; }
    $c = $p[2] === '-' ? '' : $p[2];
    if ($x) {
        $c = chess_x_rights($b, $c);
        if ($c === null) { return null; }
    }
    return ['b' => $b, 't' => $p[1], 'c' => $c, 'ep' => $ep, 'h' => (int)$p[4], 'f' => max(1, (int)$p[5]), 'x' => $x];
}

// Права 960 в один вид: буквы вертикалей ладей, белые заглавными, у каждой
// стороны по убыванию (HAha). K / Q из X-FEN — крайняя ладья справа / слева
// от короля; если её нет, права нет. null — больше двух прав у стороны.
function chess_x_rights(array $b, string $c): ?string {
    $have = ['w' => [], 'b' => []];
    foreach ($c === '' ? [] : str_split($c) as $ch) {
        $side = ctype_upper($ch) ? 'w' : 'b';
        $row = $side === 'w' ? 0 : 56;
        $u = strtoupper($ch);
        $file = -1;
        if ($u === 'K' || $u === 'Q') {
            $k = chess_king_sq($b, $side) - $row;
            $rook = $side === 'w' ? 'R' : 'r';
            for ($f = 0; $f < 8 && $k >= 0 && $k < 8; $f++) {
                if ($b[$row + $f] === $rook && ($u === 'K' ? $f > $k : $f < $k) && ($u === 'K' || $file < 0)) { $file = $f; }
            }
            if ($file < 0) { continue; }
        } else {
            $file = ord($u) - 65;
        }
        $have[$side][$file] = true;
    }
    $out = '';
    foreach (['w' => 65, 'b' => 97] as $side => $base) {
        $files = array_keys($have[$side]);
        if (count($files) > 2) { return null; }
        rsort($files);
        foreach ($files as $f) { $out .= chr($base + $f); }
    }
    return $out;
}

// Права рокировки: [буква права => клетка ладьи].
function chess_rights(array $p): array {
    $out = [];
    foreach ($p['c'] === '' ? [] : str_split($p['c']) as $ch) {
        $u = strtoupper($ch);
        $file = empty($p['x']) ? ($u === 'K' ? 7 : 0) : ord($u) - 65;
        $out[$ch] = (ctype_upper($ch) ? 0 : 56) + $file;
    }
    return $out;
}

// Начальная расстановка Chess960 по номеру 0–959 (нумерация Шарнагля, как
// на lichess: 518 — обычная RNBQKBNR): слоны на полях разного цвета, ферзь,
// кони, на оставшихся трёх полях — ладья, король, ладья.
function chess960_rank(int $n): string {
    $n = (($n % 960) + 960) % 960;
    $r = array_fill(0, 8, '');
    $r[2 * ($n % 4) + 1] = 'B';
    $n = intdiv($n, 4);
    $r[2 * ($n % 4)] = 'B';
    $n = intdiv($n, 4);
    $e = array_keys($r, '', true);
    $r[$e[$n % 6]] = 'Q';
    $n = intdiv($n, 6);
    $e = array_keys($r, '', true);
    foreach (CHESS960_KNIGHTS[$n] as $k) { $r[$e[$k]] = 'N'; }
    $e = array_keys($r, '', true);
    foreach (['R', 'K', 'R'] as $i => $pc) { $r[$e[$i]] = $pc; }
    return implode('', $r);
}

// FEN начала партии 960 с правами в Shredder-FEN.
function chess960_fen(int $n): string {
    $w = chess960_rank($n);
    $rooks = array_keys(str_split($w), 'R', true);
    $rights = chr(65 + $rooks[1]) . chr(65 + $rooks[0]);
    return strtolower($w) . '/pppppppp/8/8/8/8/PPPPPPPP/' . $w . ' w ' . $rights . strtolower($rights) . ' - 0 1';
}

// Расстановка — первая часть FEN.
function chess_placement(array $b): string {
    $rows = [];
    for ($r = 7; $r >= 0; $r--) {
        $row = '';
        $empty = 0;
        for ($f = 0; $f < 8; $f++) {
            $pc = $b[$r * 8 + $f];
            if ($pc === '') {
                $empty++;
                continue;
            }
            if ($empty > 0) { $row .= $empty; }
            $empty = 0;
            $row .= $pc;
        }
        if ($empty > 0) { $row .= $empty; }
        $rows[] = $row;
    }
    return implode('/', $rows);
}

// Клетка взятия на проходе пишется, только если пешка соперника правда
// может на неё побить: так одна и та же позиция даёт одну строку FEN и
// повторение считается честно.
function chess_ep_live(array $p): int {
    if ($p['ep'] < 0) { return -1; }
    $pawn = $p['t'] === 'w' ? 'P' : 'p';
    $back = $p['t'] === 'w' ? -1 : 1;
    foreach ([-1, 1] as $df) {
        $s = chess_step($p['ep'], $df, $back);
        if ($s >= 0 && $p['b'][$s] === $pawn) { return $p['ep']; }
    }
    return -1;
}

function chess_to_fen(array $p): string {
    $ep = chess_ep_live($p);
    return chess_placement($p['b']) . ' ' . $p['t'] . ' ' . ($p['c'] === '' ? '-' : $p['c']) . ' '
        . ($ep < 0 ? '-' : chess_sq_name($ep)) . ' ' . $p['h'] . ' ' . $p['f'];
}

// Ключ повторения позиции: расстановка, чей ход, рокировки, взятие на проходе.
function chess_key(array $p): string {
    return implode(' ', array_slice(explode(' ', chess_to_fen($p)), 0, 4));
}

// Бьёт ли сторона $by клетку $sq.
function chess_attacked(array $b, int $sq, string $by): bool {
    $w = $by === 'w';
    $pawnFrom = $w ? -1 : 1;
    foreach ([-1, 1] as $df) {
        $s = chess_step($sq, $df, $pawnFrom);
        if ($s >= 0 && $b[$s] === ($w ? 'P' : 'p')) { return true; }
    }
    foreach (CHESS_KNIGHT as [$df, $dr]) {
        $s = chess_step($sq, $df, $dr);
        if ($s >= 0 && $b[$s] === ($w ? 'N' : 'n')) { return true; }
    }
    foreach (CHESS_KING as [$df, $dr]) {
        $s = chess_step($sq, $df, $dr);
        if ($s >= 0 && $b[$s] === ($w ? 'K' : 'k')) { return true; }
    }
    foreach ([[CHESS_DIAG, 'BQ'], [CHESS_ORTH, 'RQ']] as [$dirs, $kinds]) {
        $kinds = $w ? $kinds : strtolower($kinds);
        foreach ($dirs as [$df, $dr]) {
            $s = chess_step($sq, $df, $dr);
            while ($s >= 0) {
                $pc = $b[$s];
                if ($pc !== '') {
                    if (strpos($kinds, $pc) !== false) { return true; }
                    break;
                }
                $s = chess_step($s, $df, $dr);
            }
        }
    }
    return false;
}

function chess_king_sq(array $b, string $c): int {
    $k = array_search($c === 'w' ? 'K' : 'k', $b, true);
    return $k === false ? -1 : (int)$k;
}

// Под шахом ли сторона $c (по умолчанию — та, чей ход).
function chess_in_check(array $p, ?string $c = null): bool {
    $c = $c ?? $p['t'];
    $k = chess_king_sq($p['b'], $c);
    return $k >= 0 && chess_attacked($p['b'], $k, chess_other($c));
}

// Ходы по правилам фигур без проверки «свой король под боем»: [откуда, куда, превращение].
function chess_pseudo(array $p): array {
    $b = $p['b'];
    $c = $p['t'];
    $out = [];
    for ($sq = 0; $sq < 64; $sq++) {
        $pc = $b[$sq];
        if ($pc === '' || chess_color($pc) !== $c) { continue; }
        $kind = strtolower($pc);
        if ($kind === 'p') {
            $dir = $c === 'w' ? 1 : -1;
            $start = $c === 'w' ? 1 : 6;
            $last = $c === 'w' ? 7 : 0;
            $r = intdiv($sq, 8);
            $one = chess_step($sq, 0, $dir);
            if ($one >= 0 && $b[$one] === '') {
                chess_push_pawn($out, $sq, $one, intdiv($one, 8) === $last);
                $two = chess_step($sq, 0, 2 * $dir);
                if ($r === $start && $b[$two] === '') { $out[] = [$sq, $two, '']; }
            }
            foreach ([-1, 1] as $df) {
                $to = chess_step($sq, $df, $dir);
                if ($to < 0) { continue; }
                $t = $b[$to];
                if (($t !== '' && chess_color($t) !== $c) || $to === $p['ep']) {
                    chess_push_pawn($out, $sq, $to, intdiv($to, 8) === $last);
                }
            }
            continue;
        }
        if ($kind === 'n' || $kind === 'k') {
            foreach ($kind === 'n' ? CHESS_KNIGHT : CHESS_KING as [$df, $dr]) {
                $to = chess_step($sq, $df, $dr);
                if ($to >= 0 && chess_color($b[$to]) !== $c) { $out[] = [$sq, $to, '']; }
            }
            continue;
        }
        $dirs = $kind === 'b' ? CHESS_DIAG : ($kind === 'r' ? CHESS_ORTH : array_merge(CHESS_DIAG, CHESS_ORTH));
        foreach ($dirs as [$df, $dr]) {
            $to = chess_step($sq, $df, $dr);
            while ($to >= 0) {
                $t = $b[$to];
                if ($t === '') {
                    $out[] = [$sq, $to, ''];
                } else {
                    if (chess_color($t) !== $c) { $out[] = [$sq, $to, '']; }
                    break;
                }
                $to = chess_step($to, $df, $dr);
            }
        }
    }
    chess_push_castles($out, $p);
    return $out;
}

function chess_push_pawn(array &$out, int $from, int $to, bool $promo): void {
    if (!$promo) {
        $out[] = [$from, $to, ''];
        return;
    }
    foreach (['q', 'r', 'b', 'n'] as $x) { $out[] = [$from, $to, $x]; }
}

// Рокировка: право есть, король и ладья на местах, король встаёт на g/c, ладья
// на f/d; все поля между ними и их полями назначения пусты, кроме самих
// короля и ладьи; король не под шахом и не проходит и не встаёт на битое
// поле (битость — без короля и этой ладьи на доске). В обычных шахматах это
// те же правила при короле на e1 и ладьях в углах; ход там — король на два
// поля, в 960 — король на свою ладью.
function chess_push_castles(array &$out, array $p): void {
    $b = $p['b'];
    $c = $p['t'];
    $w = $c === 'w';
    $x = !empty($p['x']);
    $row = $w ? 0 : 56;
    $k = chess_king_sq($b, $c);
    if ($k < $row || $k > $row + 7 || (!$x && $k !== $row + 4)) { return; }
    $them = chess_other($c);
    $rook = $w ? 'R' : 'r';
    foreach (chess_rights($p) as $right => $r) {
        if (chess_color($right) !== $c || $b[$r] !== $rook || $r === $k) { continue; }
        $long = $r < $k;
        $kTo = $row + ($long ? 2 : 6);
        $rTo = $row + ($long ? 3 : 5);
        $ok = true;
        for ($s = min($k, $kTo, $r, $rTo), $hi = max($k, $kTo, $r, $rTo); $ok && $s <= $hi; $s++) {
            if ($s !== $k && $s !== $r && $b[$s] !== '') { $ok = false; }
        }
        $bare = $b;
        $bare[$k] = '';
        $bare[$r] = '';
        for ($s = min($k, $kTo), $hi = max($k, $kTo); $ok && $s <= $hi; $s++) {
            if (chess_attacked($bare, $s, $them)) { $ok = false; }
        }
        if ($ok) { $out[] = [$k, $x ? $r : $kTo, '']; }
    }
}

// Ход — рокировка: король на свою ладью (960) или на два поля (обычные).
function chess_is_castle(array $p, int $from, int $to): bool {
    $pc = $p['b'][$from];
    if (strtolower($pc) !== 'k') { return false; }
    if ($p['b'][$to] === ($pc === 'K' ? 'R' : 'r')) { return true; }
    return empty($p['x']) && abs($to - $from) === 2;
}

// Сделать ход без проверки правил (ход уже из списка разрешённых).
function chess_make(array $p, int $from, int $to, string $promo = ''): array {
    $b = $p['b'];
    $pc = $b[$from];
    $c = chess_color($pc);
    $kind = strtolower($pc);
    $capture = false;
    if (chess_is_castle($p, $from, $to)) {
        $row = $from - $from % 8;
        $long = $to < $from;
        $rf = $b[$to] !== '' ? $to : $row + ($long ? 0 : 7);
        $rook = $b[$rf];
        $b[$from] = '';
        $b[$rf] = '';
        $b[$row + ($long ? 2 : 6)] = $pc;
        $b[$row + ($long ? 3 : 5)] = $rook;
    } else {
        $capture = $b[$to] !== '';
        if ($kind === 'p' && $to === $p['ep'] && $from % 8 !== $to % 8 && !$capture) {
            $b[$to + ($c === 'w' ? -8 : 8)] = '';
            $capture = true;
        }
        $b[$to] = $promo !== '' ? ($c === 'w' ? strtoupper($promo) : strtolower($promo)) : $pc;
        $b[$from] = '';
    }
    $rights = '';
    foreach (chess_rights($p) as $right => $sq) {
        if (($kind === 'k' && chess_color($right) === $c) || $sq === $from || $sq === $to) { continue; }
        $rights .= $right;
    }
    return [
        'b' => $b,
        't' => chess_other($p['t']),
        'c' => $rights,
        'ep' => ($kind === 'p' && abs($to - $from) === 16) ? intdiv($from + $to, 2) : -1,
        'h' => ($kind === 'p' || $capture) ? 0 : $p['h'] + 1,
        'f' => $p['f'] + ($p['t'] === 'b' ? 1 : 0),
        'x' => !empty($p['x']),
    ];
}

function chess_uci(array $m): string {
    return chess_sq_name($m[0]) . chess_sq_name($m[1]) . $m[2];
}

// Разрешённые ходы: [uci => [откуда, куда, превращение]].
function chess_legal(array $p): array {
    $out = [];
    $me = $p['t'];
    foreach (chess_pseudo($p) as $m) {
        $n = chess_make($p, $m[0], $m[1], $m[2]);
        if (!chess_in_check($n, $me)) { $out[chess_uci($m)] = $m; }
    }
    return $out;
}

// Ход из UCI по правилам: новая позиция или null, если так ходить нельзя.
function chess_move(array $p, string $uci): ?array {
    $m = chess_legal($p)[strtolower($uci)] ?? null;
    return $m === null ? null : chess_make($p, $m[0], $m[1], $m[2]);
}

// Запись хода для людей (SAN): e4, Nf3, exd5, O-O, e8=Q, Qxf7#.
function chess_san(array $p, string $uci): string {
    $legal = chess_legal($p);
    $m = $legal[$uci] ?? null;
    if ($m === null) { return $uci; }
    [$from, $to, $promo] = $m;
    $pc = $p['b'][$from];
    $kind = strtoupper($pc);
    if (chess_is_castle($p, $from, $to)) {
        $s = $to > $from ? 'O-O' : 'O-O-O';
    } else {
        $capture = $p['b'][$to] !== '' || ($kind === 'P' && $to === $p['ep'] && $from % 8 !== $to % 8);
        $s = '';
        if ($kind === 'P') {
            if ($capture) { $s = chr(97 + $from % 8); }
        } else {
            $s = $kind;
            $same = [];
            foreach ($legal as $o) {
                if ($o[1] === $to && $o[0] !== $from && $p['b'][$o[0]] === $pc) { $same[] = $o[0]; }
            }
            if ($same) {
                $fileClash = false;
                $rankClash = false;
                foreach ($same as $o) {
                    if ($o % 8 === $from % 8) { $fileClash = true; }
                    if (intdiv($o, 8) === intdiv($from, 8)) { $rankClash = true; }
                }
                $sq = chess_sq_name($from);
                $s .= !$fileClash ? $sq[0] : (!$rankClash ? $sq[1] : $sq);
            }
        }
        $s .= ($capture ? 'x' : '') . chess_sq_name($to) . ($promo !== '' ? '=' . strtoupper($promo) : '');
    }
    $n = chess_make($p, $from, $to, $promo);
    if (chess_in_check($n)) { $s .= chess_legal($n) ? '+' : '#'; }
    return $s;
}

// Хватит ли стороне $c фигур, чтобы когда-нибудь поставить мат: всё, кроме
// голого короля и короля с одним конём или слоном. По этому правилу
// просрочка времени против голого короля — ничья, а не проигрыш.
function chess_can_mate(array $b, string $c): bool {
    $minor = 0;
    foreach ($b as $pc) {
        if ($pc === '' || chess_color($pc) !== $c) { continue; }
        $k = strtolower($pc);
        if ($k === 'p' || $k === 'r' || $k === 'q') { return true; }
        if ($k === 'n' || $k === 'b') { $minor++; }
    }
    return $minor >= 2;
}

// Мат не поставить никому: короли одни, или у одного коня/слона, или
// остались только слоны на полях одного цвета.
function chess_dead_draw(array $b): bool {
    $minors = [];
    foreach ($b as $sq => $pc) {
        $k = strtolower($pc);
        if ($pc === '' || $k === 'k') { continue; }
        if ($k !== 'n' && $k !== 'b') { return false; }
        $minors[] = [$k, ($sq % 8 + intdiv($sq, 8)) % 2];
    }
    if (count($minors) <= 1) { return true; }
    $shade = null;
    foreach ($minors as [$k, $sh]) {
        if ($k !== 'b') { return false; }
        if ($shade !== null && $shade !== $sh) { return false; }
        $shade = $sh;
    }
    return true;
}

// Итог позиции после хода: 'mate', 'stalemate', 'material', 'fifty',
// 'repeat' или '' — партия идёт. $keys — ключи всех позиций партии,
// включая текущую (chess_key).
function chess_outcome(array $p, array $keys): string {
    if (!chess_legal($p)) { return chess_in_check($p) ? 'mate' : 'stalemate'; }
    if (chess_dead_draw($p['b'])) { return 'material'; }
    if ($p['h'] >= 100) { return 'fifty'; }
    $now = chess_key($p);
    $n = 0;
    foreach ($keys as $k) {
        if ($k === $now) { $n++; }
    }
    return $n >= 3 ? 'repeat' : '';
}

// Партия из начальной позиции ($start — FEN начала, для 960 своя) по списку
// ходов: [позиция, ключи позиций] или null, если какой-то ход нельзя сделать.
function chess_replay(array $moves, string $start = CHESS_START): ?array {
    $p = chess_from_fen($start);
    if ($p === null) { return null; }
    $keys = [chess_key($p)];
    foreach ($moves as $uci) {
        $p = chess_move($p, (string)$uci);
        if ($p === null) { return null; }
        $keys[] = chess_key($p);
    }
    return [$p, $keys];
}

// Ключи позиций партии по уже проверенным ходам — без генератора ходов:
// так повторение после каждого хода считается быстро и на длинной партии.
function chess_keys(array $moves, string $start = CHESS_START): array {
    $p = chess_from_fen($start) ?? chess_from_fen(CHESS_START);
    $keys = [chess_key($p)];
    foreach ($moves as $uci) {
        $uci = (string)$uci;
        $p = chess_make($p, chess_sq(substr($uci, 0, 2)), chess_sq(substr($uci, 2, 2)), (string)substr($uci, 4, 1));
        $keys[] = chess_key($p);
    }
    return $keys;
}

// Перебор ходов на глубину $depth — сверка генератора с известными числами.
function chess_perft(array $p, int $depth): int {
    if ($depth === 0) { return 1; }
    $legal = chess_legal($p);
    if ($depth === 1) { return count($legal); }
    $n = 0;
    foreach ($legal as $m) { $n += chess_perft(chess_make($p, $m[0], $m[1], $m[2]), $depth - 1); }
    return $n;
}
