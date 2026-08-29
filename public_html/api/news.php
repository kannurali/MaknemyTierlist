<?php
require_once __DIR__ . '/_bootstrap.php';

// Потолок ленты. Не размер страницы: пагинации нет, 51-й пост навсегда
// выталкивает самый старый. При паре постов в месяц это годы; когда упрёмся,
// добавится ?offset= — запрос уже отсортирован так, что это ничего не сломает.
const NEWS_FEED_LIMIT = 50;

// Отличает "таблицы вообще нет" (законное состояние до ручной миграции — тот
// же приём, что promo_load() в api/promo.php) от ЛЮБОЙ другой PDOException —
// в первую очередь от рассинхрона схемы в окне между выкладкой кода (уезжает
// пушем) и миграцией (запускается руками, см. docs/migrations/2026-08-18-
// image-customisation.sql): SELECT в handle_news() ниже просит колонки
// image_pct/image_align/image_wrap, которых в старой схеме ещё нет, и это
// СОВСЕМ другая ситуация, чем "постов пока нет". SQLite (тесты) и MySQL
// (прод) сообщают об отсутствующей таблице по-разному, поэтому проверяются
// оба формата.
function news_table_missing(PDOException $e): bool {
    if (($e->errorInfo[1] ?? null) === 1146) { return true; }   // MySQL: ER_NO_SUCH_TABLE
    return strpos($e->getMessage(), 'no such table') !== false; // SQLite
}

// Отличает «нет колонки body_json» от любого другого рассинхрона схемы.
// Именно этот случай — окно между выкладкой кода пушем и миграцией
// docs/migrations/2026-08-29-news-blocks.sql, которая запускается руками, —
// обязан деградировать до легаси-пути, а не ронять ленту: /news объявлен в
// sitemap.xml. Любая другая пропавшая колонка остаётся исключением, как и
// была (см. news_table_missing() выше — тот же приём, другая причина).
//
// Проверка идёт по ИМЕНИ колонки, а не по одному лишь коду ошибки: таблица
// старой формы (без image_pct и заодно без body_json) не должна сойти за
// «миграция ещё не запускалась» — SQL перечисляет image_pct раньше, и обе
// СУБД называют в ошибке именно её.
function news_body_json_missing(PDOException $e): bool {
    $msg = $e->getMessage();
    if (($e->errorInfo[1] ?? null) === 1054 && strpos($msg, 'body_json') !== false) {
        return true; // MySQL: ER_BAD_FIELD_ERROR
    }
    return strpos($msg, 'no such column: body_json') !== false; // SQLite
}

function handle_news(PDO $pdo): array {
    // LIMIT подставляется из константы, а не из запроса пользователя, поэтому
    // интерполяция здесь безопасна: плейсхолдер в LIMIT переносим не везде.
    $cols = "id, category, title_ru, title_en, body_ru, body_en, image_url, image_pct,
                   image_align, image_wrap, image_width, image_height, published_at, likes";
    $tail = " FROM news
             ORDER BY published_at DESC, id DESC
             LIMIT " . NEWS_FEED_LIMIT;
    $sql = "SELECT " . $cols . ", body_json" . $tail;
    // Нет таблицы — значит «постов пока нет», а не ошибка. Тот же приём, что
    // и в promo_load(): schema.sql на боевой сервер не уезжает (.cpanel.yml
    // копирует только public_html/), таблицу заводят руками, и до этого
    // момента /news отдавал голый HTTP 500 — при том, что он объявлен в
    // sitemap.xml и его придёт читать поисковик. Пустая лента в этом
    // состоянии честнее: страница открывается и говорит, что новостей нет.
    $hasBlocks = true;
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (news_table_missing($e)) {
            return [200, ['posts' => []]];
        }
        if (news_body_json_missing($e)) {
            // Миграция ещё не запускалась: блоков нет ни у одного поста, и
            // лента целиком идёт легаси-путём — ровно то, что она и делала
            // вчера.
            $hasBlocks = false;
            $rows = $pdo->query("SELECT " . $cols . $tail)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Не глотать молча: рассинхрон схемы или обрыв соединения —
            // реальная поломка, а не "новостей пока нет". Пусть долетит до
            // news_dispatch() ниже, который залогирует настоящую причину и
            // ответит 503, а не фальшивой пустой лентой и не голой 500кой.
            throw $e;
        }
    }

    $posts = [];
    foreach ($rows as $r) {
        // Блоки разбираются на сервере, а не в браузере: иначе каждый клиент
        // повторял бы JSON.parse со своим try/catch, а битая строка в базе
        // ломала бы страницу вместо одного поста. Битый JSON здесь — это
        // просто «блоков нет», то есть легаси-путь.
        $blocks = null;
        if ($hasBlocks && !empty($r['body_json'])) {
            $decoded = json_decode($r['body_json'], true);
            if (is_array($decoded)) { $blocks = $decoded; }
        }
        $posts[] = [
            'id'           => (int)$r['id'],
            'category'     => (string)$r['category'],
            'title_ru'     => (string)$r['title_ru'],
            'title_en'     => (string)$r['title_en'],
            'body_ru'      => (string)$r['body_ru'],
            'body_en'      => (string)$r['body_en'],
            'image_url'    => (string)$r['image_url'],
            'image_pct'    => (int)$r['image_pct'],
            'image_align'  => (string)$r['image_align'],
            'image_wrap'   => (bool)((int)$r['image_wrap']),
            // NULL остаётся NULL, а не приводится к 0: 0×0 у <img> обнулил бы
            // зарезервированную высоту, а не подсказал бы её отсутствие.
            // Пост без картинки и пост, сохранённый до появления этих
            // колонок, неотличимы для клиента — и это правильно, оба случая
            // cardFor() в news-page.js обрабатывает одинаково.
            'image_width'  => $r['image_width']  !== null ? (int)$r['image_width']  : null,
            'image_height' => $r['image_height'] !== null ? (int)$r['image_height'] : null,
            'published_at' => (int)$r['published_at'],
            // (int), а не голое значение из PDO: и MySQL, и SQLite отдают
            // числа строками, а сердечко в cardFor() (news-page.js) делает
            // с ним арифметику (±1) и сравнивает с 0 — строка "0" в этой
            // роли работала бы только пока никто не пишет строгие сравнения.
            'likes'        => (int)$r['likes'],
            // null, а не [] и не '': у поста без блоков (и у любого поста,
            // пока миграция не запускалась) их нет вовсе, а пустой массив
            // означал бы «блочный пост без единого блока» — другое состояние.
            'body_json'    => $blocks,
        ];
    }
    return [200, ['posts' => $posts]];
}

// Обёртка боевого пути вокруг handle_news(). Любая PDOException, которая
// не является "таблицы ещё нет" (см. news_table_missing() выше), долетает
// сюда — рассинхрон схемы в окне между push кода и ручной миграцией, обрыв
// соединения с БД и т. п. Настоящее сообщение (может содержать имена
// колонок/DSN) уходит в error_log(), а не наружу; наружу — 503 с маленьким
// JSON. 503, а не 500: это сигнал "попробуй ещё раз", на который краулер
// (страница объявлена в sitemap.xml как changefreq daily) реагирует
// повтором, а не фиксирует как постоянный сбой. И 503, а не фальшивая
// пустая лента 200кой: news-page.js#load() при не-ok ответе показывает
// читателю состояние ошибки с кнопкой «Повторить» — молчаливая пустота
// прячет поломку и от читателя, и от того, кто мог бы её заметить.
//
// $pdo необязателен и по умолчанию берётся из db() ВНУТРИ try — само
// подключение тоже бросает PDOException при обрыве соединения, и вызов
// db() снаружи (как аргумент) остался бы этим try не пойман. Тесты
// подставляют свой $pdo напрямую, минуя db() и config.php целиком.
function news_dispatch(?PDO $pdo = null): array {
    try {
        return handle_news($pdo ?? db());
    } catch (PDOException $e) {
        error_log('news.php: ' . $e->getMessage());
        return [503, ['error' => 'temporarily_unavailable']];
    }
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
    [$status, $payload] = news_dispatch();
    json_out($payload, $status);
}
