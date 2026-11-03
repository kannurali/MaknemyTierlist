<?php
// Фото в чате: приём, хранение, доступ, жалобы, уборка.
//
// В переписке фото — обычное сообщение с телом «[photo:<номер>]» (как стикер,
// см. CHAT_PHOTO_RE в api/lib/chat.php), а всё остальное о нём лежит в
// chat_photos. Файлы — НЕ в public_html/images: там они были бы открыты любому
// по адресу и кешировались бы навсегда. Каталог лежит выше веб-корня, а отдаёт
// файл только api/chat_photo.php после проверки chat_photo_can_view.
//
// Каждое фото декодируется и рисуется заново (chat_photo_encode). Это главная
// защита приёма: исчезают EXIF с геопозицией и всё, что приклеено к файлу после
// картинки, а наружу уходит только то, что нарисовал сервер. Нет GD — фото не
// принимаются вовсе: хранить исходные байты нельзя.

require_once __DIR__ . '/images.php';

const CHAT_PHOTO_MAX_BYTES = 6 * 1024 * 1024;
// Страница сама ужимает снимок до этой стороны, сервер просто не верит ей.
const CHAT_PHOTO_MAX_SIDE  = 1280;
// Такая миниатюра — только пятна цвета, текст и лица на ней не прочитать.
// Получатель видит её вместо фото, пока сам не нажмёт «Показать».
const CHAT_PHOTO_THUMB_SIDE = 24;
const CHAT_PHOTO_QUALITY    = 80;
const CHAT_PHOTO_PER_DAY    = 20;
const CHAT_PHOTO_KEEP_DAYS  = 30;
// Общий потолок на диске. Тариф один на сайт и базу: переполненный фотографиями
// диск уронил бы всё остальное.
const CHAT_PHOTO_DISK_MAX   = 2 * 1024 * 1024 * 1024;
// Cron для этого не заводим: просроченное убирает следующая отправка.
const CHAT_PHOTO_SWEEP_BATCH = 20;

// Есть ли таблица. На бою её нет до миграции 2026-11-02-chat-photos.sql — тогда
// кнопки в чате нет, а остальная переписка работает как раньше.
function chat_photo_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM chat_photos LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Выше веб-корня, рядом с config.php. chat_photos_dir в config.php переопределяет.
function chat_photo_dir(array $cfg): string {
    $base = dirname(CONFIG_PATH);
    $dir  = $cfg['chat_photos_dir'] ?? ((realpath($base) ?: $base) . '/chat-photos');
    return rtrim((string)$dir, '/\\');
}

// Запрет на случай, если каталог в config.php всё же окажется внутри веб-корня.
function chat_photo_dir_ensure(string $dir): bool {
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) { return false; }
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) { @file_put_contents($guard, "Require all denied\n"); }
    return is_writable($dir);
}

function chat_photo_format(): string {
    return function_exists('imagewebp') ? 'webp' : 'jpg';
}

function chat_photo_mime(string $file): string {
    return substr($file, -4) === 'webp' ? 'image/webp' : 'image/jpeg';
}

function chat_photo_thumb_name(string $file): string {
    return preg_replace('/\.(webp|jpg)\z/', '.t.$1', $file);
}

/** Вписать в сторону $side и нарисовать на белом: прозрачность фото не нужна. */
function chat_photo_draw($src, int $w, int $h, int $side) {
    $scale = min(1, $side / max($w, $h));
    $dstW  = max(1, (int)round($w * $scale));
    $dstH  = max(1, (int)round($h * $scale));
    $dst   = imagecreatetruecolor($dstW, $dstH);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $w, $h);
    return $dst;
}

function chat_photo_bytes($img, string $format, int $quality): string {
    ob_start();
    $ok = $format === 'webp' ? imagewebp($img, null, $quality) : imagejpeg($img, null, $quality);
    $out = (string)ob_get_clean();
    if (!$ok || $out === '') { throw new RuntimeException('failed'); }
    return $out;
}

/**
 * Байты от посетителя → новые файлы. Исключение несёт код ошибки для ответа:
 * too_big, bad_image, no_gd или failed.
 *
 * Размер проверяется ДО декодирования: PNG на пару килобайт может объявить
 * 9000x9000 и съесть при распаковке всю память процесса.
 */
function chat_photo_encode(string $bytes): array {
    if (!function_exists('imagecreatefromstring')) { throw new RuntimeException('no_gd'); }
    if (strlen($bytes) > CHAT_PHOTO_MAX_BYTES)     { throw new RuntimeException('too_big'); }
    if (!in_array(image_ext_for($bytes), ['png', 'jpg', 'webp'], true)) {
        throw new RuntimeException('bad_image');
    }
    $size = @getimagesizefromstring($bytes);
    if ($size === false || $size[0] < 1 || $size[1] < 1) { throw new RuntimeException('bad_image'); }
    [$w, $h] = $size;
    if ($w * $h > MAX_SOURCE_PIXELS) { throw new RuntimeException('too_big'); }

    $src = @imagecreatefromstring($bytes);
    if ($src === false) { throw new RuntimeException('bad_image'); }

    $format = chat_photo_format();
    $big    = chat_photo_draw($src, $w, $h, CHAT_PHOTO_MAX_SIDE);
    $tiny   = chat_photo_draw($src, $w, $h, CHAT_PHOTO_THUMB_SIDE);
    return [
        'photo'  => chat_photo_bytes($big, $format, CHAT_PHOTO_QUALITY),
        'thumb'  => chat_photo_bytes($tiny, $format, 40),
        'w'      => imagesx($big),
        'h'      => imagesy($big),
        'format' => $format,
    ];
}

/** Открытая жалоба на фото этого человека в этой ветке: до разбора новых фото нет. */
function chat_photo_has_open_report(PDO $pdo, string $me, int $threadId): bool {
    $st = $pdo->prepare(
        'SELECT 1 FROM chat_photos
          WHERE thread_id = :t AND sender_id = :s AND reported_at > 0 AND reviewed_at = 0 LIMIT 1'
    );
    $st->execute([':t' => $threadId, ':s' => $me]);
    return (bool)$st->fetchColumn();
}

/**
 * Можно ли прямо сейчас отправить фото. null — можно, иначе [код, тело].
 *
 * Первым фото не отправить: незнакомец не должен уметь прислать картинку тому,
 * кто с ним ни разу не говорил. Считаются и сообщения, которые человек удалил у
 * себя (chat_clears): собеседник ведь отвечал.
 */
function chat_photo_gate(PDO $pdo, string $me, int $threadId, int $now): ?array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    if (!chat_ready($pdo) || !chat_photo_ready($pdo)) { return [503, ['ok' => false, 'error' => 'not_ready']]; }
    if (!chat_is_member($pdo, $me, $threadId)) { return [403, ['ok' => false, 'error' => 'not_a_member']]; }

    $st = $pdo->prepare('SELECT 1 FROM chat_messages WHERE thread_id = :t AND sender_id <> :me LIMIT 1');
    $st->execute([':t' => $threadId, ':me' => $me]);
    if (!$st->fetchColumn()) { return [403, ['ok' => false, 'error' => 'need_reply']]; }

    if (chat_photo_has_open_report($pdo, $me, $threadId)) {
        return [403, ['ok' => false, 'error' => 'reported']];
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM chat_photos WHERE sender_id = :s AND created_at > :from');
    $st->execute([':s' => $me, ':from' => $now - 86400]);
    if ((int)$st->fetchColumn() >= CHAT_PHOTO_PER_DAY) {
        return [429, ['ok' => false, 'error' => 'daily_limit']];
    }
    return null;
}

function chat_photo_unlink(string $dir, string $file): void {
    @unlink($dir . '/' . $file);
    @unlink($dir . '/' . chat_photo_thumb_name($file));
}

/**
 * Удалить просроченные файлы. Фото с неразобранной жалобой остаётся: иначе
 * модератору было бы нечего проверять.
 */
function chat_photo_sweep(PDO $pdo, string $dir, int $now, int $limit = CHAT_PHOTO_SWEEP_BATCH): int {
    $st = $pdo->prepare(
        'SELECT id, file FROM chat_photos
          WHERE removed_at = 0 AND created_at < :cut AND (reported_at = 0 OR reviewed_at > 0)
       ORDER BY id LIMIT ' . (int)$limit
    );
    $st->execute([':cut' => $now - CHAT_PHOTO_KEEP_DAYS * 86400]);
    $n = 0;
    $up = $pdo->prepare('UPDATE chat_photos SET removed_at = :at WHERE id = :id');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        chat_photo_unlink($dir, (string)$r['file']);
        $up->execute([':at' => $now, ':id' => (int)$r['id']]);
        $n++;
    }
    return $n;
}

/** Отправка фото. [код, тело] — как у chat_send. */
function chat_photo_send(PDO $pdo, string $me, int $threadId, string $bytes, string $dir, int $now): array {
    $refused = chat_photo_gate($pdo, $me, $threadId, $now);
    if ($refused !== null) { return $refused; }

    chat_photo_sweep($pdo, $dir, $now);
    $used = (int)$pdo->query('SELECT COALESCE(SUM(bytes), 0) FROM chat_photos WHERE removed_at = 0')->fetchColumn();
    if ($used >= CHAT_PHOTO_DISK_MAX) {
        error_log('chat photos: disk cap reached');
        return [503, ['ok' => false, 'error' => 'storage_full']];
    }

    try {
        $made = chat_photo_encode($bytes);
    } catch (RuntimeException $e) {
        $code = $e->getMessage();
        return [$code === 'too_big' || $code === 'bad_image' ? 400 : 503, ['ok' => false, 'error' => $code]];
    }

    if (!chat_photo_dir_ensure($dir)) {
        error_log('chat photos: directory is not writable: ' . $dir);
        return [503, ['ok' => false, 'error' => 'storage_failed']];
    }
    $file = bin2hex(random_bytes(16)) . '.' . $made['format'];
    if (@file_put_contents($dir . '/' . $file, $made['photo']) === false
        || @file_put_contents($dir . '/' . chat_photo_thumb_name($file), $made['thumb']) === false) {
        chat_photo_unlink($dir, $file);
        error_log('chat photos: failed to write ' . $file);
        return [503, ['ok' => false, 'error' => 'storage_failed']];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO chat_photos (thread_id, sender_id, file, w, h, bytes, created_at)
                       VALUES (:t, :s, :f, :w, :h, :b, :at)')
            ->execute([':t' => $threadId, ':s' => $me, ':f' => $file, ':w' => $made['w'], ':h' => $made['h'],
                       ':b' => strlen($made['photo']) + strlen($made['thumb']), ':at' => $now]);
        $photoId = (int)$pdo->lastInsertId();

        $body = '[photo:' . $photoId . ']';
        $pdo->prepare('INSERT INTO chat_messages (thread_id, sender_id, body, created_at)
                       VALUES (:t, :s, :b, :at)')
            ->execute([':t' => $threadId, ':s' => $me, ':b' => $body, ':at' => $now]);
        $id = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE chat_threads SET last_at = :at WHERE id = :t')
            ->execute([':at' => $now, ':t' => $threadId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        chat_photo_unlink($dir, $file);
        error_log('chat photos: ' . $e->getMessage());
        return [500, ['ok' => false, 'error' => 'failed']];
    }

    return [200, [
        'ok'      => true,
        'message' => [
            'id'    => $id,
            'mine'  => true,
            'body'  => $body,
            'at'    => $now,
            'photo' => ['id' => $photoId, 'w' => $made['w'], 'h' => $made['h'], 'gone' => false, 'reported' => false],
        ],
    ]];
}

function chat_photo_find(PDO $pdo, int $id): ?array {
    if ($id <= 0 || !chat_photo_ready($pdo)) { return null; }
    $st = $pdo->prepare('SELECT * FROM chat_photos WHERE id = :id');
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function chat_photo_expired(array $row, int $now): bool {
    return (int)$row['created_at'] < $now - CHAT_PHOTO_KEEP_DAYS * 86400;
}

/**
 * Что видит страница о фото. Метка [photo:N] в чужой ветке или без записи — это
 * просто «удалено»: размеры чужого фото наружу не уходят. reported виден только
 * тому, кто жаловался: для него фото скрыто насовсем.
 */
function chat_photo_attach(PDO $pdo, string $me, int $threadId, array $messages, int $now): array {
    $ids = [];
    foreach ($messages as $m) {
        $id = chat_photo_id($m['body']);
        if ($id > 0) { $ids[$id] = true; }
    }
    if (!$ids) { return $messages; }

    $rows = [];
    if (chat_photo_ready($pdo)) {
        $list = array_keys($ids);
        $in = implode(',', array_fill(0, count($list), '?'));
        $st = $pdo->prepare("SELECT * FROM chat_photos WHERE thread_id = ? AND id IN ($in)");
        $st->execute(array_merge([$threadId], $list));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[(int)$r['id']] = $r; }
    }

    foreach ($messages as &$m) {
        $id = chat_photo_id($m['body']);
        if ($id <= 0) { continue; }
        $r = $rows[$id] ?? null;
        $gone = !$r || (int)$r['removed_at'] > 0 || chat_photo_expired($r, $now);
        $m['photo'] = [
            'id'       => $id,
            'w'        => $gone ? 0 : (int)$r['w'],
            'h'        => $gone ? 0 : (int)$r['h'],
            'gone'     => $gone,
            'reported' => $r && (int)$r['reported_at'] > 0 && (string)$r['sender_id'] !== $me,
        ];
    }
    unset($m);
    return $messages;
}

/**
 * Можно ли этому человеку открыть файл. Участники ветки — пока фото живо, и тот,
 * кто пожаловался, больше не может. Модератор — только фото с жалобой: читать
 * остальные фото чужих переписок ему незачем.
 */
function chat_photo_can_view(PDO $pdo, array $row, string $me, bool $mod, int $now): bool {
    if ($me === '' || (int)$row['removed_at'] > 0) { return false; }
    $reported = (int)$row['reported_at'] > 0;
    if ($mod && $reported) { return true; }
    if (chat_photo_expired($row, $now) || !chat_is_member($pdo, $me, (int)$row['thread_id'])) { return false; }
    return !$reported || (string)$row['sender_id'] === $me;
}

/** Путь к файлу для показа или null. Причину наружу не отдаём: всем один 404. */
function chat_photo_path(PDO $pdo, string $me, bool $mod, int $id, bool $thumb, string $dir, int $now): ?string {
    $row = chat_photo_find($pdo, $id);
    if (!$row || !chat_photo_can_view($pdo, $row, $me, $mod, $now)) { return null; }
    $name = (string)$row['file'];
    $path = $dir . '/' . ($thumb ? chat_photo_thumb_name($name) : $name);
    return is_file($path) ? $path : null;
}

/**
 * Жалоба получателя. first — первая ли она: по ней уходит сигнал модераторам.
 * Отправитель на себя пожаловаться не может, чужой — тоже.
 */
function chat_photo_report(PDO $pdo, string $me, int $id, int $now): array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    $row = chat_photo_find($pdo, $id);
    if (!$row || !chat_is_member($pdo, $me, (int)$row['thread_id'])) {
        return [404, ['ok' => false, 'error' => 'not_found']];
    }
    if ((string)$row['sender_id'] === $me) { return [400, ['ok' => false, 'error' => 'own_photo']]; }
    if ((int)$row['removed_at'] > 0) { return [200, ['ok' => true, 'first' => false]]; }
    if ((int)$row['reported_at'] > 0) { return [200, ['ok' => true, 'first' => false]]; }

    $up = $pdo->prepare('UPDATE chat_photos SET reported_at = :at WHERE id = :id AND reported_at = 0');
    $up->execute([':at' => $now, ':id' => $id]);
    return [200, ['ok' => true, 'first' => $up->rowCount() > 0]];
}

/**
 * Решение модератора. remove — файл стирается сразу, keep — фото остаётся, а
 * отправитель снова может слать фото в эту ветку. Получатель, который пожаловался,
 * своё фото назад не получит в обоих случаях. Возвращает код итога для страницы.
 */
function chat_photo_review(PDO $pdo, string $by, int $id, string $action, string $dir, int $now): string {
    if ($action !== 'remove' && $action !== 'keep') { return 'bad_action'; }
    $row = chat_photo_find($pdo, $id);
    if (!$row || (int)$row['reported_at'] === 0) { return 'not_found'; }
    if ((int)$row['reviewed_at'] > 0 || (int)$row['removed_at'] > 0) { return 'already'; }

    $pdo->prepare('UPDATE chat_photos SET reviewed_at = :at, reviewed_by = :by, removed_at = :rm WHERE id = :id')
        ->execute([':at' => $now, ':by' => $by, ':rm' => $action === 'remove' ? $now : 0, ':id' => $id]);
    if ($action === 'remove') { chat_photo_unlink($dir, (string)$row['file']); }
    return $action === 'remove' ? 'removed' : 'kept';
}

/** Неразобранные жалобы: сначала самые старые. Для /admin/chat-photos. */
function chat_photo_open_reports(PDO $pdo, int $limit = 100): array {
    if (!chat_photo_ready($pdo)) { return []; }
    $st = $pdo->query(
        'SELECT p.id, p.sender_id, p.created_at, p.reported_at, t.a_id, t.b_id
           FROM chat_photos p
           JOIN chat_threads t ON t.id = p.thread_id
          WHERE p.reported_at > 0 AND p.reviewed_at = 0 AND p.removed_at = 0
       ORDER BY p.reported_at ASC, p.id ASC LIMIT ' . (int)$limit
    );
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $people = [];
    foreach ($rows as $r) { $people[] = (string)$r['a_id']; $people[] = (string)$r['b_id']; }
    $users = ban_users($pdo, $people);

    $out = [];
    foreach ($rows as $r) {
        $sender   = (string)$r['sender_id'];
        $reporter = (string)$r['a_id'] === $sender ? (string)$r['b_id'] : (string)$r['a_id'];
        $out[] = [
            'id'       => (int)$r['id'],
            'sender'   => ['id' => $sender, 'nick' => ban_nick($users[$sender] ?? ['roblox_id' => $sender])],
            'reporter' => ['id' => $reporter, 'nick' => ban_nick($users[$reporter] ?? ['roblox_id' => $reporter])],
            'sentAt'   => (int)$r['created_at'],
            'at'       => (int)$r['reported_at'],
        ];
    }
    return $out;
}
