<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/chess.php';

// Правила шахмат (api/lib/chess.php). Генератор ходов сверяется с
// общеизвестными числами perft (chessprogramming.org/Perft_Results): в них
// сходятся рокировки, взятие на проходе, превращения и связки.

function ch_pos(string $fen): array {
    $p = chess_from_fen($fen);
    if ($p === null) { throw new RuntimeException('плохой FEN: ' . $fen); }
    return $p;
}

function ch_play(array $moves): array {
    $r = chess_replay($moves);
    if ($r === null) { throw new RuntimeException('ход не прошёл: ' . implode(' ', $moves)); }
    return $r;
}

test('perft: начальная позиция', function () {
    $p = ch_pos(CHESS_START);
    assert_eq([20, 400, 8902], [chess_perft($p, 1), chess_perft($p, 2), chess_perft($p, 3)]);
});

test('perft: Kiwipete — рокировки, связки, взятие на проходе', function () {
    $p = ch_pos('r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1');
    assert_eq([48, 2039], [chess_perft($p, 1), chess_perft($p, 2)]);
});

test('perft: эндшпиль с шахами от ладьи и взятием на проходе', function () {
    $p = ch_pos('8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - - 0 1');
    assert_eq([14, 191, 2812], [chess_perft($p, 1), chess_perft($p, 2), chess_perft($p, 3)]);
});

test('perft: превращения и рокировка под ударом', function () {
    $p = ch_pos('r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1');
    assert_eq([6, 264, 9467], [chess_perft($p, 1), chess_perft($p, 2), chess_perft($p, 3)]);
    $p = ch_pos('rnbq1k1r/pp1Pbppp/2p5/8/2B5/8/PPP1NnPP/RNBQK2R w KQ - 1 8');
    assert_eq([44, 1486], [chess_perft($p, 1), chess_perft($p, 2)]);
});

test('FEN туда и обратно; плохой FEN — null', function () {
    assert_eq(CHESS_START, chess_to_fen(ch_pos(CHESS_START)));
    $fen = 'r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1';
    assert_eq($fen, chess_to_fen(ch_pos($fen)));
    foreach (['', 'x', '8/8/8/8/8/8/8/8 w - - 0 1', 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR x KQkq - 0 1',
              'rnbqkbnr/pppppppp/9/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1', 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq z9 0 1'] as $bad) {
        assert_eq(null, chess_from_fen($bad), $bad);
    }
});

test('взятие на проходе в FEN — только если есть кому бить', function () {
    [$p] = ch_play(['e2e4']);
    assert_eq('rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1', chess_to_fen($p));
    [$p] = ch_play(['e2e4', 'a7a6', 'e4e5', 'd7d5']);
    assert_eq('rnbqkbnr/1pp1pppp/p7/3pP3/8/8/PPPP1PPP/RNBQKBNR w KQkq d6 0 3', chess_to_fen($p));
    $n = chess_move($p, 'e5d6');
    assert_eq('rnbqkbnr/1pp1pppp/p2P4/8/8/8/PPPP1PPP/RNBQKBNR b KQkq - 0 3', chess_to_fen($n));
});

test('ход: неверный и чужой — null; превращение без фигуры — null', function () {
    $p = ch_pos(CHESS_START);
    assert_eq(null, chess_move($p, 'e2e5'));
    assert_eq(null, chess_move($p, 'e7e5'));
    assert_eq(null, chess_move($p, 'zz'));
    $q = ch_pos('8/4P3/8/8/8/8/k7/4K3 w - - 0 1');
    assert_eq(null, chess_move($q, 'e7e8'));
    assert_eq('4N3/8/8/8/8/8/k7/4K3 b - - 0 1', chess_to_fen(chess_move($q, 'e7e8n')));
});

test('рокировка двигает ладью и снимает права', function () {
    $p = ch_pos('r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1');
    assert_eq('r3k2r/8/8/8/8/8/8/R4RK1 b kq - 1 1', chess_to_fen(chess_move($p, 'e1g1')));
    assert_eq('r3k2r/8/8/8/8/8/8/2KR3R b kq - 1 1', chess_to_fen(chess_move($p, 'e1c1')));
    assert_eq('r3k2r/8/8/8/8/8/8/R3K1R1 b Qkq - 1 1', chess_to_fen(chess_move($p, 'h1g1')));
    $b = chess_move($p, 'a1a8');
    assert_eq('R3k2r/8/8/8/8/8/8/4K2R b Kk - 0 1', chess_to_fen($b), 'ладья съедена в углу — права нет');
    $through = ch_pos('r3k2r/8/8/8/8/8/5r2/R3K2R w KQkq - 0 1');
    assert_eq(false, isset(chess_legal($through)['e1g1']), 'через битое поле нельзя');
    assert_eq(true, isset(chess_legal($through)['e1c1']));
});

test('SAN: фигуры, взятия, уточнение, рокировки, шах и мат', function () {
    $moves = ['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1c4', 'g8f6', 'f3g5', 'd7d5', 'e4d5', 'f6d5', 'e1g1'];
    $p = ch_pos(CHESS_START);
    $san = [];
    foreach ($moves as $m) {
        $san[] = chess_san($p, $m);
        $p = chess_move($p, $m);
    }
    assert_eq(['e4', 'e5', 'Nf3', 'Nc6', 'Bc4', 'Nf6', 'Ng5', 'd5', 'exd5', 'Nxd5', 'O-O'], $san);
    $two = ch_pos('4k3/8/8/8/8/8/4K3/R6R w - - 0 1');
    assert_eq('Rhd1', chess_san($two, 'h1d1'));
    $rank = ch_pos('7k/8/8/R7/8/8/8/R3K3 w - - 0 1');
    assert_eq('R1a3', chess_san($rank, 'a1a3'));
    $mate = ch_pos('6k1/5ppp/8/8/8/8/8/R5K1 w - - 0 1');
    assert_eq('Ra8#', chess_san($mate, 'a1a8'));
    $check = ch_pos('6k1/5pp1/8/8/8/8/8/R5K1 w - - 0 1');
    assert_eq('Ra8+', chess_san($check, 'a1a8'));
    $promo = ch_pos('1n5k/P7/8/8/8/8/8/6K1 w - - 0 1');
    assert_eq('axb8=Q+', chess_san($promo, 'a7b8q'));
});

test('итоги: мат, пат, мало фигур, 50 ходов, троекратное повторение', function () {
    [$p, $keys] = ch_play(['f2f3', 'e7e5', 'g2g4', 'd8h4']);
    assert_eq('mate', chess_outcome($p, $keys));
    $stale = ch_pos('7k/5Q2/6K1/8/8/8/8/8 b - - 0 1');
    assert_eq('stalemate', chess_outcome($stale, [chess_key($stale)]));
    $kk = ch_pos('7k/8/6K1/8/8/8/8/8 b - - 0 1');
    assert_eq('material', chess_outcome($kk, [chess_key($kk)]));
    $kb = ch_pos('7k/8/6K1/8/8/8/8/2B1b3 b - - 0 1');
    assert_eq('material', chess_outcome($kb, [chess_key($kb)]), 'слоны на полях одного цвета');
    $kbb = ch_pos('7k/8/6K1/8/8/8/8/2B2b2 b - - 0 1');
    assert_eq('', chess_outcome($kbb, [chess_key($kbb)]), 'слоны разного цвета — не ничья');
    $fifty = ch_pos('7k/8/6K1/8/8/8/8/R7 b - - 100 80');
    assert_eq('fifty', chess_outcome($fifty, [chess_key($fifty)]));
    [$p, $keys] = ch_play(['g1f3', 'g8f6', 'f3g1', 'f6g8', 'g1f3', 'g8f6', 'f3g1']);
    assert_eq('', chess_outcome($p, $keys), 'позиция была два раза');
    [$p, $keys] = ch_play(['g1f3', 'g8f6', 'f3g1', 'f6g8', 'g1f3', 'g8f6', 'f3g1', 'f6g8']);
    assert_eq('repeat', chess_outcome($p, $keys));
});

test('хватит ли фигур на мат — для просрочки времени', function () {
    $b = ch_pos('7k/8/6K1/8/8/8/8/R7 w - - 0 1')['b'];
    assert_eq([true, false], [chess_can_mate($b, 'w'), chess_can_mate($b, 'b')]);
    assert_eq(false, chess_can_mate(ch_pos('7k/8/6K1/8/8/8/8/N7 w - - 0 1')['b'], 'w'));
    assert_eq(true, chess_can_mate(ch_pos('7k/8/6K1/8/8/8/8/NB6 w - - 0 1')['b'], 'w'));
    assert_eq(true, chess_can_mate(ch_pos('7k/8/6K1/8/8/8/P7/8 w - - 0 1')['b'], 'w'));
});

test('шах на доске и замена позиции после хода', function () {
    [$p] = ch_play(['e2e4', 'f7f6', 'd1h5']);
    assert_eq(true, chess_in_check($p));
    assert_eq(60, chess_king_sq($p['b'], 'b'));
    assert_eq(['g7g6'], array_keys(chess_legal($p)));
});

// Chess960: первые 12 позиций chessprogramming.org/Chess960_Perft_Results
// (Shredder-FEN), глубина 3 — в них рокировки с королём рядом с ладьёй, через
// поля ладьи и с правами только одной стороны.
test('perft 960: опубликованные позиции', function () {
    $pos = [
        ['bqnb1rkr/pp3ppp/3ppn2/2p5/5P2/P2P4/NPP1P1PP/BQ1BNRKR w HFhf - 2 9', [21, 528, 12189]],
        ['2nnrbkr/p1qppppp/8/1ppb4/6PP/3PP3/PPP2P2/BQNNRBKR w HEhe - 1 9', [21, 807, 18002]],
        ['b1q1rrkb/pppppppp/3nn3/8/P7/1PPP4/4PPPP/BQNNRKRB w GE - 1 9', [20, 479, 10471]],
        ['qbbnnrkr/2pp2pp/p7/1p2pp2/8/P3PP2/1PPP1KPP/QBBNNR1R w hf - 0 9', [22, 593, 13440]],
        ['1nbbnrkr/p1p1ppp1/3p4/1p3P1p/3Pq2P/8/PPP1P1P1/QNBBNRKR w HFhf - 0 9', [28, 1120, 31058]],
        ['qnbnr1kr/ppp1b1pp/4p3/3p1p2/8/2NPP3/PPP1BPPP/QNB1R1KR w HEhe - 1 9', [29, 899, 26578]],
        ['q1bnrkr1/ppppp2p/2n2p2/4b1p1/2NP4/8/PPP1PPPP/QNB1RRKB w ge - 1 9', [30, 860, 24566]],
        ['qbn1brkr/ppp1p1p1/2n4p/3p1p2/P7/6PP/QPPPPP2/1BNNBRKR w HFhf - 0 9', [25, 635, 17054]],
        ['qnnbbrkr/1p2ppp1/2pp3p/p7/1P5P/2NP4/P1P1PPP1/Q1NBBRKR w HFhf - 0 9', [24, 572, 15243]],
        ['qn1rbbkr/ppp2p1p/1n1pp1p1/8/3P4/P6P/1PP1PPPK/QNNRBB1R w hd - 2 9', [28, 811, 23175]],
        ['qnr1bkrb/pppp2pp/3np3/5p2/8/P2P2P1/NPP1PP1P/QN1RBKRB w GDg - 3 9', [33, 823, 26895]],
        ['qb1nrkbr/1pppp1p1/1n3p2/p1B4p/8/3P1P1P/PPP1P1P1/QBNNRK1R w HEhe - 0 9', [31, 855, 25620]],
    ];
    foreach ($pos as [$fen, $want]) {
        $p = ch_pos($fen);
        assert_eq($fen, chess_to_fen($p), 'FEN туда и обратно');
        assert_eq($want, [chess_perft($p, 1), chess_perft($p, 2), chess_perft($p, 3)], $fen);
    }
});

test('960: расстановки по номеру — все разные, слоны на разных цветах, король между ладьями', function () {
    assert_eq('RNBQKBNR', chess960_rank(518));
    assert_eq('BBQNNRKR', chess960_rank(0));
    assert_eq('RKRNNQBB', chess960_rank(959));
    assert_eq('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w HAha - 0 1', chess960_fen(518));
    assert_eq('bbqnnrkr/pppppppp/8/8/8/8/PPPPPPPP/BBQNNRKR w HFhf - 0 1', chess960_fen(0));
    $seen = [];
    for ($n = 0; $n < 960; $n++) {
        $r = chess960_rank($n);
        $seen[$r] = true;
        $b = array_keys(str_split($r), 'B', true);
        $rk = array_keys(str_split($r), 'R', true);
        $k = strpos($r, 'K');
        $ok = count($b) === 2 && ($b[0] + $b[1]) % 2 === 1 && count($rk) === 2 && $rk[0] < $k && $k < $rk[1]
            && substr_count($r, 'Q') === 1 && substr_count($r, 'N') === 2;
        if (!$ok) { assert_eq('правильная расстановка', $r, (string)$n); }
        $fen = chess960_fen($n);
        if (chess_to_fen(ch_pos($fen)) !== $fen) { assert_eq($fen, chess_to_fen(ch_pos($fen))); }
    }
    assert_eq(960, count($seen));
    assert_eq([20, 400, 8902], [chess_perft(ch_pos(chess960_fen(518)), 1), chess_perft(ch_pos(chess960_fen(518)), 2),
        chess_perft(ch_pos(chess960_fen(518)), 3)], 'обычная расстановка как 960');
});

test('960: X-FEN KQkq — крайние ладьи; буквы вертикалей включают 960', function () {
    $p = chess_from_fen('rk2r3/8/8/8/8/8/8/RK2R2R w KQkq - 0 1', true);
    assert_eq('rk2r3/8/8/8/8/8/8/RK2R2R w HAea - 0 1', chess_to_fen($p));
    $q = ch_pos('rk2r3/8/8/8/8/8/8/RK2R2R w EAea - 0 1');
    assert_eq(true, $q['x']);
    assert_eq(false, ch_pos(CHESS_START)['x']);
    assert_eq(null, chess_from_fen('4k3/8/8/8/8/8/8/R2RK2R w HDA - 0 1'), 'три права у одной стороны');
});

test('960: рокировка — король на g/c, ладья на f/d, ход «король на ладью»', function () {
    $p = ch_pos('1r4kr/8/8/8/8/8/8/1R4KR w HBhb - 0 1');
    $legal = chess_legal($p);
    assert_eq(true, isset($legal['g1h1']), 'король уже на g1: ходит только ладья');
    assert_eq('1r4kr/8/8/8/8/8/8/1R3RK1 b hb - 1 1', chess_to_fen(chess_move($p, 'g1h1')));
    assert_eq('O-O', chess_san($p, 'g1h1'));
    assert_eq(true, isset($legal['g1b1']));
    assert_eq('1r4kr/8/8/8/8/8/8/2KR3R b hb - 1 1', chess_to_fen(chess_move($p, 'g1b1')));
    assert_eq('O-O-O', chess_san($p, 'g1b1'));
    assert_eq(true, isset($legal['g1f1']) && isset($legal['g1g2']), 'обычные ходы короля остались');

    $swap = ch_pos('4k3/8/8/8/8/8/8/5KR1 w G - 0 1');
    assert_eq('4k3/8/8/8/8/8/8/5RK1 b - - 1 1', chess_to_fen(chess_move($swap, 'f1g1')), 'король и ладья меняются местами');
    $long = ch_pos('4k3/8/8/8/8/8/8/RK6 w A - 0 1');
    assert_eq('4k3/8/8/8/8/8/8/2KR4 b - - 1 1', chess_to_fen(chess_move($long, 'b1a1')), 'король идёт вправо на c1');
    $blocked = ch_pos('4k3/8/8/8/8/8/8/RK1N4 w A - 0 1');
    assert_eq(false, isset(chess_legal($blocked)['b1a1']), 'на d1 конь — ладье некуда');
    $other = ch_pos('4k3/8/8/8/8/8/8/1K4RR w H - 0 1');
    assert_eq(false, isset(chess_legal($other)['b1h1']), 'g1 занят второй ладьёй');
    $near = ch_pos('4k3/8/8/8/8/8/8/1K4RR w G - 0 1');
    assert_eq('4k3/8/8/8/8/8/8/5RKR b - - 1 1', chess_to_fen(chess_move($near, 'b1g1')), 'с ближней ладьёй можно');
});

test('960: битые поля, ладья заслоняла короля, битое поле ладьи', function () {
    $pass = ch_pos('4k3/8/8/8/8/8/4r3/1K5R w H - 0 1');
    assert_eq(false, isset(chess_legal($pass)['b1h1']), 'король проходит e1 под ударом');
    $check = ch_pos('4k3/8/8/8/8/8/1r6/1K5R w H - 0 1');
    assert_eq(false, isset(chess_legal($check)['b1h1']), 'под шахом рокировки нет');
    $shield = ch_pos('4k3/8/8/8/8/8/8/rRK5 w B - 0 1');
    assert_eq(false, chess_in_check($shield));
    assert_eq(false, isset(chess_legal($shield)['c1b1']), 'после рокировки ладья a1 бьёт короля на c1');
    $rookAttacked = ch_pos('4k3/8/8/8/8/8/3r4/RK6 w A - 0 1');
    assert_eq(true, isset(chess_legal($rookAttacked)['b1a1']), 'битое поле ладьи рокировке не мешает');
});

test('960: права по вертикалям ладей, запись хода — король на ладью', function () {
    $p = ch_pos(chess960_fen(0));
    $n = chess_move(chess_move(chess_move($p, 'h2h4'), 'h7h5'), 'h1h3');
    assert_eq('bbqnnrkr/ppppppp1/8/7p/7P/7R/PPPPPPP1/BBQNNRK1 b Fhf - 1 2', chess_to_fen($n), 'ушла ладья h — права H нет, F осталось');
    $x = ch_pos('r3k2r/8/8/8/8/8/8/R3K2R w HAha - 0 1');
    assert_eq([true, true, false, false], [isset(chess_legal($x)['e1h1']), isset(chess_legal($x)['e1a1']),
        isset(chess_legal($x)['e1g1']), isset(chess_legal($x)['e1c1'])]);
    assert_eq('r3k2r/8/8/8/8/8/8/R4RK1 b ha - 1 1', chess_to_fen(chess_move($x, 'e1h1')));
    $std = ch_pos('r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1');
    assert_eq([false, true], [isset(chess_legal($std)['e1h1']), isset(chess_legal($std)['e1g1'])], 'обычные шахматы — как раньше');
});

test('960: партия по ходам с начала 960, повторение по ключам', function () {
    $start = chess960_fen(0);
    $moves = ['d1e3', 'd8e6', 'e3d1', 'e6d8', 'd1e3', 'd8e6', 'e3d1', 'e6d8'];
    $r = chess_replay($moves, $start);
    assert_eq(true, $r !== null);
    assert_eq('repeat', chess_outcome($r[0], $r[1]));
    assert_eq($r[1], chess_keys($moves, $start));
    assert_eq(null, chess_replay(['d1e3']), 'с обычного начала этого хода нет');
});

run_tests();
