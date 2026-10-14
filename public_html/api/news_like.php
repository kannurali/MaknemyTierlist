<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/likes.php';

// Лайк одного поста ленты — только вошедшему и один на аккаунт
// (api/lib/likes.php). Устройство как у api/like.php (общий счётчик
// тирлиста): голос и счётчик в одной транзакции, счётчик — атомарный
// UPDATE ... WHERE с защитой от ухода в минус. Разница только в том, что
// счётчиков здесь много (колонка news.likes), поэтому нужен ещё и id.
function handle_news_like(PDO $pdo, string $me, array $body, int $now): array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }

    // read_row_id(), а не (int)$body['id']: (int)"1abc" молча стало бы 1 и
    // лайк ушёл бы чужому посту — та же дыра, которую read_row_id() уже
    // закрыла для news_delete.php/news_save.php.
    $id = read_row_id($body);
    if ($id <= 0) { return [400, ['ok' => false, 'error' => 'bad id']]; }

    $dir = (isset($body['dir']) && (int)$body['dir'] === -1) ? -1 : 1;

    // Существование проверяется отдельным SELECT до голоса: иначе в
    // like_votes легла бы строка за несуществующий пост.
    $chk = $pdo->prepare('SELECT 1 FROM news WHERE id = :id');
    $chk->execute([':id' => $id]);
    if ($chk->fetchColumn() === false) {
        return [404, ['ok' => false, 'error' => 'not found']];
    }

    $pdo->beginTransaction();
    try {
        if (like_vote($pdo, $me, like_news_target($id), $dir, $now)) {
            $pdo->prepare('UPDATE news SET likes = likes + :inc WHERE id = :id AND likes + :chk >= 0')
                ->execute([':inc' => $dir, ':id' => $id, ':chk' => $dir]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return [503, ['ok' => false, 'error' => 'not_ready']];
    }

    $sel = $pdo->prepare('SELECT likes FROM news WHERE id = :id');
    $sel->execute([':id' => $id]);
    $likes = (int)$sel->fetchColumn();

    return [200, ['ok' => true, 'id' => $id, 'likes' => $likes, 'liked' => $dir === 1]];
}

if (!defined('TESTING')) {
    require_post();
    // 100/час: лента отдаёт до NEWS_FEED_LIMIT (50) постов разом, и
    // лайкнуть-и-передумать по каждому — это 100 запросов за заход.
    $key = client_key();
    if (!rate_limit_allow('news_like', $key, 100, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }
    $me = resume_site_session() ? profile_me($_SESSION) : '';
    [$status, $payload] = handle_news_like(db(), $me, read_json_body(), time());
    json_out($payload, $status);
}
