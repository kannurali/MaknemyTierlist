<?php
require_once __DIR__ . '/_bootstrap.php';

// Анонимный лайк одного поста ленты. Устройство один в один как у
// api/like.php (общий счётчик тирлиста) — тот же атомарный
// UPDATE ... WHERE с защитой от ухода в минус в одной инструкции, портируемый
// на MySQL и SQLite (тесты). Разница только в том, что здесь счётчиков много
// (по одному на пост, колонка news.likes), а не один на весь сайт, поэтому
// нужен ещё и id.
function handle_news_like(PDO $pdo, array $body): array {
    // read_row_id(), а не (int)$body['id']: (int)"1abc" молча стало бы 1 и
    // лайк ушёл бы чужому посту — та же дыра, которую read_row_id() уже
    // закрыла для news_delete.php/news_save.php.
    $id = read_row_id($body);
    if ($id <= 0) { return [400, ['ok' => false, 'error' => 'bad id']]; }

    $dir = (isset($body['dir']) && (int)$body['dir'] === -1) ? -1 : 1;

    // Существование проверяется отдельным SELECT, а не по rowCount() самого
    // UPDATE — та же причина, что в news_delete.php/news_save.php: несуществующий
    // id и "декремент уже на нуле" одинаково дают 0 задетых строк у UPDATE
    // ниже, а различить их нужно (первое — 404 и ничего не менять, второе —
    // 200, счётчик просто не уходит в минус). rowCount() на MySQL считает
    // изменённые строки, а не найденные, так что по нему это не различить.
    $chk = $pdo->prepare('SELECT 1 FROM news WHERE id = :id');
    $chk->execute([':id' => $id]);
    if ($chk->fetchColumn() === false) {
        return [404, ['ok' => false, 'error' => 'not found']];
    }

    // Атомарно + защита от underflow + портируемо на MySQL и SQLite: WHERE
    // блокирует сам UPDATE, когда результат ушёл бы в минус, а не читает
    // значение отдельным запросом и не пишет обратно (гонка между двумя
    // одновременными лайками того же поста).
    $stmt = $pdo->prepare(
        'UPDATE news SET likes = likes + :inc WHERE id = :id AND likes + :chk >= 0'
    );
    $stmt->execute([':inc' => $dir, ':id' => $id, ':chk' => $dir]);

    $sel = $pdo->prepare('SELECT likes FROM news WHERE id = :id');
    $sel->execute([':id' => $id]);
    $likes = (int)$sel->fetchColumn();

    return [200, ['ok' => true, 'id' => $id, 'likes' => $likes]];
}

if (!defined('TESTING')) {
    require_post();
    // Без входа, как и /api/like.php, поэтому нужен свой предел частоты.
    // 20/час у like.php посчитаны на ОДИН общий счётчик тирлиста — здесь же
    // лента отдаёт до NEWS_FEED_LIMIT (50) постов разом, и один и тот же
    // посетитель имеет полное право полистать её и лайкнуть/разлайкнуть
    // несколько разных постов, а не только один. Взять то же число 20
    // буквально означало бы упереться в потолок, честно пролистав меньше
    // половины ленты. 100/час с запасом покрывает даже щедрый сценарий —
    // лайкнуть-и-передумать по каждому из 50 постов за один заход (50×2=100),
    // и при этом на порядок ниже того, что нужно скрипту, который реально
    // пытается накрутить счётчик.
    $key = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!rate_limit_allow('news_like', $key, 100, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }
    [$status, $payload] = handle_news_like(db(), read_json_body());
    json_out($payload, $status);
}
