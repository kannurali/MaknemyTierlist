<?php
require_once __DIR__ . '/_bootstrap.php';

// Потолок ленты. Не размер страницы: пагинации нет, 51-й пост навсегда
// выталкивает самый старый. При паре постов в месяц это годы; когда упрёмся,
// добавится ?offset= — запрос уже отсортирован так, что это ничего не сломает.
const NEWS_FEED_LIMIT = 50;

function handle_news(PDO $pdo): array {
    // LIMIT подставляется из константы, а не из запроса пользователя, поэтому
    // интерполяция здесь безопасна: плейсхолдер в LIMIT переносим не везде.
    $sql = "SELECT id, category, title_ru, title_en, body_ru, body_en, image_url, image_size,
                   image_width, image_height, published_at
              FROM news
             ORDER BY published_at DESC, id DESC
             LIMIT " . NEWS_FEED_LIMIT;
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $posts = [];
    foreach ($rows as $r) {
        $posts[] = [
            'id'           => (int)$r['id'],
            'category'     => (string)$r['category'],
            'title_ru'     => (string)$r['title_ru'],
            'title_en'     => (string)$r['title_en'],
            'body_ru'      => (string)$r['body_ru'],
            'body_en'      => (string)$r['body_en'],
            'image_url'    => (string)$r['image_url'],
            'image_size'   => (string)$r['image_size'],
            // NULL остаётся NULL, а не приводится к 0: 0×0 у <img> обнулил бы
            // зарезервированную высоту, а не подсказал бы её отсутствие.
            // Пост без картинки и пост, сохранённый до появления этих
            // колонок, неотличимы для клиента — и это правильно, оба случая
            // cardFor() в news-page.js обрабатывает одинаково.
            'image_width'  => $r['image_width']  !== null ? (int)$r['image_width']  : null,
            'image_height' => $r['image_height'] !== null ? (int)$r['image_height'] : null,
            'published_at' => (int)$r['published_at'],
        ];
    }
    return [200, ['posts' => $posts]];
}

if (!defined('TESTING')) {
    // Лента открыта всем, поэтому единственная защита от вычерпывания — частота.
    // 120 запросов в час: читатель открывает страницу и щёлкает категории (это
    // один запрос), так что для живого человека потолок недостижим.
    $key = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!rate_limit_allow('news', $key, 120, 3600, time())) {
        json_out(['error' => 'rate_limited'], 429);
        exit;
    }
    // Кэшировать нечем: у ленты нет rev, а заводить его — значит вернуть ту
    // самую связку с тирлистом, ради разрыва которой таблица и отдельная.
    header('Cache-Control: no-cache');
    [$status, $payload] = handle_news(db());
    json_out($payload, $status);
}
