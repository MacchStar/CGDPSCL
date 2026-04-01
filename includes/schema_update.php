<?php
declare(strict_types=1);

function schema_col_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column"
    );
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function schema_idx_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :idx"
    );
    $stmt->execute([
        ':table' => $table,
        ':idx' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function schema_needs_update(PDO $pdo): bool
{
    if (!schema_col_exists($pdo, 'users', 'points')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'is_banned')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'bonus_points')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_is_banned')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_points')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_bonus_points')) {
        return true;
    }
    if (schema_col_exists($pdo, 'users', 'display_name')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'demons', 'creator')) {
        return true;
    }

    return false;
}

function run_schema_update(PDO $pdo): array
{
    $logs = [];

    if (!schema_col_exists($pdo, 'users', 'points')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER role');
        $logs[] = '[OK] Added users.points';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN points DECIMAL(10,2) NOT NULL DEFAULT 0.00');

    if (!schema_col_exists($pdo, 'users', 'is_banned')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0 AFTER role');
        $logs[] = '[OK] Added users.is_banned';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0');

    if (!schema_col_exists($pdo, 'users', 'bonus_points')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER points');
        $logs[] = '[OK] Added users.bonus_points';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00');

    if (!schema_idx_exists($pdo, 'users', 'idx_users_is_banned')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_is_banned (is_banned)');
        $logs[] = '[OK] Added idx_users_is_banned';
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_points')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_points (points)');
        $logs[] = '[OK] Added idx_users_points';
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_bonus_points')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_bonus_points (bonus_points)');
        $logs[] = '[OK] Added idx_users_bonus_points';
    }

    if (schema_col_exists($pdo, 'users', 'display_name')) {
        $pdo->exec('ALTER TABLE users DROP COLUMN display_name');
        $logs[] = '[OK] Removed users.display_name';
    }

    if (!schema_col_exists($pdo, 'demons', 'creator')) {
        $pdo->exec('ALTER TABLE demons ADD COLUMN creator VARCHAR(160) NULL AFTER requirement');
        $logs[] = '[OK] Added demons.creator';
    }
    $pdo->exec('ALTER TABLE demons MODIFY COLUMN creator VARCHAR(160) NULL');

    $backfilled = $pdo->exec(
        "UPDATE demons
         SET creator = publisher
         WHERE (creator IS NULL OR TRIM(creator) = '')
           AND publisher IS NOT NULL
           AND TRIM(publisher) <> ''"
    );
    $logs[] = '[OK] Backfilled demons.creator: ' . (int) $backfilled . ' row(s)';

    $logs[] = '[DONE] Schema update completed.';

    return $logs;
}

function schema_config_path(): ?string
{
    $path = $GLOBALS['app_config_path'] ?? null;
    if (!is_string($path) || $path === '' || !is_file($path)) {
        return null;
    }

    return $path;
}

function schema_set_updated_flag(int $value): bool
{
    $value = $value === 1 ? 1 : 0;
    $GLOBALS['app_config']['app']['updated'] = $value;

    $path = schema_config_path();
    if ($path === null || !is_readable($path) || !is_writable($path)) {
        return false;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return false;
    }

    $replacement = "'updated' => {$value}";
    $updatedRaw = $raw;

    if (preg_match("/'updated'\\s*=>\\s*[01]\\s*,/i", $updatedRaw) === 1) {
        $updatedRaw = (string) preg_replace(
            "/'updated'\\s*=>\\s*[01]\\s*,/i",
            $replacement . ',',
            $updatedRaw,
            1
        );
    } else {
        $count = 0;
        $updatedRaw = (string) preg_replace(
            "/('debug'\\s*=>\\s*(?:true|false)\\s*,(?:\\s*\\/\\/[^\\r\\n]*)?\\r?\\n)/i",
            "$1{$replacement},\n",
            $updatedRaw,
            1,
            $count
        );

        if ($count !== 1) {
            $updatedRaw = (string) preg_replace(
                "/('app'\\s*=>\\s*\\[\\s*\\r?\\n)/i",
                "$1{$replacement},\n",
                $raw,
                1,
                $count
            );

            if ($count !== 1) {
                return false;
            }
        }
    }

    if ($updatedRaw === $raw) {
        return true;
    }

    return file_put_contents($path, $updatedRaw, LOCK_EX) !== false;
}

function ensure_schema_updated_on_bootstrap(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (PHP_SAPI === 'cli') {
        return;
    }

    $scriptName = strtolower((string) basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($scriptName === 'update_db_schema.php') {
        return;
    }

    if ((int) config('app.updated', 0) === 1) {
        return;
    }

    try {
        $pdo = db();

        if (schema_needs_update($pdo)) {
            run_schema_update($pdo);
        }

        schema_set_updated_flag(1);
    } catch (Throwable $e) {
        if ((bool) config('app.debug', false)) {
            error_log('Schema auto-update failed: ' . $e->getMessage());
        }
    }
}
