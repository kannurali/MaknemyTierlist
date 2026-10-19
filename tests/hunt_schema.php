<?php
// Таблицы ивента для SQLite: тесты (tests/hunt_test.php) и стенд
// (tools/seed-hunt.php). Зеркалят docs/migrations/2026-10-20-hunt.sql —
// колонки и ключи те же, типы упрощены до SQLite. Меняешь миграцию —
// меняй и здесь.

function hunt_sqlite_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft',
        title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '', rules_ru TEXT NULL, rules_en TEXT NULL,
        starts_at INTEGER NOT NULL DEFAULT 0, sales_end_at INTEGER NOT NULL DEFAULT 0, draw_at INTEGER NOT NULL DEFAULT 0,
        ticket_price INTEGER NOT NULL DEFAULT 50, ticket_limit INTEGER NOT NULL DEFAULT 3, prizes_json TEXT NULL,
        claim_days INTEGER NOT NULL DEFAULT 7, milestones_json TEXT NULL,
        created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, drawn_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_chapters (
        hunt_id INTEGER NOT NULL, n INTEGER NOT NULL, title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '',
        story_ru TEXT NULL, story_en TEXT NULL, opens_at INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (hunt_id, n))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, hunt_id INTEGER NOT NULL, chapter INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0, kind TEXT NOT NULL DEFAULT 'quiz', level TEXT NOT NULL DEFAULT 'easy',
        period TEXT NOT NULL DEFAULT 'once', lang TEXT NOT NULL DEFAULT 'all', grp TEXT NOT NULL DEFAULT '',
        reward INTEGER NOT NULL DEFAULT 0, title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '',
        body_ru TEXT NULL, body_en TEXT NULL, cfg_json TEXT NULL, active INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_secrets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, hunt_id INTEGER NOT NULL, chapter INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0, page TEXT NOT NULL DEFAULT '', spot TEXT NOT NULL DEFAULT '',
        sprite TEXT NOT NULL DEFAULT 'pumpkin', level TEXT NOT NULL DEFAULT 'easy', period TEXT NOT NULL DEFAULT 'once',
        reward INTEGER NOT NULL DEFAULT 0, hint_ru TEXT NOT NULL DEFAULT '', hint_en TEXT NOT NULL DEFAULT '',
        found_ru TEXT NULL, found_en TEXT NULL, active INTEGER NOT NULL DEFAULT 1,
        created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_wallets (
        hunt_id INTEGER NOT NULL, user_id INTEGER NOT NULL, balance INTEGER NOT NULL DEFAULT 0,
        earned INTEGER NOT NULL DEFAULT 0, tickets INTEGER NOT NULL DEFAULT 0, src TEXT NOT NULL DEFAULT '',
        first_at INTEGER NOT NULL DEFAULT 0, last_at INTEGER NOT NULL DEFAULT 0, last_day TEXT NOT NULL DEFAULT '',
        days INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (hunt_id, user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT, hunt_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        delta INTEGER NOT NULL, kind TEXT NOT NULL DEFAULT '', ref TEXT NOT NULL, created_at INTEGER NOT NULL,
        UNIQUE (hunt_id, user_id, ref))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT, hunt_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        seq INTEGER NOT NULL, created_at INTEGER NOT NULL, void INTEGER NOT NULL DEFAULT 0,
        void_reason TEXT NOT NULL DEFAULT '', draw_pos INTEGER NOT NULL DEFAULT 0, UNIQUE (hunt_id, user_id, seq))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_wins (
        id INTEGER PRIMARY KEY AUTOINCREMENT, hunt_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        ticket_id INTEGER NOT NULL, prize_idx INTEGER NOT NULL DEFAULT 0, prize_name TEXT NOT NULL DEFAULT '',
        prize_icon TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'won', deadline INTEGER NOT NULL DEFAULT 0,
        note TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
        UNIQUE (hunt_id, ticket_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_stats (
        hunt_id INTEGER NOT NULL, day TEXT NOT NULL, metric TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (hunt_id, day, metric))");
}
