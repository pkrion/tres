<?php
function getDb(): PDO {
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }

    $dbPath = $dataDir . '/db.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec('CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reference TEXT NOT NULL,
        description TEXT,
        barcode TEXT,
        price REAL NOT NULL,
        UNIQUE(reference),
        UNIQUE(barcode)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        opened_at TEXT NOT NULL,
        closed_at TEXT,
        opening_cash REAL DEFAULT 0,
        closing_total REAL DEFAULT 0,
        export_path TEXT
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER,
        created_at TEXT NOT NULL,
        vat_rate REAL DEFAULT 0,
        subtotal REAL NOT NULL,
        tax REAL NOT NULL,
        total REAL NOT NULL,
        FOREIGN KEY(session_id) REFERENCES sessions(id) ON DELETE SET NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS ticket_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        reference TEXT,
        description TEXT,
        barcode TEXT,
        price REAL NOT NULL,
        quantity REAL NOT NULL,
        discount REAL DEFAULT 0,
        line_total REAL NOT NULL,
        FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_products_reference ON products(reference)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode)');
    return $db;
}

function getSetting(string $key, $default = null) {
    $db = getDb();
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return $default;
    }
    $decoded = json_decode($value, true);
    return $decoded === null ? $value : $decoded;
}

function setSetting(string $key, $value): void {
    $db = getDb();
    $stmt = $db->prepare('REPLACE INTO settings (key, value) VALUES (:key, :value)');
    $stmt->execute([
        ':key' => $key,
        ':value' => json_encode($value)
    ]);
}
