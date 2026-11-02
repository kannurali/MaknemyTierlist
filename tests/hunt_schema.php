<?php
// Таблицы ивента для SQLite: тесты (tests/hunt_test.php) и стенд
// (tools/seed-hunt.php). Зеркалят docs/migrations/2026-10-30-hunt.sql —
// колонки и ключи те же, типы упрощены до SQLite. Меняешь миграцию —
// меняй и здесь.

function hunt_sqlite_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft',
        title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '', rules_ru TEXT NULL, rules_en TEXT NULL,
        starts_at INTEGER NOT NULL DEFAULT 0, sales_end_at INTEGER NOT NULL DEFAULT 0, draw_at INTEGER NOT NULL DEFAULT 0,
        login_json TEXT NOT NULL DEFAULT '', hours_json TEXT NOT NULL DEFAULT '', prizes_json TEXT NULL,
        claim_days INTEGER NOT NULL DEFAULT 7, titles_json TEXT NULL,
        created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, drawn_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_wallets (
        hunt_id INTEGER NOT NULL, user_id INTEGER NOT NULL, balance INTEGER NOT NULL DEFAULT 0,
        earned INTEGER NOT NULL DEFAULT 0, tickets INTEGER NOT NULL DEFAULT 0,
        first_at INTEGER NOT NULL DEFAULT 0, last_at INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (hunt_id, user_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT, hunt_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        delta INTEGER NOT NULL, kind TEXT NOT NULL DEFAULT '', ref TEXT NOT NULL, created_at INTEGER NOT NULL,
        UNIQUE (hunt_id, user_id, ref))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_time (
        hunt_id INTEGER NOT NULL, user_id INTEGER NOT NULL, day INTEGER NOT NULL,
        secs INTEGER NOT NULL DEFAULT 0, beat_at INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (hunt_id, user_id, day))");
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS hunt_titles (
        user_id INTEGER NOT NULL, hunt_id INTEGER NOT NULL, kind TEXT NOT NULL, created_at INTEGER NOT NULL,
        PRIMARY KEY (user_id, hunt_id, kind))");
}
