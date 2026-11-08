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

run_tests();
