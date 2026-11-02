<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/chat.php';

// Фото в чате (api/lib/chat_photos.php): файл всегда пересобирается, видят его
// только участники диалога, а модератор — только после жалобы.

const NOW = 1790000000;
const ALICE = '5';
const BOB = '9';
const STRANGER = '7';

function cp_db(bool $photos = true): PDO {
    $pdo = test_db();
    $pdo->exec('CREATE TABLE chat_threads (id INTEGER PRIMARY KEY AUTOINCREMENT, a_id INTEGER NOT NULL,
        b_id INTEGER NOT NULL, last_at INTEGER NOT NULL DEFAULT 0, UNIQUE (a_id, b_id))');
    $pdo->exec('CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL,
        sender_id INTEGER NOT NULL, body TEXT NOT NULL, created_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE chat_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL,
        author_id INTEGER NOT NULL, target_id INTEGER NOT NULL, stars INTEGER NOT NULL, body TEXT, created_at INTEGER NOT NULL)');
    if ($photos) {
        // Зеркалит docs/migrations/2026-11-02-chat-photos.sql.
        $pdo->exec('CREATE TABLE chat_photos (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL, file TEXT NOT NULL, w INTEGER NOT NULL, h INTEGER NOT NULL,
            bytes INTEGER NOT NULL, created_at INTEGER NOT NULL, reported_at INTEGER NOT NULL DEFAULT 0,
            reviewed_at INTEGER NOT NULL DEFAULT 0, reviewed_by INTEGER NOT NULL DEFAULT 0,
            removed_at INTEGER NOT NULL DEFAULT 0)');
    }
    $ins = $pdo->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at)
                          VALUES (?, ?, ?, \'\', 0, 0)');
    foreach ([[5, 'alice', 'Alice'], [9, 'bob', 'Bob'], [7, 'eve', 'Eve']] as $u) { $ins->execute($u); }
    $pdo->exec('INSERT INTO chat_threads (a_id, b_id) VALUES (5, 9)');
    return $pdo;
}

function cp_dir(): string {
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/nx-photos-' . bin2hex(random_bytes(6));
    register_shutdown_function(function () use ($dir) {
        foreach ((array)glob($dir . '/{,.}*', GLOB_BRACE) as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($dir);
    });
    return $dir;
}

function cp_files(string $dir): array {
    return array_map('basename', array_values(array_filter((array)glob($dir . '/*'), 'is_file')));
}

function cp_jpeg(int $w = 800, int $h = 600): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
    ob_start();
    imagejpeg($im, null, 90);
    return (string)ob_get_clean();
}

// Вклейка «EXIF» сразу за заголовком JPEG: сегмент APP1 с заданными байтами.
function cp_with_exif(string $jpeg, string $secret): string {
    $payload = "Exif\0\0" . $secret;
    return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
}

function cp_png_header(int $w, int $h): string {
    $chunk = 'IHDR' . pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $chunk . pack('N', crc32($chunk));
}

function cp_encode_error(string $bytes): string {
    try {
        chat_photo_encode($bytes);
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

// Диалог, где Боб уже ответил, и фото от Алисы. Возвращает номер фото.
function cp_sent(PDO $pdo, string $dir, int $at = NOW): int {
    chat_send($pdo, BOB, 1, 'привет', $at - 10);
    [$code, $out] = chat_photo_send($pdo, ALICE, 1, cp_jpeg(), $dir, $at);
    assert_eq(200, $code, 'фото отправлено');
    return (int)$out['message']['photo']['id'];
}

// --- метка в теле сообщения ---------------------------------------------------

test('метка: разбирается строго, обычной отправкой её не поставить', function () {
    assert_eq(12, chat_photo_id('[photo:12]'));
    foreach (['[photo:12] смотри', 'x[photo:12]', '[photo:abc]', '[photo:]', "[photo:1]\n"] as $body) {
        assert_eq(0, chat_photo_id($body), $body);
    }
    $pdo = cp_db();
    [$code, $out] = chat_send($pdo, ALICE, 1, '[photo:1]', NOW);
    assert_eq(400, $code, 'подделка метки');
    assert_eq('bad_photo', $out['error']);
    [$code] = chat_send($pdo, ALICE, 1, '  [photo:1]  ', NOW);
    assert_eq(400, $code, 'с пробелами тоже');
    [$code, $out] = chat_send($pdo, ALICE, 1, '[photo:1] смотри', NOW);
    assert_eq(200, $code, 'обычный текст с такими буквами проходит');
    assert_eq('[photo:1] смотри', $out['message']['body']);
    assert_eq(false, isset($out['message']['photo']), 'и фото не становится');
});

test('фильтр слов не ломает метку, даже если в списке есть «photo»', function () {
    $rules = chat_filter_compile(['photo']);
    assert_eq('[photo:12]', chat_filter_apply('[photo:12]', $rules)['text']);
    assert_eq('*** тут', chat_filter_apply('photo тут', $rules)['text'], 'обычный текст фильтруется');
});

// --- пересборка файла ---------------------------------------------------------

test('файл собирается заново: метаданные и хвост исчезают', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $dirty = cp_with_exif(cp_jpeg(), 'GPS-SECRET-55.7558') . '<?php system($_GET["c"]); ?>';
    assert_true(strpos($dirty, 'GPS-SECRET') !== false, 'исходник с метками');
    $made = chat_photo_encode($dirty);
    assert_eq(false, strpos($made['photo'], 'GPS-SECRET'), 'нет геометки');
    assert_eq(false, strpos($made['photo'], '<?php'), 'нет хвоста');
    assert_eq(false, strpos($made['thumb'], 'GPS-SECRET'), 'и в миниатюре нет');
    assert_true(in_array(image_ext_for($made['photo']), ['webp', 'jpg'], true), 'формат сервера');
    assert_eq(800, $made['w']);
    assert_eq(600, $made['h']);
});

test('размеры: большое ужимается, маленькое не растёт, миниатюра только пятна', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $big = chat_photo_encode(cp_jpeg(3000, 1000));
    assert_eq(1280, $big['w']);
    assert_eq(427, $big['h']);
    $thumb = getimagesizefromstring($big['thumb']);
    assert_eq(CHAT_PHOTO_THUMB_SIDE, max($thumb[0], $thumb[1]), 'длинная сторона миниатюры');
    assert_true(strlen($big['thumb']) < 2000, 'миниатюра лёгкая');

    $small = chat_photo_encode(cp_jpeg(100, 50));
    assert_eq(100, $small['w']);
    assert_eq(50, $small['h']);
});

test('отказы: не картинка, GIF, лишний вес, бомба из пикселей', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    assert_eq('bad_image', cp_encode_error('просто текст'), 'текст');
    assert_eq('bad_image', cp_encode_error('<?php echo 1; ?>'), 'php');
    assert_eq('bad_image', cp_encode_error("GIF89a\x01\x00\x01\x00\x00\x00\x00;"), 'GIF не принимается');
    assert_eq('bad_image', cp_encode_error("\xFF\xD8\xFF\xE0обрезанный"), 'подпись JPEG без картинки');
    assert_eq('too_big', cp_encode_error(str_repeat('x', CHAT_PHOTO_MAX_BYTES + 1)), 'вес');
    assert_eq('too_big', cp_encode_error(cp_png_header(9000, 9000)), '81 Мпикс до декодирования');
});

// --- кому можно отправлять ----------------------------------------------------

test('отправка: аноним, чужой, первым и после лимита — нельзя', function () {
    $pdo = cp_db();
    $dir = cp_dir();
    assert_eq([401, 'not_logged_in'], cp_refused($pdo, '', $dir), 'аноним');
    assert_eq([403, 'not_a_member'], cp_refused($pdo, STRANGER, $dir), 'чужой');
    assert_eq([403, 'need_reply'], cp_refused($pdo, ALICE, $dir), 'собеседник ещё не отвечал');

    chat_send($pdo, ALICE, 1, 'привет', NOW);
    assert_eq([403, 'need_reply'], cp_refused($pdo, ALICE, $dir), 'собственные сообщения не считаются');
    assert_eq(null, chat_photo_gate($pdo, BOB, 1, NOW), 'Бобу уже ответили: Алиса писала');

    chat_send($pdo, BOB, 1, 'привет', NOW);
    assert_eq(null, chat_photo_gate($pdo, ALICE, 1, NOW), 'после ответа можно');

    $ins = $pdo->prepare("INSERT INTO chat_photos (thread_id, sender_id, file, w, h, bytes, created_at)
                          VALUES (1, 5, 'x.webp', 1, 1, 1, ?)");
    for ($i = 0; $i < CHAT_PHOTO_PER_DAY; $i++) { $ins->execute([NOW - 100]); }
    assert_eq([429, 'daily_limit'], cp_refused($pdo, ALICE, $dir), 'суточный лимит');
    assert_eq(null, chat_photo_gate($pdo, ALICE, 1, NOW + 86400), 'через сутки снова можно');
    assert_eq(null, chat_photo_gate($pdo, BOB, 1, NOW), 'лимит у каждого свой');
});

function cp_refused(PDO $pdo, string $me, string $dir): array {
    [$code, $out] = chat_photo_send($pdo, $me, 1, 'не важно', $dir, NOW);
    return [$code, $out['error'] ?? ''];
}

test('до миграции фото выключены, а остальной чат работает', function () {
    $pdo = cp_db(false);
    chat_send($pdo, BOB, 1, 'привет', NOW);
    assert_eq([503, 'not_ready'], cp_refused($pdo, ALICE, cp_dir()), 'отправка');
    assert_eq(null, chat_photo_find($pdo, 1), 'поиск');
    assert_eq([], chat_photo_open_reports($pdo), 'жалобы');
    [, $out] = handle_chat($pdo, ['user_id' => ALICE], '1', NOW);
    assert_eq(false, $out['photos'], 'кнопки нет');
    assert_eq(1, count($out['messages']), 'переписка на месте');

    [, $out] = handle_chat(cp_db(), ['user_id' => ALICE], '1', NOW);
    assert_eq(true, $out['photos'], 'после миграции есть');
    [, $out] = handle_chat(cp_db(), [], null, NOW);
    assert_eq(false, $out['photos'], 'гостю нет');
});

test('отправка: файл на диске, сообщение в ветке, ответ совпадает с чтением', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $pdo = cp_db();
    $dir = cp_dir();
    chat_send($pdo, BOB, 1, 'привет', NOW - 10);

    [$code, $out] = chat_photo_send($pdo, ALICE, 1, cp_jpeg(), $dir, NOW);
    assert_eq(200, $code);
    $m = $out['message'];
    assert_eq(true, $m['mine']);
    $id = $m['photo']['id'];
    assert_eq('[photo:' . $id . ']', $m['body']);
    assert_eq(['id' => $id, 'w' => 800, 'h' => 600, 'gone' => false, 'reported' => false], $m['photo']);

    $files = cp_files($dir);
    assert_eq(2, count($files), 'фото и миниатюра');
    $row = chat_photo_find($pdo, $id);
    assert_true(in_array($row['file'], $files, true), 'имя из базы');
    assert_true(preg_match('/^[0-9a-f]{32}\.(webp|jpg)\z/', $row['file']) === 1, 'случайное имя');
    assert_eq((int)$row['bytes'], filesize($dir . '/' . $row['file']) + filesize($dir . '/' . chat_photo_thumb_name($row['file'])));
    assert_true(is_file($dir . '/.htaccess'), 'запрет на случай каталога в веб-корне');

    $read = chat_messages($pdo, BOB, 1, NOW);
    $last = $read[count($read) - 1];
    assert_eq(false, $last['mine']);
    assert_eq($m['photo'], $last['photo'], 'получатель видит то же');
    assert_eq(false, isset($read[0]['photo']), 'у текста поля нет');

    assert_eq(NOW, (int)$pdo->query('SELECT last_at FROM chat_threads WHERE id = 1')->fetchColumn(), 'ветка поднялась');
    assert_eq($m['body'], chat_last_messages($pdo, [1])[1]['body'], 'список диалогов видит метку');
});

test('плохой файл не оставляет ни сообщений, ни файлов', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $pdo = cp_db();
    $dir = cp_dir();
    chat_send($pdo, BOB, 1, 'привет', NOW);
    [$code, $out] = chat_photo_send($pdo, ALICE, 1, 'мусор', $dir, NOW);
    assert_eq(400, $code);
    assert_eq('bad_image', $out['error']);
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn());
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM chat_photos')->fetchColumn());
    assert_eq([], cp_files($dir));
});

test('потолок на диске: дальше сайт не отдаёт место под фото', function () {
    $pdo = cp_db();
    chat_send($pdo, BOB, 1, 'привет', NOW);
    $pdo->prepare("INSERT INTO chat_photos (thread_id, sender_id, file, w, h, bytes, created_at)
                   VALUES (1, 9, 'x.webp', 1, 1, ?, ?)")->execute([CHAT_PHOTO_DISK_MAX, NOW]);
    assert_eq([503, 'storage_full'], cp_refused($pdo, ALICE, cp_dir()));
});

// --- кто видит файл -----------------------------------------------------------

test('доступ: участники да, чужой нет, модератор только с жалобой', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $pdo = cp_db();
    $dir = cp_dir();
    $id = cp_sent($pdo, $dir);
    $file = chat_photo_find($pdo, $id)['file'];

    assert_eq($dir . '/' . $file, chat_photo_path($pdo, ALICE, false, $id, false, $dir, NOW), 'отправитель');
    assert_eq($dir . '/' . $file, chat_photo_path($pdo, BOB, false, $id, false, $dir, NOW), 'получатель');
    assert_eq($dir . '/' . chat_photo_thumb_name($file), chat_photo_path($pdo, BOB, false, $id, true, $dir, NOW), 'миниатюра');
    assert_eq(null, chat_photo_path($pdo, STRANGER, false, $id, false, $dir, NOW), 'чужой');
    assert_eq(null, chat_photo_path($pdo, '', false, $id, false, $dir, NOW), 'аноним');
    assert_eq(null, chat_photo_path($pdo, '', true, $id, false, $dir, NOW), 'аноним с флагом');
    assert_eq(null, chat_photo_path($pdo, STRANGER, true, $id, false, $dir, NOW), 'модератор без жалобы');
    assert_eq(null, chat_photo_path($pdo, ALICE, false, $id + 1, false, $dir, NOW), 'нет такого фото');
    assert_eq(null, chat_photo_path($pdo, ALICE, false, $id, false, $dir, NOW + CHAT_PHOTO_KEEP_DAYS * 86400 + 1), 'просрочено');

    chat_photo_report($pdo, BOB, $id, NOW);
    assert_true(chat_photo_path($pdo, STRANGER, true, $id, false, $dir, NOW) !== null, 'модератор с жалобой');
    assert_eq(null, chat_photo_path($pdo, STRANGER, false, $id, false, $dir, NOW), 'жалоба не открывает чужим');
    assert_eq(null, chat_photo_path($pdo, BOB, false, $id, false, $dir, NOW), 'кто пожаловался, больше не видит');
    assert_true(chat_photo_path($pdo, ALICE, false, $id, false, $dir, NOW) !== null, 'отправитель видит своё');
});

test('метка с чужим номером не раскрывает чужое фото', function () {
    $pdo = cp_db();
    $pdo->exec('INSERT INTO chat_threads (a_id, b_id) VALUES (5, 7)');
    $pdo->exec("INSERT INTO chat_photos (thread_id, sender_id, file, w, h, bytes, created_at)
                VALUES (2, 7, 'x.webp', 640, 480, 1, " . NOW . ')');
    $pdo->exec("INSERT INTO chat_messages (thread_id, sender_id, body, created_at) VALUES (1, 9, '[photo:1]', " . NOW . ')');
    $m = chat_messages($pdo, ALICE, 1, NOW)[0];
    assert_eq(['id' => 1, 'w' => 0, 'h' => 0, 'gone' => true, 'reported' => false], $m['photo']);
});

// --- жалобы ---------------------------------------------------------------------

test('жалоба: только получатель, повтор не считается новой', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $pdo = cp_db();
    $dir = cp_dir();
    $id = cp_sent($pdo, $dir);

    assert_eq(401, chat_photo_report($pdo, '', $id, NOW)[0], 'аноним');
    assert_eq(404, chat_photo_report($pdo, STRANGER, $id, NOW)[0], 'чужой');
    assert_eq(404, chat_photo_report($pdo, BOB, $id + 1, NOW)[0], 'нет фото');
    [$code, $out] = chat_photo_report($pdo, ALICE, $id, NOW);
    assert_eq(400, $code, 'на себя');
    assert_eq('own_photo', $out['error']);

    assert_eq([200, ['ok' => true, 'first' => true]], chat_photo_report($pdo, BOB, $id, NOW + 5));
    assert_eq([200, ['ok' => true, 'first' => false]], chat_photo_report($pdo, BOB, $id, NOW + 6), 'сигнал уходит один раз');
    assert_eq(NOW + 5, (int)chat_photo_find($pdo, $id)['reported_at'], 'время первой жалобы');
});

test('жалоба скрывает фото у получателя и закрывает отправителю новые фото', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $pdo = cp_db();
    $dir = cp_dir();
    $id = cp_sent($pdo, $dir);
    chat_photo_report($pdo, BOB, $id, NOW);

    $last = function (string $me) use ($pdo) {
        $all = chat_messages($pdo, $me, 1, NOW);
        return $all[count($all) - 1]['photo'];
    };
    assert_eq(true, $last(BOB)['reported'], 'у получателя скрыто');
    assert_eq(false, $last(ALICE)['reported'], 'отправитель о жалобе из ленты не узнаёт');

    assert_eq([403, 'reported'], cp_refused($pdo, ALICE, $dir), 'новые фото закрыты');
    assert_eq(null, chat_photo_gate($pdo, BOB, 1, NOW), 'получателю нет');

    assert_eq('kept', chat_photo_review($pdo, '2', $id, 'keep', $dir, NOW));
    assert_eq(null, chat_photo_gate($pdo, ALICE, 1, NOW), 'после разбора снова можно');
    assert_eq(null, chat_photo_path($pdo, BOB, false, $id, false, $dir, NOW), 'получателю фото не возвращается');
    assert_eq(true, $last(BOB)['reported'], 'и в ленте скрыто');
    assert_true(chat_photo_path($pdo, ALICE, false, $id, false, $dir, NOW) !== null, 'файл остался');
});

test('разбор: удаление стирает файлы, повтор и лишнее отвергаются', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $pdo = cp_db();
    $dir = cp_dir();
    $id = cp_sent($pdo, $dir);

    assert_eq('not_found', chat_photo_review($pdo, '2', $id, 'remove', $dir, NOW), 'без жалобы удалить нельзя');
    assert_eq(2, count(cp_files($dir)), 'файлы на месте');

    chat_photo_report($pdo, BOB, $id, NOW);
    assert_eq('bad_action', chat_photo_review($pdo, '2', $id, 'ban', $dir, NOW));
    assert_eq(1, count(chat_photo_open_reports($pdo)), 'жалоба открыта');

    assert_eq('removed', chat_photo_review($pdo, '2', $id, 'remove', $dir, NOW + 1));
    assert_eq([], cp_files($dir), 'файлов нет');
    assert_eq('already', chat_photo_review($pdo, '2', $id, 'keep', $dir, NOW + 2));
    assert_eq([], chat_photo_open_reports($pdo), 'жалоба закрыта');

    $row = chat_photo_find($pdo, $id);
    assert_eq(NOW + 1, (int)$row['removed_at']);
    assert_eq(2, (int)$row['reviewed_by']);

    $m = chat_messages($pdo, ALICE, 1, NOW + 2);
    assert_eq(true, $m[count($m) - 1]['photo']['gone'], 'в ленте удалено у обоих');
    assert_eq(null, chat_photo_path($pdo, STRANGER, true, $id, false, $dir, NOW + 2), 'модератору файла больше нет');
    assert_eq(null, chat_photo_gate($pdo, ALICE, 1, NOW + 2), 'разобрано: отправитель не заблокирован');
});

test('список жалоб: старые сверху, с ником отправителя и пожаловавшегося', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $pdo = cp_db();
    $dir = cp_dir();
    $first = cp_sent($pdo, $dir);
    [, $out] = chat_photo_send($pdo, BOB, 1, cp_jpeg(), $dir, NOW + 1);
    $second = $out['message']['photo']['id'];

    chat_photo_report($pdo, ALICE, $second, NOW + 2);
    chat_photo_report($pdo, BOB, $first, NOW + 3);

    $list = chat_photo_open_reports($pdo);
    assert_eq([$second, $first], array_column($list, 'id'));
    assert_eq(['id' => BOB, 'nick' => 'Bob'], $list[0]['sender']);
    assert_eq(['id' => ALICE, 'nick' => 'Alice'], $list[0]['reporter']);
    assert_eq(NOW + 2, $list[0]['at']);
});

// --- уборка ---------------------------------------------------------------------

test('уборка: через 30 дней файл стирается, неразобранная жалоба держит его', function () {
    if (!function_exists('imagecreatetruecolor')) { return; }
    $pdo = cp_db();
    $dir = cp_dir();
    $old = cp_sent($pdo, $dir);
    [, $out] = chat_photo_send($pdo, BOB, 1, cp_jpeg(), $dir, NOW);
    $held = $out['message']['photo']['id'];
    chat_photo_report($pdo, ALICE, $held, NOW);

    $late = NOW + CHAT_PHOTO_KEEP_DAYS * 86400 + 1;
    [, $out] = chat_photo_send($pdo, ALICE, 1, cp_jpeg(), $dir, $late);
    $fresh = $out['message']['photo']['id'];

    assert_eq($late, (int)chat_photo_find($pdo, $old)['removed_at'], 'просроченное убрала следующая отправка');
    assert_eq(0, (int)chat_photo_find($pdo, $held)['removed_at'], 'с жалобой осталось');
    assert_eq(0, (int)chat_photo_find($pdo, $fresh)['removed_at'], 'новое осталось');
    assert_eq(4, count(cp_files($dir)), 'два фото по два файла');
    assert_true(chat_photo_path($pdo, STRANGER, true, $held, false, $dir, $late) !== null, 'модератору видно');
    assert_eq(null, chat_photo_path($pdo, BOB, false, $held, false, $dir, $late), 'участнику срок вышел');

    assert_eq('kept', chat_photo_review($pdo, '2', $held, 'keep', $dir, $late));
    assert_eq(1, chat_photo_sweep($pdo, $dir, $late), 'после разбора уходит и оно');
    assert_eq(2, count(cp_files($dir)));
});

test('уборка ограничена пачкой: отправка не зависает на тысяче файлов', function () {
    $pdo = cp_db();
    $ins = $pdo->prepare("INSERT INTO chat_photos (thread_id, sender_id, file, w, h, bytes, created_at)
                          VALUES (1, 5, ?, 1, 1, 1, 1)");
    for ($i = 0; $i < CHAT_PHOTO_SWEEP_BATCH + 5; $i++) { $ins->execute(["f$i.webp"]); }
    assert_eq(CHAT_PHOTO_SWEEP_BATCH, chat_photo_sweep($pdo, cp_dir(), NOW));
    assert_eq(5, chat_photo_sweep($pdo, cp_dir(), NOW));
});

// --- каталог и отдача ------------------------------------------------------------

test('каталог лежит вне веб-корня, config.php может его заменить', function () {
    $dir = str_replace('\\', '/', chat_photo_dir([]));
    assert_eq(false, strpos($dir . '/', '/public_html/'), 'не в public_html: ' . $dir);
    assert_eq('/x/y', chat_photo_dir(['chat_photos_dir' => '/x/y/']));
    assert_eq('image/webp', chat_photo_mime('a.webp'));
    assert_eq('image/jpeg', chat_photo_mime('a.jpg'));
    assert_eq('a.t.webp', chat_photo_thumb_name('a.webp'));
});

test('отдача: кеш только у посетителя, тип не угадывается', function () {
    $src = file_get_contents(__DIR__ . '/../public_html/api/chat_photo.php');
    assert_true(strpos($src, "X-LiteSpeed-Cache-Control: no-cache") !== false, 'мимо кеша LiteSpeed');
    assert_true(strpos($src, 'Cache-Control: private') !== false, 'private');
    assert_true(strpos($src, 'X-Content-Type-Options: nosniff') !== false, 'nosniff');
    assert_true(strpos($src, 'session_write_close') !== false, 'сессия отпущена');
    assert_eq(false, strpos($src, 'lscache_public('), 'в общий кеш не кладётся');
});

test('страница: метки и лимиты разошлись бы молча, поэтому сверяем', function () {
    $js = file_get_contents(__DIR__ . '/../public_html/js/chat-page.js');
    assert_true(strpos($js, 'var PHOTO_RE = /^\[photo:(\d{1,18})\]$/;') !== false, 'метка в js');
    assert_true(strpos($js, 'var PHOTO_SIDE = ' . CHAT_PHOTO_MAX_SIDE . ';') !== false, 'сторона в js');
    assert_true(strpos(CHAT_PHOTO_RE, '\[photo:(\d{1,18})\]') !== false, 'метка в php');
});

run_tests();
