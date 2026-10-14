<?php
// Лайки тирлиста и постов ленты — только вошедшим через Roblox и по одному
// на аккаунт (решение владельца, 2026-10-14). Счётчики живут где жили:
// likes.count у тирлиста, news.likes у поста. Таблица like_votes помнит, кто
// уже голосовал: второй «лайк» с того же аккаунта счётчик не трогает, а
// снять можно только поставленный — иначе «минус» с чистого аккаунта уводил
// бы чужие лайки.

require_once __DIR__ . '/profile.php';

const LIKE_TIERLIST = 'tierlist';

function like_news_target(int $id): string {
    return 'news:' . $id;
}

/**
 * Поставить (+1) или снять (−1) голос. true — голос действительно изменился,
 * и счётчик надо сдвинуть; false — он уже был таким.
 *
 * Повтор ловится первичным ключом, а не SELECT перед INSERT: два клика из
 * двух вкладок прошли бы проверку оба. SQLSTATE 23000 одинаков у MySQL и
 * SQLite. Отсутствие таблицы (миграция не запущена) — исключение наверх.
 */
function like_vote(PDO $pdo, string $me, string $target, int $dir, int $now): bool {
    if ($dir === 1) {
        try {
            $pdo->prepare('INSERT INTO like_votes (user_id, target, created_at) VALUES (?, ?, ?)')
                ->execute([$me, $target, $now]);
            return true;
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') { return false; }
            throw $e;
        }
    }
    $st = $pdo->prepare('DELETE FROM like_votes WHERE user_id = ? AND target = ?');
    $st->execute([$me, $target]);
    return $st->rowCount() === 1;
}

/**
 * Что человек уже лайкнул: ['tierlist' => bool, 'news' => [id, …]]. Отдаёт
 * session.php, по нему кнопки рисуются нажатыми на любом устройстве. Без
 * таблицы — пусто, а не ошибка.
 */
function like_mine(PDO $pdo, string $me): array {
    $out = ['tierlist' => false, 'news' => []];
    if ($me === '') { return $out; }
    try {
        $st = $pdo->prepare('SELECT target FROM like_votes WHERE user_id = ?');
        $st->execute([$me]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $t = (string)$t;
            if ($t === LIKE_TIERLIST) { $out['tierlist'] = true; }
            elseif (preg_match('/^news:([1-9]\d{0,9})\z/', $t, $m) === 1) { $out['news'][] = (int)$m[1]; }
        }
    } catch (PDOException $e) {
        // таблицы like_votes ещё нет
    }
    return $out;
}
