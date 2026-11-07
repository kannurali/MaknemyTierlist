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
    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_links (
        user_id INTEGER PRIMARY KEY, chat_id INTEGER NOT NULL, tg_name TEXT NOT NULL DEFAULT '',
        lang TEXT NOT NULL DEFAULT 'ru', linked_at INTEGER NOT NULL)");
}
