<?php
require_once __DIR__ . '/_bootstrap.php';

// Допустимые категории. Тот же список лежит в js/news.js — если добавляется
// четвёртая, править надо оба места, иначе редактор предложит то, что сервер
// не примет.
const NEWS_CATEGORIES = ['tierlist', 'game', 'project'];
// Тот же список, что и NEWS.IMAGE_SIZES в js/news.js — по той же причине,
// что и у NEWS_CATEGORIES выше: одно место правды на фронте и на сервере.
const NEWS_IMAGE_SIZES = ['small', 'medium', 'full'];
const NEWS_TITLE_MAX  = 200;
const NEWS_BODY_MAX   = 20000;

// Ровно то, что возвращает save_image_bytes(): /images/<sha1>.<ext>.
// Белый список формы, а не чёрный список опасного: чужой хост, javascript: и
// обход каталога отсекаются по построению, а не по перечислению.
const NEWS_IMAGE_RE = '#^/images/[0-9a-f]{40}\.(png|jpg|webp)$#';

// Ширина/высота картинки новости — то, что upload.php вернул после
// downscale_image_bytes() (см. лист изменений в
// docs/superpowers/specs/2026-08-03-safari-memory-and-i18n-design.md, задача
// 2). Валидным считается только положительное целое; всё прочее (строка не
// из цифр, ноль, отрицательное, дробное, отсутствующее значение) откатывается
// на null — так же, как у поста, сохранённого до появления этих колонок:
// cardFor() в news-page.js тогда просто не ставит атрибуты width/height,
// а не подставляет 0, который обнулил бы зарезервированную высоту.
function news_image_dim($v): ?int {
    if (is_int($v)) { return $v > 0 ? $v : null; }
    if (is_float($v) && $v == (int)$v) { $n = (int)$v; return $n > 0 ? $n : null; }
    if (is_string($v) && ctype_digit($v)) { $n = (int)$v; return $n > 0 ? $n : null; }
    return null;
}

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

    // Отсутствующий ключ и пустая строка — это "старый пост" или "админ
    // ничего не выбрал", а не мусор: обе трактуются как 'full', и только
    // непустое значение вне трёх допустимых — ошибка. Так пост, сохранённый
    // до появления этого поля, по-прежнему проходит валидацию.
    $imageSize = trim((string)($b['image_size'] ?? ''));
    if ($imageSize === '') {
        $imageSize = 'full';
    } elseif (!in_array($imageSize, NEWS_IMAGE_SIZES, true)) {
        return ['ok' => false, 'error' => 'bad image_size', 'post' => []];
    }

    $width  = news_image_dim($b['image_width']  ?? null);
    $height = news_image_dim($b['image_height'] ?? null);

    return ['ok' => true, 'error' => '', 'post' => [
        'category'     => $cat,
        'title_ru'     => $titleRu,
        'title_en'     => $titleEn,
        'body_ru'      => $bodyRu,
        'body_en'      => $bodyEn,
        'image_url'    => $image,
        'image_size'   => $imageSize,
        'image_width'  => $width,
        'image_height' => $height,
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
        ':sz'  => $p['image_size'],
        ':iw'  => $p['image_width'],
        ':ih'  => $p['image_height'],
        ':pa'  => $publishedAt,
    ];

    // Ключ 'id' в теле есть, но read_row_id вернул 0 — значит это не
    // отсутствие id (тогда это создание нового поста), а мусор вместо id
    // ("1abc", true, [1,2,3], -5, дробь...). Такое — 400, а не молчаливая
    // вставка новой записи вместо ожидаемого апдейта.
    $id = read_row_id($body);
    if ($id <= 0 && array_key_exists('id', $body)) {
        return [400, ['ok' => false, 'error' => 'bad id']];
    }
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
                    image_size = :sz, image_width = :iw, image_height = :ih,
                    published_at = :pa
              WHERE id = :id"
        );
        $stmt->execute($params + [':id' => $id]);
        return [200, ['ok' => true, 'id' => $id]];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO news (category, title_ru, title_en, body_ru, body_en, image_url, image_size,
                            image_width, image_height, published_at)
         VALUES (:c, :tr, :te, :br, :be, :img, :sz, :iw, :ih, :pa)"
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
