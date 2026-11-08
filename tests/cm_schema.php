<?php
// Таблицы чат-менеджера для SQLite (тесты). Зеркалят
// docs/migrations/2026-11-07-chat-manager.sql: колонки и ключи те же, типы
// упрощены. Меняешь миграцию — меняй и здесь. tg_links — чужая таблица
// (docs/migrations/2026-09-23-telegram.sql): по ней узнаём сотрудников сайта.

function cm_sqlite_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_chats (
        chat_id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1, owner_id INTEGER NOT NULL DEFAULT 0, tg_owner_id INTEGER NOT NULL DEFAULT 0,
        admins TEXT NULL, admins_at INTEGER NOT NULL DEFAULT 0, settings TEXT NULL,
        report_chat_id INTEGER NOT NULL DEFAULT 0, rights_warned_at INTEGER NOT NULL DEFAULT 0,
        sent_window INTEGER NOT NULL DEFAULT 0, sent_count INTEGER NOT NULL DEFAULT 0,
        added_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_members (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, lvl INTEGER NOT NULL DEFAULT 0,
        joined_at INTEGER NOT NULL DEFAULT 0, last_seen INTEGER NOT NULL DEFAULT 0,
        greeted_at INTEGER NOT NULL DEFAULT 0, left_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (chat_id, user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_users (
        user_id INTEGER PRIMARY KEY, username TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
        updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_msgs (
        chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        text_hash TEXT NOT NULL DEFAULT '', at INTEGER NOT NULL, PRIMARY KEY (chat_id, message_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_punishments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        kind TEXT NOT NULL, reason TEXT NOT NULL DEFAULT '', by_id INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL, until_at INTEGER NOT NULL DEFAULT 0,
        lifted_at INTEGER NOT NULL DEFAULT 0, lifted_by INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_rank_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        by_id INTEGER NOT NULL DEFAULT 0, old_lvl INTEGER NOT NULL DEFAULT 0, new_lvl INTEGER NOT NULL DEFAULT 0,
        at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL,
        reporter_id INTEGER NOT NULL, target_id INTEGER NOT NULL, reason TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'open', handled_by INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL, handled_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_spam (
        user_id INTEGER PRIMARY KEY, reason TEXT NOT NULL DEFAULT '', added_by TEXT NOT NULL DEFAULT '',
        added_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_words (
        chat_id INTEGER NOT NULL, word TEXT NOT NULL, PRIMARY KEY (chat_id, word))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_notes (
        chat_id INTEGER NOT NULL, name TEXT NOT NULL, body TEXT NOT NULL, entities TEXT NULL,
        by_id INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL, PRIMARY KEY (chat_id, name))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_timers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, run_at INTEGER NOT NULL,
        body TEXT NOT NULL, by_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL,
        done_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_queue (
        chat_id INTEGER NOT NULL, message_id INTEGER NOT NULL, delete_at INTEGER NOT NULL,
        PRIMARY KEY (chat_id, message_id))");
    // этап 2 — docs/migrations/2026-11-08-chat-manager-activity.sql
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_stats (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, day INTEGER NOT NULL,
        msgs INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (chat_id, user_id, day))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_profiles (
        user_id INTEGER PRIMARY KEY, gender TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '',
        birthday TEXT NOT NULL DEFAULT '', about TEXT NOT NULL DEFAULT '', updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_rep (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, score INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (chat_id, user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_rep_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, from_id INTEGER NOT NULL,
        to_id INTEGER NOT NULL, delta INTEGER NOT NULL, at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_awards (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        level INTEGER NOT NULL, reason TEXT NOT NULL, by_id INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL, until_at INTEGER NOT NULL DEFAULT 0, removed_at INTEGER NOT NULL DEFAULT 0)");
    // этап 3 — docs/migrations/2026-11-08-chat-manager-games.sql
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_offers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, kind TEXT NOT NULL,
        from_id INTEGER NOT NULL, to_id INTEGER NOT NULL, ref_id INTEGER NOT NULL DEFAULT 0,
        msg_id INTEGER NOT NULL DEFAULT 0, expires_at INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'open')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_games (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, kind TEXT NOT NULL,
        a_id INTEGER NOT NULL, b_id INTEGER NOT NULL, turn_id INTEGER NOT NULL, state TEXT NOT NULL DEFAULT '{}',
        msg_id INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'on', winner_id INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_game_stats (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, kind TEXT NOT NULL, wins INTEGER NOT NULL DEFAULT 0,
        losses INTEGER NOT NULL DEFAULT 0, draws INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (chat_id, user_id, kind))");
    // шахматы — docs/migrations/2026-11-08-cm-chess.sql
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_chess (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, white_id INTEGER NOT NULL,
        black_id INTEGER NOT NULL, base_ms INTEGER NOT NULL, white_ms INTEGER NOT NULL, black_ms INTEGER NOT NULL,
        fen TEXT NOT NULL, moves TEXT NOT NULL, san TEXT NOT NULL, turn_at INTEGER NOT NULL,
        due_at INTEGER NOT NULL DEFAULT 0, draw_by TEXT NOT NULL DEFAULT '', msg_id INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'on', result TEXT NOT NULL DEFAULT '', reason TEXT NOT NULL DEFAULT '',
        rev INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_couples (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, a_id INTEGER NOT NULL, b_id INTEGER NOT NULL,
        since INTEGER NOT NULL, xp INTEGER NOT NULL DEFAULT 0, xp_day INTEGER NOT NULL DEFAULT 0,
        xp_today INTEGER NOT NULL DEFAULT 0, ended_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_marriages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, a_id INTEGER NOT NULL, b_id INTEGER NOT NULL,
        since INTEGER NOT NULL, ended_at INTEGER NOT NULL DEFAULT 0, ended_by INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_clans (
        id INTEGER PRIMARY KEY AUTOINCREMENT, chat_id INTEGER NOT NULL, name TEXT NOT NULL, name_key TEXT NOT NULL,
        owner_id INTEGER NOT NULL, about TEXT NOT NULL DEFAULT '', is_open INTEGER NOT NULL DEFAULT 1,
        called_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, UNIQUE (chat_id, name_key))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cm_clan_members (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL, clan_id INTEGER NOT NULL, joined_at INTEGER NOT NULL,
        PRIMARY KEY (chat_id, user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_links (
        user_id INTEGER PRIMARY KEY, chat_id INTEGER NOT NULL, tg_name TEXT NOT NULL DEFAULT '',
        lang TEXT NOT NULL DEFAULT 'ru', linked_at INTEGER NOT NULL)");
}
