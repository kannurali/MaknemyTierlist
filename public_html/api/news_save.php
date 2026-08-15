<?php
require_once __DIR__ . '/_bootstrap.php';

// Допустимые категории. Тот же список лежит в js/news.js — если добавляется
// четвёртая, править надо оба места, иначе редактор предложит то, что сервер
// не примет.
const NEWS_CATEGORIES = ['tierlist', 'game', 'project'];
const NEWS_TITLE_MAX  = 200;
const NEWS_BODY_MAX   = 20000;

// Ровно то, что возвращает save_image_bytes(): /images/<sha1>.<ext>.
// Белый список формы, а не чёрный список опасного: чужой хост, javascript: и
// обход каталога отсекаются по построению, а не по перечислению.
const NEWS_IMAGE_RE = '#^/images/[0-9a-f]{40}\.(png|jpg|webp)$#';

function validate_news_post(array $b): array {
    $cat = (string)($b['category'] ?? '');
    if (!in_array($cat, NEWS_CATEGORIES, true)) {
        return ['ok' => false, 'error' => 'bad category', 'post' => []];
    }

    $titleRu = trim((string)($b['title_ru'] ?? ''));
    $bodyRu  = trim((string)($b['body_ru']  ?? ''));
    if ($titleRu === '') { return ['ok' => false, 'error' => 'title_ru is required', 'post' => []]; }
    if ($bodyRu === '')  { return ['ok' => false, 'error' => 'body_ru is required',  'post' => []]; }

    $titleEn = trim((string)($b['title_en'] ?? ''));
    $bodyEn  = trim((string)($b['body_en']  ?? ''));

    // mb_strlen, а не strlen: в utf8mb4 русская буква весит два байта, и по
    // байтам валидный заголовок из 200 символов отлетал бы как слишком длинный.
    if (mb_strlen($titleRu) > NEWS_TITLE_MAX || mb_strlen($titleEn) > NEWS_TITLE_MAX) {
        return ['ok' => false, 'error' => 'title too long', 'post' => []];
    }
    if (mb_strlen($bodyRu) > NEWS_BODY_MAX || mb_strlen($bodyEn) > NEWS_BODY_MAX) {
        return ['ok' => false, 'error' => 'body too long', 'post' => []];
    }

    $image = trim((string)($b['image_url'] ?? ''));
    if ($image !== '' && !preg_match(NEWS_IMAGE_RE, $image)) {
        return ['ok' => false, 'error' => 'bad image_url', 'post' => []];
    }

    return ['ok' => true, 'error' => '', 'post' => [
        'category'  => $cat,
        'title_ru'  => $titleRu,
        'title_en'  => $titleEn,
        'body_ru'   => $bodyRu,
        'body_en'   => $bodyEn,
        'image_url' => $image,
    ]];
}

function handle_news_save(PDO $pdo, array $body, int $nowMs): array {
    $v = validate_news_post($body);
    if (!$v['ok']) { return [400, ['ok' => false, 'error' => $v['error']]]; }
    $p = $v['post'];

    // Дата поста — контент, а не rev: редактор её показывает и правит, задним
    // числом в том числе. Поэтому явное значение побеждает серверные часы, и
    // монотонности здесь никто не ждёт.
    $publishedAt = ((int)($body['published_at'] ?? 0)) > 0
        ? (int)$body['published_at']
        : $nowMs;

    $params = [
        ':c'   => $p['category'],
        ':tr'  => $p['title_ru'],
        ':te'  => $p['title_en'],
        ':br'  => $p['body_ru'],
        ':be'  => $p['body_en'],
        ':img' => $p['image_url'],
        ':pa'  => $publishedAt,
    ];

    $id = (int)($body['id'] ?? 0);
    if ($id > 0) {
        // Существование проверяется отдельным SELECT, а не по rowCount()
        // апдейта: MySQL считает изменённые строки, а не найденные, и
        // сохранение без правок вернуло бы 0 — то есть ложный 404.
        $chk = $pdo->prepare("SELECT COUNT(*) FROM news WHERE id = :id");
        $chk->execute([':id' => $id]);
        if ((int)$chk->fetchColumn() === 0) {
            return [404, ['ok' => false, 'error' => 'not found']];
        }
        $stmt = $pdo->prepare(
            "UPDATE news
                SET category = :c, title_ru = :tr, title_en = :te,
                    body_ru = :br, body_en = :be, image_url = :img,
                    published_at = :pa
              WHERE id = :id"
        );
        $stmt->execute($params + [':id' => $id]);
        return [200, ['ok' => true, 'id' => $id]];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO news (category, title_ru, title_en, body_ru, body_en, image_url, published_at)
         VALUES (:c, :tr, :te, :br, :be, :img, :pa)"
    );
    $stmt->execute($params);
    return [200, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]];
}

if (!defined('TESTING')) {
    require_post();
    require_admin();
    $nowMs = (int)round(microtime(true) * 1000);
    [$status, $payload] = handle_news_save(db(), read_json_body(), $nowMs);
    json_out($payload, $status);
}
