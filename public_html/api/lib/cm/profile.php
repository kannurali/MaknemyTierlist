<?php
// Анкета и профиль участника (этап 2). Анкета (пол, город, день рождения,
// «о себе») — одна на человека для всех чатов, меняет её только хозяин.
// Профиль собирает cm_profile_lines(); «кто я» (router.php) показывает его же.

const CM_GENDERS = ['м' => 'm', 'муж' => 'm', 'мужской' => 'm', 'ж' => 'f', 'жен' => 'f', 'женский' => 'f'];
// Фраза команды → поле анкеты.
const CM_PROFILE_FIELDS = ['+пол' => 'gender', '+город' => 'city', '+др' => 'birthday', '+о себе' => 'about',
                           '-пол' => 'gender', '-город' => 'city', '-др' => 'birthday', '-о себе' => 'about'];
const CM_PROFILE_MAX = ['city' => 64, 'about' => 200];

// Включён ли раздел «дк» в чате (уровень меньше 6).
function cm_section_on(array $chat, string $section): bool {
    return cm_need_level($chat, $section, cm_section_default($section)) < 6;
}

// Анкета человека; нет строки — все поля пустые.
function cm_profile_get(PDO $pdo, int $userId): array {
    $out = ['gender' => '', 'city' => '', 'birthday' => '', 'about' => ''];
    $st = $pdo->prepare('SELECT gender, city, birthday, about FROM cm_profiles WHERE user_id = :u');
    $st->execute([':u' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        foreach ($out as $k => $v) { $out[$k] = (string)$r[$k]; }
    }
    return $out;
}

function cm_profile_put(PDO $pdo, int $userId, string $field, string $value, int $now): void {
    if (!in_array($field, ['gender', 'city', 'birthday', 'about'], true)) { return; }
    $p = [':u' => $userId, ':v' => $value, ':n' => $now];
    $up = $pdo->prepare("UPDATE cm_profiles SET $field = :v, updated_at = :n WHERE user_id = :u");
    $up->execute($p);
    if ($up->rowCount() > 0) { return; }
    try {
        $pdo->prepare("INSERT INTO cm_profiles (user_id, $field, updated_at) VALUES (:u, :v, :n)")->execute($p);
    } catch (PDOException $e) {
        $up->execute($p);   // строка уже есть, а MySQL не посчитал неизменённую
    }
}

// «25.12» или «25.12.2005» → «25.12» / «25.12.2005»; '' — не дата. Год — от
// 1900 до нынешнего по Москве. Без года 29.02 проверяем по високосному 2000-му.
function cm_birthday(string $raw, int $now): string {
    if (!preg_match('~^([0-9]{1,2})\.([0-9]{1,2})(?:\.([0-9]{4}))?$~', trim($raw), $m)) { return ''; }
    $day = (int)$m[1];
    $month = (int)$m[2];
    $hasYear = isset($m[3]) && $m[3] !== '';
    $year = $hasYear ? (int)$m[3] : 2000;
    if ($hasYear && ($year < 1900 || $year > intdiv(cm_day($now), 10000))) { return ''; }
    if (!checkdate($month, $day, $year)) { return ''; }
    return sprintf('%02d.%02d', $day, $month) . ($hasYear ? '.' . $year : '');
}

// --------------------------------------------------------------------------
//  Профиль
// --------------------------------------------------------------------------

/**
 * Профиль строками: имя, ранг, сообщения, «в чате с», репутация, награды,
 * варны, анкета. Пустые строки анкеты не выводятся, строки выключенного
 * раздела — тоже; награды видны всегда, как и команда «награды». Этап 3
 * вставляет «Пара», «Брак», «Клан», «Дуэли» после наград, перед варнами.
 */
function cm_profile_lines(array $ctx, int $userId, string $name): array {
    $pdo = $ctx['pdo'];
    $chat = $ctx['chat'];
    $chatId = (int)$chat['chat_id'];
    $now = (int)$ctx['now'];
    $rank = cm_rank($ctx, $userId);
    $lines = ['👤 ' . $name, cm_t('profile_rank', ['n' => $rank, 'rank' => CM_RANK_NAMES[$rank]])];
    if (cm_section_on($chat, 'статистика')) {
        $place = cm_stats_place($pdo, $chatId, $userId, 0, $now);
        $lines[] = cm_t('profile_msgs', ['all' => cm_num(cm_stats_msgs($pdo, $chatId, $userId, 0, $now)),
            'today' => cm_num(cm_stats_msgs($pdo, $chatId, $userId, 1, $now)),
            'place' => $place > 0 ? cm_t('profile_place', ['n' => $place]) : '']);
        $first = cm_stats_first_day($pdo, $chatId, $userId);
        if ($first > 0) { $lines[] = cm_t('profile_since', ['date' => cm_day_text($first)]); }
    }
    if (cm_section_on($chat, 'репутация')) {
        $lines[] = cm_t('profile_rep', ['score' => cm_rep_score($pdo, $chatId, $userId)]);
    }
    $awards = cm_awards_active($pdo, $chatId, $userId, $now);
    if ($awards) { $lines[] = cm_t('profile_awards', ['list' => cm_awards_brief($awards)]); }
    $lines[] = cm_t('profile_warns', ['n' => cm_warns_active($ctx, $userId), 'limit' => (int)$chat['settings']['warns']['limit']]);
    if (cm_section_on($chat, 'анкета')) {
        $p = cm_profile_get($pdo, $userId);
        $parts = [];
        if ($p['gender'] !== '') { $parts[] = cm_t('profile_gender', ['g' => cm_t($p['gender'] === 'f' ? 'gender_f' : 'gender_m')]); }
        if ($p['city'] !== '') { $parts[] = cm_t('profile_city', ['city' => $p['city']]); }
        if ($p['birthday'] !== '') { $parts[] = cm_t('profile_bday', ['date' => $p['birthday']]); }
        if ($parts) { $lines[] = implode(' · ', $parts); }
        if ($p['about'] !== '') { $lines[] = cm_t('profile_about', ['about' => $p['about']]); }
    }
    return $lines;
}

function cm_profile_send(array $ctx, array $t): void {
    if (!empty($t['is_bot'])) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    cm_reply($ctx, implode("\n", cm_profile_lines($ctx, (int)$t['id'], (string)$t['name'])), false);
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_profile(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t !== null) { cm_profile_send($ctx, $t); }
}

function cm_cmd_profile_of(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_profile_send($ctx, $t); }
}

// «+пол м», «+город Москва», «+др 25.12», «+о себе текст». Цель не
// принимается: ответ на чужое сообщение меняет свою анкету. Город и «о себе»
// — одной строкой, без ссылок и мата: анкету показывают во всех чатах, а
// сообщение-команду фильтры уже не проверяют.
function cm_cmd_profile_set(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $field = CM_PROFILE_FIELDS[$cmd['phrase']];
    $raw = trim($cmd['args'] . ' ' . $cmd['body']);
    if ($field === 'gender') {
        $value = CM_GENDERS[cm_norm($raw)] ?? '';
    } elseif ($field === 'birthday') {
        $value = cm_birthday($raw, (int)$ctx['now']);
    } else {
        $value = trim((string)preg_replace('~\s+~u', ' ', $raw));
        if (mb_strlen($value) > CM_PROFILE_MAX[$field]) { $value = ''; }
    }
    if ($value === '') {
        cm_reply($ctx, cm_t('profile_' . $field . '_usage'));
        return;
    }
    if (($field === 'city' || $field === 'about')
        && (cm_has_link(['text' => $value], []) || cm_has_mat($value, chat_filter_compile(CM_MAT_WORDS)))) {
        cm_reply($ctx, cm_t('profile_text_bad'));
        return;
    }
    cm_profile_put($ctx['pdo'], (int)$ctx['actor'], $field, $value, (int)$ctx['now']);
    cm_reply($ctx, cm_t('profile_saved'));
}

function cm_cmd_profile_clear(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    cm_profile_put($ctx['pdo'], (int)$ctx['actor'], CM_PROFILE_FIELDS[$cmd['phrase']], '', (int)$ctx['now']);
    cm_reply($ctx, cm_t('profile_cleared'));
}
