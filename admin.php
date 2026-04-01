<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$adminPassword = (string) config('admin.password', 'changeme');

function record_position_event(PDO $pdo, int $demonId, ?int $oldPosition, int $newPosition, ?int $changedByUserId, ?string $note = null): void
{
    $stmt = $pdo->prepare('INSERT INTO demon_position_history
        (demon_id, old_position, new_position, changed_by_user_id, note)
        VALUES
        (:demon_id, :old_position, :new_position, :changed_by_user_id, :note)');

    $stmt->execute([
        ':demon_id' => $demonId,
        ':old_position' => $oldPosition,
        ':new_position' => $newPosition,
        ':changed_by_user_id' => $changedByUserId,
        ':note' => $note !== null && trim($note) !== '' ? trim($note) : null,
    ]);
}

function admin_column_exists(PDO $pdo, string $table, string $column): bool
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

function ensure_bonus_points_column(PDO $pdo): void
{
    if (!admin_column_exists($pdo, 'users', 'bonus_points')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER points');
    }

    $pdo->exec('ALTER TABLE users MODIFY COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00');
}

function ensure_user_banned_column(PDO $pdo): void
{
    if (!admin_column_exists($pdo, 'users', 'is_banned')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0 AFTER role');
    }

    $pdo->exec('ALTER TABLE users MODIFY COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0');
}

function admin_webhook_actor_label(): string
{
    $name = trim((string) (current_user_display_name() ?? 'System'));
    if ($name === '') {
        $name = 'System';
    }

    $id = current_user_id();
    return $id !== null ? $name . ' (#' . $id . ')' : $name;
}

function admin_webhook_text(mixed $value): string
{
    if ($value === null) {
        return '-';
    }

    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    $text = trim((string) $value);
    return $text !== '' ? $text : '-';
}

function admin_webhook_list_label(int $legacy): string
{
    return $legacy === 1 ? 'Legacy' : 'Main';
}

function admin_webhook_add_change(array &$changes, string $label, string $before, string $after, bool $inline = false): void
{
    if ($before === $after) {
        return;
    }

    $changes[] = [
        'name' => $label,
        'value' => $before . ' -> ' . $after,
        'inline' => $inline,
    ];
}

function admin_notify_level_added(array $level): void
{
    $embed = [
        'title' => 'Level Added',
        'color' => 5814783,
        'fields' => [
            ['name' => 'Level', 'value' => '#' . (int) $level['position'] . ' - ' . admin_webhook_text($level['name']), 'inline' => false],
            ['name' => 'List', 'value' => admin_webhook_list_label((int) $level['legacy']), 'inline' => true],
            ['name' => 'Difficulty', 'value' => admin_webhook_text($level['difficulty']), 'inline' => true],
            ['name' => 'Requirement', 'value' => (int) $level['requirement'] . '%', 'inline' => true],
            ['name' => 'Publisher', 'value' => admin_webhook_text($level['publisher']), 'inline' => true],
            ['name' => 'Verifier', 'value' => admin_webhook_text($level['verifier']), 'inline' => true],
            ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
        ],
        'timestamp' => gmdate('c'),
    ];

    if ((int) ($level['id'] ?? 0) > 0) {
        $embed['url'] = absolute_url('demon.php?id=' . (int) $level['id']);
    }

    send_discord_webhook('', [$embed]);
}

function admin_notify_level_updated(array $before, array $after, string $moveNote = ''): void
{
    $changes = [];

    admin_webhook_add_change($changes, 'Name', admin_webhook_text($before['name'] ?? null), admin_webhook_text($after['name'] ?? null));
    admin_webhook_add_change($changes, 'Position', '#' . (int) ($before['position'] ?? 0), '#' . (int) ($after['position'] ?? 0), true);
    admin_webhook_add_change($changes, 'Difficulty', admin_webhook_text($before['difficulty'] ?? null), admin_webhook_text($after['difficulty'] ?? null), true);
    admin_webhook_add_change($changes, 'Requirement', (int) ($before['requirement'] ?? 0) . '%', (int) ($after['requirement'] ?? 0) . '%', true);
    admin_webhook_add_change($changes, 'Publisher', admin_webhook_text($before['publisher'] ?? null), admin_webhook_text($after['publisher'] ?? null), true);
    admin_webhook_add_change($changes, 'Verifier', admin_webhook_text($before['verifier'] ?? null), admin_webhook_text($after['verifier'] ?? null), true);
    admin_webhook_add_change($changes, 'Video URL', admin_webhook_text($before['video_url'] ?? null), admin_webhook_text($after['video_url'] ?? null));
    admin_webhook_add_change($changes, 'Thumbnail URL', admin_webhook_text($before['thumbnail_url'] ?? null), admin_webhook_text($after['thumbnail_url'] ?? null));
    admin_webhook_add_change($changes, 'Level ID', admin_webhook_text($before['level_id'] ?? null), admin_webhook_text($after['level_id'] ?? null), true);
    admin_webhook_add_change($changes, 'Level Length', admin_webhook_text($before['level_length'] ?? null), admin_webhook_text($after['level_length'] ?? null), true);
    admin_webhook_add_change($changes, 'Song', admin_webhook_text($before['song'] ?? null), admin_webhook_text($after['song'] ?? null));

    $beforeObjects = ($before['object_count'] ?? null) !== null ? (string) (int) $before['object_count'] : '-';
    $afterObjects = ($after['object_count'] ?? null) !== null ? (string) (int) $after['object_count'] : '-';
    admin_webhook_add_change($changes, 'Object Count', $beforeObjects, $afterObjects, true);

    admin_webhook_add_change(
        $changes,
        'List Type',
        admin_webhook_list_label((int) ($before['legacy'] ?? 0)),
        admin_webhook_list_label((int) ($after['legacy'] ?? 0)),
        true
    );

    if ($changes === []) {
        return;
    }

    $fields = [
        ['name' => 'Level', 'value' => '#' . (int) ($after['position'] ?? 0) . ' - ' . admin_webhook_text($after['name'] ?? null), 'inline' => false],
        ['name' => 'Demon ID', 'value' => '#' . (int) ($after['id'] ?? 0), 'inline' => true],
        ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
        ['name' => 'Changed Fields', 'value' => (string) count($changes), 'inline' => true],
    ];

    if ($moveNote !== '') {
        $fields[] = ['name' => 'Move Note', 'value' => $moveNote, 'inline' => false];
    }

    $embed = [
        'title' => 'Level Updated',
        'color' => 15105570,
        'fields' => array_merge($fields, $changes),
        'timestamp' => gmdate('c'),
    ];

    if ((int) ($after['id'] ?? 0) > 0) {
        $embed['url'] = absolute_url('demon.php?id=' . (int) $after['id']);
    }

    send_discord_webhook('', [$embed]);
}

function admin_notify_level_moved(string $name, int $demonId, int $oldPosition, int $newPosition, string $note = ''): void
{
    if ($newPosition === $oldPosition) {
        return;
    }

    $embed = [
        'title' => 'Level Position Updated',
        'color' => 15105570,
        'fields' => [
            ['name' => 'Level', 'value' => admin_webhook_text($name), 'inline' => false],
            ['name' => 'Change', 'value' => '#' . $oldPosition . ' -> #' . $newPosition, 'inline' => true],
            ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
            ['name' => 'Reason', 'value' => $note !== '' ? $note : 'Position moved in admin panel', 'inline' => false],
        ],
        'timestamp' => gmdate('c'),
    ];

    if ($demonId > 0) {
        $embed['url'] = absolute_url('demon.php?id=' . $demonId);
    }

    send_discord_webhook('', [$embed]);
}

function admin_notify_user_updated(array $before, array $after, float $bonusDelta): void
{
    $changes = [];

    admin_webhook_add_change($changes, 'Role', strtoupper((string) $before['role']), strtoupper((string) $after['role']), true);
    admin_webhook_add_change(
        $changes,
        'Banned',
        ((int) $before['is_banned'] === 1 ? 'Yes' : 'No'),
        ((int) $after['is_banned'] === 1 ? 'Yes' : 'No'),
        true
    );
    admin_webhook_add_change(
        $changes,
        'Bonus Points',
        number_format((float) $before['bonus_points'], 2, '.', ''),
        number_format((float) $after['bonus_points'], 2, '.', ''),
        true
    );
    admin_webhook_add_change(
        $changes,
        'Total Points',
        number_format((float) $before['points'], 2, '.', ''),
        number_format((float) $after['points'], 2, '.', ''),
        true
    );

    if ($changes === []) {
        return;
    }

    $fields = [
        ['name' => 'User', 'value' => admin_webhook_text($after['username']) . ' (#' . (int) $after['id'] . ')', 'inline' => true],
        ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
        ['name' => 'Bonus Delta', 'value' => ($bonusDelta >= 0 ? '+' : '') . number_format($bonusDelta, 2, '.', ''), 'inline' => true],
    ];

    $embed = [
        'title' => 'User Management Updated',
        'color' => (int) $after['is_banned'] === 1 ? 15158332 : 3447003,
        'fields' => array_merge($fields, $changes),
        'timestamp' => gmdate('c'),
    ];

    send_discord_webhook('', [$embed]);
}

function admin_notify_submission_reviewed(array $submission, string $decision, string $reviewNote, string $playerName, array $recordFields = []): void
{
    $decision = strtolower($decision);
    $decisionLabel = strtoupper($decision);

    $fields = [
        ['name' => 'Submission', 'value' => '#' . (int) ($submission['id'] ?? 0), 'inline' => true],
        ['name' => 'Decision', 'value' => $decisionLabel, 'inline' => true],
        ['name' => 'Type', 'value' => admin_webhook_text($submission['type'] ?? null), 'inline' => true],
        ['name' => 'Demon', 'value' => admin_webhook_text($submission['demon_name'] ?? null), 'inline' => true],
        ['name' => 'Player', 'value' => admin_webhook_text($playerName), 'inline' => true],
        ['name' => 'Submitted Progress', 'value' => (int) ($submission['progress'] ?? 0) . '%', 'inline' => true],
        ['name' => 'Reviewed By', 'value' => admin_webhook_actor_label(), 'inline' => true],
    ];

    if ($reviewNote !== '') {
        $fields[] = ['name' => 'Review Note', 'value' => $reviewNote, 'inline' => false];
    }

    foreach ($recordFields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $name = trim((string) ($field['name'] ?? ''));
        $value = trim((string) ($field['value'] ?? ''));
        if ($name === '' || $value === '') {
            continue;
        }

        $fields[] = [
            'name' => $name,
            'value' => $value,
            'inline' => !empty($field['inline']),
        ];
    }

    $embed = [
        'title' => 'Submission Review ' . $decisionLabel,
        'color' => $decision === 'approved' ? 5763719 : 15548997,
        'fields' => $fields,
        'timestamp' => gmdate('c'),
    ];

    send_discord_webhook('', [$embed]);
}

if (method_is_post()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $password = (string) ($_POST['password'] ?? '');
        if ($password === $adminPassword) {
            $_SESSION['admin_logged_in'] = true;
            flash('success', 'Admin login successful.');
        } else {
            flash('error', 'Wrong admin password.');
        }

        redirect('admin.php');
    }

    if ($action === 'logout' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        unset($_SESSION['admin_logged_in']);
        flash('success', 'Logged out.');
        redirect('admin.php');
    }

    if ($action === 'add_level' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $difficulty = trim((string) ($_POST['difficulty'] ?? 'Extreme Demon'));
        $positionInput = trim((string) ($_POST['position'] ?? ''));
        $requirement = (int) ($_POST['requirement'] ?? 100);
        $publisher = trim((string) ($_POST['publisher'] ?? ''));
        $verifier = trim((string) ($_POST['verifier'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $thumbnail = trim((string) ($_POST['thumbnail_url'] ?? ''));
        $levelId = trim((string) ($_POST['level_id'] ?? ''));
        $levelLength = trim((string) ($_POST['level_length'] ?? ''));
        $song = trim((string) ($_POST['song'] ?? ''));
        $objectCountInput = trim((string) ($_POST['object_count'] ?? ''));
        $legacy = isset($_POST['legacy']) ? 1 : 0;

        $errors = [];
        if ($name === '') {
            $errors[] = 'Level name is required.';
        }
        if ($publisher === '') {
            $errors[] = 'Publisher is required.';
        }
        if ($videoUrl === '' || filter_var($videoUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Valid verification video URL is required.';
        }
        if ($thumbnail !== '' && filter_var($thumbnail, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Thumbnail URL must be valid when provided.';
        }
        if ($requirement < 1 || $requirement > 100) {
            $errors[] = 'Requirement must be between 1 and 100.';
        }

        $objectCount = null;
        if ($objectCountInput !== '') {
            if (!ctype_digit($objectCountInput)) {
                $errors[] = 'Object count must be a non-negative integer.';
            } else {
                $objectCount = (int) $objectCountInput;
            }
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('admin.php');
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $dupStmt = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1');
            $dupStmt->execute([':name' => $name]);
            if ($dupStmt->fetch() !== false) {
                throw new RuntimeException('A level with this name already exists.');
            }

            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            if ($positionInput !== '') {
                $position = (int) $positionInput;
                if ($position < 1) {
                    throw new RuntimeException('Position must be >= 1.');
                }
                if ($position > $maxPosition + 1) {
                    throw new RuntimeException('Position cannot be greater than ' . ($maxPosition + 1) . '.');
                }

                $positionOffset = $maxPosition + 1;

                $lift = $pdo->prepare('UPDATE demons
                    SET position = position + :offset
                    WHERE position >= :position');
                $lift->execute([
                    ':offset' => $positionOffset,
                    ':position' => $position,
                ]);

                $drop = $pdo->prepare('UPDATE demons
                    SET position = position - :shift
                    WHERE position >= :lifted_from');
                $drop->execute([
                    ':shift' => $positionOffset - 1,
                    ':lifted_from' => $position + $positionOffset,
                ]);
            } else {
                $position = $maxPosition + 1;
            }

            $insert = $pdo->prepare('INSERT INTO demons
                (position, name, difficulty, requirement, publisher, verifier, video_url, thumbnail_url, level_id, level_length, song, object_count, legacy)
                VALUES
                (:position, :name, :difficulty, :requirement, :publisher, :verifier, :video_url, :thumbnail_url, :level_id, :level_length, :song, :object_count, :legacy)');

            $insert->execute([
                ':position' => $position,
                ':name' => $name,
                ':difficulty' => $difficulty !== '' ? $difficulty : 'Extreme Demon',
                ':requirement' => $requirement,
                ':publisher' => $publisher,
                ':verifier' => $verifier !== '' ? $verifier : null,
                ':video_url' => $videoUrl,
                ':thumbnail_url' => $thumbnail !== '' ? $thumbnail : null,
                ':level_id' => $levelId !== '' ? $levelId : null,
                ':level_length' => $levelLength !== '' ? $levelLength : null,
                ':song' => $song !== '' ? $song : null,
                ':object_count' => $objectCount,
                ':legacy' => $legacy,
            ]);

            $newDemonId = (int) $pdo->lastInsertId();
            record_position_event($pdo, $newDemonId, null, $position, current_user_id(), 'Level added');

            $createdLevelData = [
                'id' => $newDemonId,
                'position' => $position,
                'name' => $name,
                'difficulty' => $difficulty !== '' ? $difficulty : 'Extreme Demon',
                'requirement' => $requirement,
                'publisher' => $publisher,
                'verifier' => $verifier,
                'video_url' => $videoUrl,
                'thumbnail_url' => $thumbnail,
                'level_id' => $levelId,
                'level_length' => $levelLength,
                'song' => $song,
                'object_count' => $objectCount,
                'legacy' => $legacy,
            ];

            $pdo->commit();
            admin_notify_level_added($createdLevelData);

            flash('success', 'Level added at position #' . $position . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect('admin.php');
    }
    if ($action === 'edit_level' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $targetNameInput = trim((string) ($_POST['demon_name'] ?? ''));
        $newNameInput = trim((string) ($_POST['name'] ?? ''));
        $difficultyInput = trim((string) ($_POST['difficulty'] ?? ''));
        $requirementInput = trim((string) ($_POST['requirement'] ?? ''));
        $publisherInput = trim((string) ($_POST['publisher'] ?? ''));
        $verifierInput = trim((string) ($_POST['verifier'] ?? ''));
        $videoUrlInput = trim((string) ($_POST['video_url'] ?? ''));
        $thumbnailInput = trim((string) ($_POST['thumbnail_url'] ?? ''));
        $levelIdInput = trim((string) ($_POST['level_id'] ?? ''));
        $levelLengthInput = trim((string) ($_POST['level_length'] ?? ''));
        $songInput = trim((string) ($_POST['song'] ?? ''));
        $objectCountInput = trim((string) ($_POST['object_count'] ?? ''));
        $legacyStatus = (string) ($_POST['legacy_status'] ?? 'keep');
        $newPositionInput = trim((string) ($_POST['new_position'] ?? ''));
        $moveNote = trim((string) ($_POST['move_note'] ?? ''));

        if ($targetNameInput === '') {
            flash('error', 'Level name is required for editing.');
            redirect('admin.php');
        }

        $errors = [];
        if ($requirementInput !== '' && !ctype_digit($requirementInput)) {
            $errors[] = 'Requirement must be a whole number.';
        }
        if ($objectCountInput !== '' && !ctype_digit($objectCountInput)) {
            $errors[] = 'Object count must be a non-negative integer.';
        }
        if ($newPositionInput !== '' && !ctype_digit($newPositionInput)) {
            $errors[] = 'New position must be a positive integer.';
        }
        if ($videoUrlInput !== '' && filter_var($videoUrlInput, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Verification video URL must be valid.';
        }
        if ($thumbnailInput !== '' && filter_var($thumbnailInput, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Thumbnail URL must be valid.';
        }
        if (!in_array($legacyStatus, ['keep', 'normal', 'legacy'], true)) {
            $errors[] = 'Invalid legacy status option.';
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect('admin.php');
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $targetStmt = $pdo->prepare('SELECT * FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1 FOR UPDATE');
            $targetStmt->execute([':name' => $targetNameInput]);
            $target = $targetStmt->fetch();

            if ($target === false) {
                $partial = $pdo->prepare('SELECT id, name FROM demons WHERE LOWER(name) LIKE :query ORDER BY position ASC LIMIT 2');
                $partial->execute([':query' => '%' . strtolower($targetNameInput) . '%']);
                $matches = $partial->fetchAll();

                if (count($matches) === 0) {
                    throw new RuntimeException('Level not found. Please type a valid level name.');
                }
                if (count($matches) > 1) {
                    throw new RuntimeException('Multiple levels match this name. Please type the full level name.');
                }

                $targetStmt = $pdo->prepare('SELECT * FROM demons WHERE id = :id LIMIT 1 FOR UPDATE');
                $targetStmt->execute([':id' => (int) $matches[0]['id']]);
                $target = $targetStmt->fetch();
            }

            if ($target === false) {
                throw new RuntimeException('Level not found.');
            }

            $demonId = (int) $target['id'];
            $oldPosition = (int) $target['position'];
            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            $beforeLevelData = [
                'id' => $demonId,
                'position' => (int) $target['position'],
                'name' => (string) $target['name'],
                'difficulty' => (string) $target['difficulty'],
                'requirement' => (int) $target['requirement'],
                'publisher' => (string) $target['publisher'],
                'verifier' => (string) ($target['verifier'] ?? ''),
                'video_url' => (string) $target['video_url'],
                'thumbnail_url' => (string) ($target['thumbnail_url'] ?? ''),
                'level_id' => (string) ($target['level_id'] ?? ''),
                'level_length' => (string) ($target['level_length'] ?? ''),
                'song' => (string) ($target['song'] ?? ''),
                'object_count' => $target['object_count'] !== null ? (int) $target['object_count'] : null,
                'legacy' => (int) $target['legacy'],
            ];

            $finalName = $newNameInput !== '' ? $newNameInput : (string) $target['name'];
            $finalDifficulty = $difficultyInput !== '' ? $difficultyInput : (string) $target['difficulty'];
            $finalRequirement = $requirementInput !== '' ? (int) $requirementInput : (int) $target['requirement'];
            $finalPublisher = $publisherInput !== '' ? $publisherInput : (string) $target['publisher'];
            $finalVerifier = $verifierInput !== '' ? $verifierInput : (string) ($target['verifier'] ?? '');
            $finalVideoUrl = $videoUrlInput !== '' ? $videoUrlInput : (string) $target['video_url'];
            $finalThumbnail = $thumbnailInput !== '' ? $thumbnailInput : (string) ($target['thumbnail_url'] ?? '');
            $finalLevelId = $levelIdInput !== '' ? $levelIdInput : (string) ($target['level_id'] ?? '');
            $finalLevelLength = $levelLengthInput !== '' ? $levelLengthInput : (string) ($target['level_length'] ?? '');
            $finalSong = $songInput !== '' ? $songInput : (string) ($target['song'] ?? '');
            $finalObjectCount = $objectCountInput !== ''
                ? (int) $objectCountInput
                : ($target['object_count'] !== null ? (int) $target['object_count'] : null);

            $finalLegacy = (int) $target['legacy'];
            if ($legacyStatus === 'normal') {
                $finalLegacy = 0;
            }
            if ($legacyStatus === 'legacy') {
                $finalLegacy = 1;
            }

            if ($finalName === '') {
                throw new RuntimeException('Level name cannot be empty.');
            }
            if ($finalPublisher === '') {
                throw new RuntimeException('Publisher cannot be empty.');
            }
            if ($finalRequirement < 1 || $finalRequirement > 100) {
                throw new RuntimeException('Requirement must be between 1 and 100.');
            }
            if ($finalVideoUrl === '' || filter_var($finalVideoUrl, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('Verification video URL must be valid.');
            }
            if ($finalThumbnail !== '' && filter_var($finalThumbnail, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('Thumbnail URL must be valid.');
            }

            $dupStmt = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) AND id <> :id LIMIT 1');
            $dupStmt->execute([
                ':name' => $finalName,
                ':id' => $demonId,
            ]);
            if ($dupStmt->fetch() !== false) {
                throw new RuntimeException('Another level already uses this name.');
            }

            $newPosition = $oldPosition;
            if ($newPositionInput !== '') {
                $newPosition = (int) $newPositionInput;
                if ($newPosition < 1 || $newPosition > $maxPosition) {
                    throw new RuntimeException('New position must be between 1 and ' . $maxPosition . '.');
                }
            }

            if ($newPosition !== $oldPosition) {
                $positionOffset = $maxPosition + 1;

                $parkTarget = $pdo->prepare('UPDATE demons SET position = :temporary_position WHERE id = :id');
                $parkTarget->execute([
                    ':temporary_position' => $positionOffset,
                    ':id' => $demonId,
                ]);

                if ($newPosition < $oldPosition) {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position >= :new_position
                          AND position < :old_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':new_position' => $newPosition,
                        ':old_position' => $oldPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position >= :lifted_from
                          AND position < :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset - 1,
                        ':lifted_from' => $newPosition + $positionOffset,
                        ':lifted_to' => $oldPosition + $positionOffset,
                    ]);
                } else {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position > :old_position
                          AND position <= :new_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':old_position' => $oldPosition,
                        ':new_position' => $newPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position > :lifted_from
                          AND position <= :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset + 1,
                        ':lifted_from' => $oldPosition + $positionOffset,
                        ':lifted_to' => $newPosition + $positionOffset,
                    ]);
                }

                record_position_event(
                    $pdo,
                    $demonId,
                    $oldPosition,
                    $newPosition,
                    current_user_id(),
                    $moveNote !== '' ? $moveNote : 'Position updated in level edit'
                );
            }

            $update = $pdo->prepare('UPDATE demons
                SET position = :position,
                    name = :name,
                    difficulty = :difficulty,
                    requirement = :requirement,
                    publisher = :publisher,
                    verifier = :verifier,
                    video_url = :video_url,
                    thumbnail_url = :thumbnail_url,
                    level_id = :level_id,
                    level_length = :level_length,
                    song = :song,
                    object_count = :object_count,
                    legacy = :legacy
                WHERE id = :id');

            $update->execute([
                ':position' => $newPosition,
                ':name' => $finalName,
                ':difficulty' => $finalDifficulty,
                ':requirement' => $finalRequirement,
                ':publisher' => $finalPublisher,
                ':verifier' => $finalVerifier !== '' ? $finalVerifier : null,
                ':video_url' => $finalVideoUrl,
                ':thumbnail_url' => $finalThumbnail !== '' ? $finalThumbnail : null,
                ':level_id' => $finalLevelId !== '' ? $finalLevelId : null,
                ':level_length' => $finalLevelLength !== '' ? $finalLevelLength : null,
                ':song' => $finalSong !== '' ? $finalSong : null,
                ':object_count' => $finalObjectCount,
                ':legacy' => $finalLegacy,
                ':id' => $demonId,
            ]);

            $afterLevelData = [
                'id' => $demonId,
                'position' => $newPosition,
                'name' => $finalName,
                'difficulty' => $finalDifficulty,
                'requirement' => $finalRequirement,
                'publisher' => $finalPublisher,
                'verifier' => $finalVerifier,
                'video_url' => $finalVideoUrl,
                'thumbnail_url' => $finalThumbnail,
                'level_id' => $finalLevelId,
                'level_length' => $finalLevelLength,
                'song' => $finalSong,
                'object_count' => $finalObjectCount,
                'legacy' => $finalLegacy,
            ];

            $pdo->commit();
            admin_notify_level_updated($beforeLevelData, $afterLevelData, $moveNote);

            flash('success', 'Updated level #' . $newPosition . ' - ' . $finalName . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect('admin.php');
    }
    if ($action === 'move_level' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $demonId = (int) ($_POST['demon_id'] ?? 0);
        $demonNameInput = trim((string) ($_POST['demon_name'] ?? ''));
        $newPosition = (int) ($_POST['new_position'] ?? 0);
        $note = trim((string) ($_POST['move_note'] ?? ''));

        if ($newPosition < 1 || ($demonId < 1 && $demonNameInput === '')) {
            flash('error', 'Invalid level move request.');
            redirect('admin.php');
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            if ($demonId < 1) {
                $exactByName = $pdo->prepare('SELECT id FROM demons WHERE legacy = 0 AND LOWER(name) = LOWER(:name) LIMIT 1');
                $exactByName->execute([':name' => $demonNameInput]);
                $matchId = $exactByName->fetchColumn();

                if ($matchId === false) {
                    $partialByName = $pdo->prepare('SELECT id, name FROM demons WHERE legacy = 0 AND LOWER(name) LIKE :query ORDER BY position ASC LIMIT 2');
                    $partialByName->execute([':query' => '%' . strtolower($demonNameInput) . '%']);
                    $matches = $partialByName->fetchAll();

                    if (count($matches) === 0) {
                        throw new RuntimeException('Level not found. Please type a valid level name.');
                    }
                    if (count($matches) > 1) {
                        throw new RuntimeException('Multiple levels match this name. Please type the full level name.');
                    }

                    $matchId = (int) $matches[0]['id'];
                }

                $demonId = (int) $matchId;
            }

            $targetStmt = $pdo->prepare('SELECT id, name, position FROM demons WHERE id = :id LIMIT 1 FOR UPDATE');
            $targetStmt->execute([':id' => $demonId]);
            $target = $targetStmt->fetch();

            if ($target === false) {
                throw new RuntimeException('Level not found.');
            }

            $oldPosition = (int) $target['position'];
            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            if ($newPosition > $maxPosition) {
                throw new RuntimeException('New position cannot exceed ' . $maxPosition . '.');
            }

            if ($newPosition !== $oldPosition) {
                $positionOffset = $maxPosition + 1;

                $parkTarget = $pdo->prepare('UPDATE demons SET position = :temporary_position WHERE id = :id');
                $parkTarget->execute([
                    ':temporary_position' => $positionOffset,
                    ':id' => $demonId,
                ]);

                if ($newPosition < $oldPosition) {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position >= :new_position
                          AND position < :old_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':new_position' => $newPosition,
                        ':old_position' => $oldPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position >= :lifted_from
                          AND position < :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset - 1,
                        ':lifted_from' => $newPosition + $positionOffset,
                        ':lifted_to' => $oldPosition + $positionOffset,
                    ]);
                } else {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position > :old_position
                          AND position <= :new_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':old_position' => $oldPosition,
                        ':new_position' => $newPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position > :lifted_from
                          AND position <= :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset + 1,
                        ':lifted_from' => $oldPosition + $positionOffset,
                        ':lifted_to' => $newPosition + $positionOffset,
                    ]);
                }

                $updateTarget = $pdo->prepare('UPDATE demons SET position = :position WHERE id = :id');
                $updateTarget->execute([
                    ':position' => $newPosition,
                    ':id' => $demonId,
                ]);

                record_position_event(
                    $pdo,
                    $demonId,
                    $oldPosition,
                    $newPosition,
                    current_user_id(),
                    $note !== '' ? $note : 'Position moved in admin panel'
                );
            }

            $pdo->commit();

            if ($newPosition !== $oldPosition) {
                admin_notify_level_moved((string) $target['name'], $demonId, $oldPosition, $newPosition, $note);
            }

            if ($newPosition === $oldPosition) {
                flash('success', 'No change made. Level is already at #' . $oldPosition . '.');
            } else {
                flash('success', 'Moved ' . (string) $target['name'] . ' from #' . $oldPosition . ' to #' . $newPosition . '.');
            }
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect('admin.php');
    }

    if ($action === 'update_user' && is_admin()) {
        $usersQueryRedirect = trim((string) ($_POST['users_q'] ?? ''));
        $redirectTarget = $usersQueryRedirect !== ''
            ? ('admin.php?users_q=' . rawurlencode($usersQueryRedirect) . '#admin-user-management')
            : 'admin.php#admin-user-management';

        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect($redirectTarget);
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? '');
        $isBannedInput = (string) ($_POST['is_banned'] ?? '0');
        $bonusInput = trim((string) ($_POST['bonus_delta'] ?? '0'));

        if ($userId < 1 || !in_array($role, ['player', 'admin'], true)) {
            flash('error', 'Invalid user update request.');
            redirect($redirectTarget);
        }
        if (!in_array($isBannedInput, ['0', '1'], true)) {
            flash('error', 'Invalid banned status value.');
            redirect($redirectTarget);
        }
        if (!is_numeric($bonusInput)) {
            flash('error', 'Bonus value must be a valid number.');
            redirect($redirectTarget);
        }

        $isBanned = $isBannedInput === '1' ? 1 : 0;
        $bonusDelta = round((float) $bonusInput, 2);
        if ($bonusDelta < -999999 || $bonusDelta > 999999) {
            flash('error', 'Bonus value is out of range.');
            redirect($redirectTarget);
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();
            ensure_bonus_points_column($pdo);
            ensure_user_banned_column($pdo);

            $userStmt = $pdo->prepare('SELECT id, username, role, is_banned, bonus_points, points FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $userStmt->execute([':id' => $userId]);
            $target = $userStmt->fetch();
            if ($target === false) {
                throw new RuntimeException('User not found.');
            }

            $currentRole = (string) $target['role'];
            $currentBanned = (int) ($target['is_banned'] ?? 0) === 1 ? 1 : 0;
            if ($currentRole === 'admin' && $role === 'player') {
                $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn();
                if ($adminCount <= 1) {
                    throw new RuntimeException('Cannot demote the last admin account.');
                }
            }

            $currentBonus = round((float) ($target['bonus_points'] ?? 0.0), 2);
            $newBonus = round($currentBonus + $bonusDelta, 2);
            if ($newBonus < -999999 || $newBonus > 999999) {
                throw new RuntimeException('Bonus points value is out of range after applying changes.');
            }

            $currentPoints = round((float) ($target['points'] ?? 0.0), 2);
            $newPoints = round($currentPoints + $bonusDelta, 2);

            $beforeUserData = [
                'id' => (int) $target['id'],
                'username' => (string) $target['username'],
                'role' => $currentRole,
                'is_banned' => $currentBanned,
                'bonus_points' => $currentBonus,
                'points' => $currentPoints,
            ];

            $updateUser = $pdo->prepare('UPDATE users
                                         SET role = :role,
                                             is_banned = :is_banned,
                                             bonus_points = :bonus_points,
                                             points = ROUND(COALESCE(points, 0.00) + :bonus_delta, 2)
                                         WHERE id = :id');
            $updateUser->execute([
                ':role' => $role,
                ':is_banned' => $isBanned,
                ':bonus_points' => $newBonus,
                ':bonus_delta' => $bonusDelta,
                ':id' => $userId,
            ]);

            if ((int) ($_SESSION['user_id'] ?? 0) === $userId && $role !== 'admin') {
                unset($_SESSION['admin_logged_in']);
            }

            $afterUserData = [
                'id' => (int) $target['id'],
                'username' => (string) $target['username'],
                'role' => $role,
                'is_banned' => $isBanned,
                'bonus_points' => $newBonus,
                'points' => $newPoints,
            ];

            $pdo->commit();
            admin_notify_user_updated($beforeUserData, $afterUserData, $bonusDelta);

            flash('success', 'Updated user settings for ' . (string) $target['username'] . '. Bonus delta: ' . ($bonusDelta >= 0 ? '+' : '') . number_format($bonusDelta, 2) . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect($redirectTarget);
    }
    if ($action === 'review' && is_admin()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', 'Invalid session token.');
            redirect('admin.php');
        }

        $submissionId = (int) ($_POST['submission_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $reviewNote = trim((string) ($_POST['review_note'] ?? ''));

        if ($submissionId < 1 || !in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Invalid review request.');
            redirect('admin.php');
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $submissionStmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id FOR UPDATE');
            $submissionStmt->execute([':id' => $submissionId]);
            $submission = $submissionStmt->fetch();

            if ($submission === false) {
                throw new RuntimeException('Submission not found.');
            }

            if ((string) $submission['status'] !== 'pending') {
                throw new RuntimeException('Submission already reviewed.');
            }

            $submissionPlayer = trim((string) ($submission['player'] ?? ''));
            if ($submissionPlayer === '' && (int) ($submission['submitted_by_user_id'] ?? 0) > 0) {
                $playerLookup = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
                $playerLookup->execute([':id' => (int) $submission['submitted_by_user_id']]);
                $submissionPlayer = (string) ($playerLookup->fetchColumn() ?: '');
            }
            if ($submissionPlayer === '') {
                $submissionPlayer = 'Unknown';
            }

            $recordWebhookFields = [];

            if ($decision === 'approved') {
                if ((string) $submission['type'] !== 'completion') {
                    throw new RuntimeException('Demon submissions are disabled. Add levels manually from the admin form.');
                }

                $demonStmt = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                $demonStmt->execute([':name' => (string) $submission['demon_name']]);
                $demonId = $demonStmt->fetchColumn();

                if ($demonId === false) {
                    throw new RuntimeException('Cannot approve completion: demon not found.');
                }

                $progress = max(1, min(100, (int) ($submission['progress'] ?? 100)));
                $submittedVideo = (string) ($submission['video_url'] ?: '#');
                $submittedNotes = trim((string) ($submission['notes'] ?? ''));

                $existingStmt = $pdo->prepare('SELECT id, progress, video_url, notes, placement FROM completions WHERE demon_id = :demon_id AND player = :player LIMIT 1');
                $existingStmt->execute([
                    ':demon_id' => (int) $demonId,
                    ':player' => $submissionPlayer,
                ]);
                $existing = $existingStmt->fetch();

                if ($existing !== false) {
                    $oldProgress = (int) ($existing['progress'] ?? 0);
                    $newProgress = max($oldProgress, $progress);
                    $oldVideo = (string) ($existing['video_url'] ?? '#');
                    $oldNotes = trim((string) ($existing['notes'] ?? ''));

                    $updateRecord = $pdo->prepare('UPDATE completions
                        SET video_url = :video_url,
                            progress = :progress,
                            notes = :notes
                        WHERE id = :id');

                    $updateRecord->execute([
                        ':video_url' => $submittedVideo,
                        ':progress' => $newProgress,
                        ':notes' => $submittedNotes !== '' ? $submittedNotes : null,
                        ':id' => (int) $existing['id'],
                    ]);

                    $recordWebhookFields[] = [
                        'name' => 'Record Action',
                        'value' => 'Updated completion #' . (int) $existing['id'],
                        'inline' => false,
                    ];
                    $recordWebhookFields[] = [
                        'name' => 'Placement',
                        'value' => '#' . (int) ($existing['placement'] ?? 0),
                        'inline' => true,
                    ];

                    $hasCompletionFieldChange = false;
                    if ($newProgress !== $oldProgress) {
                        $recordWebhookFields[] = [
                            'name' => 'Completion Progress',
                            'value' => $oldProgress . '% -> ' . $newProgress . '%',
                            'inline' => true,
                        ];
                        $hasCompletionFieldChange = true;
                    }

                    if ($oldVideo !== $submittedVideo) {
                        $recordWebhookFields[] = [
                            'name' => 'Video URL',
                            'value' => $oldVideo . ' -> ' . $submittedVideo,
                            'inline' => false,
                        ];
                        $hasCompletionFieldChange = true;
                    }

                    $oldNotesLabel = $oldNotes !== '' ? $oldNotes : '-';
                    $newNotesLabel = $submittedNotes !== '' ? $submittedNotes : '-';
                    if ($oldNotesLabel !== $newNotesLabel) {
                        $recordWebhookFields[] = [
                            'name' => 'Notes',
                            'value' => $oldNotesLabel . ' -> ' . $newNotesLabel,
                            'inline' => false,
                        ];
                        $hasCompletionFieldChange = true;
                    }

                    if (!$hasCompletionFieldChange) {
                        $recordWebhookFields[] = [
                            'name' => 'Record Delta',
                            'value' => 'No completion field changed (duplicate or equivalent proof).',
                            'inline' => false,
                        ];
                    }
                } else {
                    $placementStmt = $pdo->prepare('SELECT COALESCE(MAX(placement), 0) + 1 AS next_placement
                                                    FROM completions
                                                    WHERE demon_id = :demon_id');
                    $placementStmt->execute([':demon_id' => (int) $demonId]);
                    $nextPlacement = (int) $placementStmt->fetchColumn();

                    $insertCompletion = $pdo->prepare('INSERT INTO completions
                        (demon_id, player, video_url, progress, placement, notes)
                        VALUES
                        (:demon_id, :player, :video_url, :progress, :placement, :notes)');

                    $insertCompletion->execute([
                        ':demon_id' => (int) $demonId,
                        ':player' => $submissionPlayer,
                        ':video_url' => $submittedVideo,
                        ':progress' => $progress,
                        ':placement' => $nextPlacement,
                        ':notes' => $submittedNotes !== '' ? $submittedNotes : null,
                    ]);

                    $newCompletionId = (int) $pdo->lastInsertId();
                    $recordWebhookFields[] = [
                        'name' => 'Record Action',
                        'value' => 'Created completion #' . $newCompletionId,
                        'inline' => false,
                    ];
                    $recordWebhookFields[] = [
                        'name' => 'Placement',
                        'value' => '#' . $nextPlacement,
                        'inline' => true,
                    ];
                    $recordWebhookFields[] = [
                        'name' => 'Completion Progress',
                        'value' => $progress . '%',
                        'inline' => true,
                    ];
                }
            }

            $updateStmt = $pdo->prepare('UPDATE submissions
                                         SET status = :status,
                                             review_note = :review_note,
                                             reviewed_at = NOW()
                                         WHERE id = :id');
            $updateStmt->execute([
                ':status' => $decision,
                ':review_note' => $reviewNote !== '' ? $reviewNote : null,
                ':id' => $submissionId,
            ]);

            $pdo->commit();
            admin_notify_submission_reviewed($submission, $decision, $reviewNote, $submissionPlayer, $recordWebhookFields);

            flash('success', 'Submission #' . $submissionId . ' marked as ' . $decision . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect('admin.php');
    }
}

if (!is_admin()) {
    render_header('Admin Login', 'admin');
    ?>
    <section class="panel panel-narrow fade">
        <div class="panel-head">
            <h1>Admin Login</h1>
            <p>Only admins can moderate submissions and add or rank levels.</p>
        </div>
        <?php if ($adminPassword === 'changeme'): ?>
            <div class="info-red">Default password is still active (`changeme`). Change it in config.</div>
        <?php endif; ?>
        <form class="stack-form" method="post" action="<?= e(base_url('admin.php')) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="login">
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" required>
            </label>
            <button class="button blue hover" type="submit">Login</button>
        </form>
    </section>
    <?php
    render_footer();
    exit;
}

$pending = db()->query('SELECT s.*, u.username AS submitter_username
                        FROM submissions s
                        LEFT JOIN users u ON u.id = s.submitted_by_user_id
                        WHERE s.status = "pending"
                        ORDER BY s.created_at ASC')->fetchAll();

$reviewed = db()->query('SELECT s.*, u.username AS submitter_username
                         FROM submissions s
                         LEFT JOIN users u ON u.id = s.submitted_by_user_id
                         WHERE s.status <> "pending"
                         ORDER BY s.reviewed_at DESC
                         LIMIT 30')->fetchAll();

$adminPdo = db();
$hasBonusPoints = admin_column_exists($adminPdo, 'users', 'bonus_points');
$hasUserBanned = admin_column_exists($adminPdo, 'users', 'is_banned');

$stats = [
    'pending' => (int) $adminPdo->query('SELECT COUNT(*) FROM submissions WHERE status = "pending"')->fetchColumn(),
    'approved' => (int) $adminPdo->query('SELECT COUNT(*) FROM submissions WHERE status = "approved"')->fetchColumn(),
    'rejected' => (int) $adminPdo->query('SELECT COUNT(*) FROM submissions WHERE status = "rejected"')->fetchColumn(),
    'players' => (int) $adminPdo->query('SELECT COUNT(*) FROM users WHERE role = "player"')->fetchColumn(),
    'admins' => (int) $adminPdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn(),
];
$stats['banned'] = $hasUserBanned
    ? (int) $adminPdo->query('SELECT COUNT(*) FROM users WHERE COALESCE(is_banned, 0) = 1')->fetchColumn()
    : 0;

$usersSelectFields = [
    'id',
    'username',
    'email',
    'country_code',
    'role',
    'points',
    $hasBonusPoints ? 'bonus_points' : '0.00 AS bonus_points',
    $hasUserBanned ? 'is_banned' : '0 AS is_banned',
    'created_at',
];
$usersQuery = trim((string) ($_GET['users_q'] ?? ''));
$usersQuery = function_exists('mb_substr')
    ? (string) mb_substr($usersQuery, 0, 80)
    : (string) substr($usersQuery, 0, 80);

$users = [];
if ($usersQuery !== '') {
    $usersSql = 'SELECT ' . implode(', ', $usersSelectFields) . '
                 FROM users
                 WHERE username LIKE :users_query
                 ORDER BY created_at DESC
                 LIMIT 20';
    $usersStmt = $adminPdo->prepare($usersSql);
    $usersStmt->execute([':users_query' => '%' . $usersQuery . '%']);
    $users = $usersStmt->fetchAll();
}

$maxPosition = (int) db()->query('SELECT COALESCE(MAX(position), 1) FROM demons')->fetchColumn();
$editableDemons = db()->query('SELECT id, name, position, requirement
                               FROM demons
                               ORDER BY position ASC, name ASC')->fetchAll();

render_header('Admin', 'admin');
?>
<section class="panel fade">
    <div class="panel-head">
        <div>
            <h1>Admin Dashboard</h1>
            <p>Moderation center for records and level management.</p>
        </div>
    </div>

    <div class="detail-grid" style="grid-template-columns: repeat(6, 1fr); gap: 10px;">
        <div class="panel subtle"><h3><?= $stats['pending'] ?></h3><p>Pending</p></div>
        <div class="panel subtle"><h3><?= $stats['approved'] ?></h3><p>Approved</p></div>
        <div class="panel subtle"><h3><?= $stats['rejected'] ?></h3><p>Rejected</p></div>
        <div class="panel subtle"><h3><?= $stats['players'] ?></h3><p>Players</p></div>
        <div class="panel subtle"><h3><?= $stats['admins'] ?></h3><p>Admins</p></div>
        <div class="panel subtle"><h3><?= $stats['banned'] ?></h3><p>Banned</p></div>
    </div>
</section>


<section class="panel fade">
    <div class="panel-head">
        <h2>Quick Actions</h2>
        <p>Choose one tool and only that section will be displayed below.</p>
    </div>
    <div class="admin-quick-actions">
        <a class="admin-action-tile" href="#admin-add-level" data-open-admin-section="admin-add-level">
            <span class="admin-action-title">Add Level</span>
            <small>Create a new demon entry.</small>
        </a>
        <a class="admin-action-tile" href="#admin-edit-level" data-open-admin-section="admin-edit-level">
            <span class="admin-action-title">Edit Level</span>
            <small>Update level info and ranking.</small>
        </a>
        <a class="admin-action-tile" href="#admin-user-management" data-open-admin-section="admin-user-management">
            <span class="admin-action-title">User Management</span>
            <small>Adjust role, ban status, and bonus points.</small>
        </a>
        <a class="admin-action-tile" href="#admin-pending-submissions" data-open-admin-section="admin-pending-submissions">
            <span class="admin-action-title">Pending Submissions</span>
            <small>Review new records in queue.</small>
        </a>
        <a class="admin-action-tile" href="#admin-reviewed-submissions" data-open-admin-section="admin-reviewed-submissions">
            <span class="admin-action-title">Recently Reviewed</span>
            <small>Check moderation history.</small>
        </a>
    </div>
</section>

<section class="panel fade admin-tool-section" id="admin-add-level">
    <div class="panel-head">
        <h2>Add Level</h2>
        <p>Only admins can add demons to the list. Extra level metadata is optional.</p>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('admin.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_level">

        <div class="detail-grid" style="grid-template-columns: 2fr 1fr 1fr;">
            <label class="field">
                <span>Level Name</span>
                <input type="text" name="name" required>
            </label>
            <label class="field">
                <span>Position (optional)</span>
                <input type="number" min="1" name="position" placeholder="Auto = end">
            </label>
            <label class="field">
                <span>Requirement (%)</span>
                <input type="number" min="1" max="100" name="requirement" value="100" required>
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Publisher</span>
                <input type="text" name="publisher" required>
            </label>
            <label class="field">
                <span>Verifier</span>
                <input type="text" name="verifier">
            </label>
        </div>

        <label class="field">
            <span>Difficulty</span>
            <input type="text" name="difficulty" value="Extreme Demon" required>
        </label>

        <label class="field">
            <span>Verification Video URL</span>
            <input type="url" name="video_url" required>
        </label>

        <label class="field">
            <span>Thumbnail URL (optional)</span>
            <input type="url" name="thumbnail_url">
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Level ID (optional)</span>
                <input type="text" name="level_id" placeholder="e.g. 12345678">
            </label>
            <label class="field">
                <span>Level Length (optional)</span>
                <input type="text" name="level_length" placeholder="e.g. Long">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Song (optional)</span>
                <input type="text" name="song" placeholder="e.g. Creo - Sphere">
            </label>
            <label class="field">
                <span>Object Count (optional)</span>
                <input type="number" min="0" name="object_count" placeholder="e.g. 178945">
            </label>
        </div>

        <label class="cb-container" style="text-align: left; margin-top: 6px;">
            <input type="checkbox" name="legacy" value="1">
            <span class="checkmark"></span>
            Mark as Legacy list entry
        </label>

        <button class="button blue hover" type="submit">Add Level</button>
    </form>
</section>

<section class="panel fade admin-tool-section" id="admin-edit-level">
    <div class="panel-head">
        <h2>Edit Level</h2>
        <p>Update level information and ranking in one place.</p>
    </div>

    <form class="stack-form" method="post" action="<?= e(base_url('admin.php')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit_level">

        <label class="field">
            <span>Level to Edit</span>
            <input type="text" name="demon_name" data-suggest-list="admin-demon-list" placeholder="Type level name..." autocomplete="off" required>
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>New Name (optional)</span>
                <input type="text" name="name" placeholder="Leave blank to keep current">
            </label>
            <label class="field">
                <span>Difficulty (optional)</span>
                <input type="text" name="difficulty" placeholder="Leave blank to keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <label class="field">
                <span>Requirement % (optional)</span>
                <input type="number" min="1" max="100" name="requirement" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Publisher (optional)</span>
                <input type="text" name="publisher" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Verifier (optional)</span>
                <input type="text" name="verifier" placeholder="Keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Verification Video URL (optional)</span>
                <input type="url" name="video_url" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Thumbnail URL (optional)</span>
                <input type="url" name="thumbnail_url" placeholder="Keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Level ID (optional)</span>
                <input type="text" name="level_id" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Level Length (optional)</span>
                <input type="text" name="level_length" placeholder="Keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Song (optional)</span>
                <input type="text" name="song" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Object Count (optional)</span>
                <input type="number" min="0" name="object_count" placeholder="Keep current">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <label class="field">
                <span>Legacy Status</span>
                <select name="legacy_status">
                    <option value="keep">Keep current</option>
                    <option value="normal">Set as Current List</option>
                    <option value="legacy">Set as Legacy</option>
                </select>
            </label>
            <label class="field">
                <span>New Position (optional)</span>
                <input type="number" min="1" max="<?= $maxPosition ?>" name="new_position" placeholder="Keep current">
            </label>
            <label class="field">
                <span>Move Note (optional)</span>
                <input type="text" name="move_note" placeholder="Reason for rank change">
            </label>
        </div>

        <button class="button blue hover" type="submit">Save Level Changes</button>
    </form>

    <datalist id="admin-demon-list">
        <?php foreach ($editableDemons as $demon): ?>
            <option value="<?= e((string) $demon['name']) ?>" label="#<?= (int) $demon['position'] ?> (Req <?= (int) $demon['requirement'] ?>%)"></option>
        <?php endforeach; ?>
    </datalist>
</section>

<section class="panel fade admin-tool-section admin-list-section" id="admin-user-management">
    <div class="panel-head">
        <h2>User Management</h2>
        <p>Adjust role, banned status, and bonus points for each account in one place.</p>
    </div>

    <form class="admin-user-toolbar" method="get" action="<?= e(base_url('admin.php#admin-user-management')) ?>">
        <div class="admin-user-search-grid">
            <label class="field">
                <span>Search Username</span>
                <input id="admin-user-search" type="text" name="users_q" value="<?= e($usersQuery) ?>" placeholder="Type username...">
            </label>
            <div class="admin-user-search-actions">
                <button class="button white hover" type="submit">Search</button>
                <?php if ($usersQuery !== ''): ?>
                    <a class="button ghost hover" href="<?= e(base_url('admin.php#admin-user-management')) ?>">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <div class="table-wrap">
        <table class="data-table admin-user-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Banned</th>
                    <th>Points</th>
                    <th>Bonus</th>
                    <th>Joined</th>
                    <th>Adjust</th>
                </tr>
            </thead>
            <tbody id="admin-user-table-body">
                <?php if ($usersQuery === ''): ?>
                    <tr><td colspan="9" class="muted">Enter a username and press Search to load users.</td></tr>
                <?php elseif ($users === []): ?>
                    <tr><td colspan="9" class="muted">No users found for "<?= e($usersQuery) ?>".</td></tr>
                <?php endif; ?>

                <?php foreach ($users as $member): ?>
                    <?php
                    $memberIsAdmin = (string) $member['role'] === 'admin';
                    $isLastAdmin = $memberIsAdmin && $stats['admins'] <= 1;
                    $isCurrentUser = current_user_id() !== null && (int) $member['id'] === (int) current_user_id();
                    $isBanned = (int) ($member['is_banned'] ?? 0) === 1;
                    $countryCode = normalize_country_code((string) ($member['country_code'] ?? ''));
                    $countryText = country_flag_html($countryCode);
                    ?>
                    <tr data-user-row data-search-value="<?= e(strtolower((string) $member['username'] . ' ' . (string) ($member['email'] ?? '') . ' ' . (string) $member['role'] . ' ' . ($isBanned ? 'banned' : 'active'))) ?>">
                        <td>#<?= (int) $member['id'] ?></td>
                        <td>
                            <div class="admin-user-identity">
                                <?php if ($countryText !== ''): ?>
                                    <span class="admin-user-country"><?= $countryText ?></span>
                                <?php endif; ?>
                                <b title="<?= e((string) $member['username']) ?>"><?= e((string) $member['username']) ?></b>
                            </div>
                        </td>
                        <td><?= e((string) ($member['email'] ?: '-')) ?></td>
                        <td><span class="badge <?= $memberIsAdmin ? 'approved' : '' ?>"><?= e(strtoupper((string) $member['role'])) ?></span></td>
                        <td><span class="badge <?= $isBanned ? 'error' : 'success' ?>"><?= $isBanned ? 'BANNED' : 'ACTIVE' ?></span></td>
                        <td><?= e(number_format((float) ($member['points'] ?? 0.0), 2)) ?></td>
                        <td><?= e(number_format((float) ($member['bonus_points'] ?? 0.0), 2)) ?></td>
                        <td><?= e(date('Y-m-d', strtotime((string) $member['created_at']))) ?></td>
                        <td class="admin-user-action-cell">
                            <form class="admin-user-edit-form" method="post" action="<?= e(base_url('admin.php')) ?>">
                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                                <input type="hidden" name="users_q" value="<?= e($usersQuery) ?>">

                                <div class="admin-user-edit-controls">
                                    <label class="admin-user-edit-field">
                                        <span>Role</span>
                                        <select name="role">
                                            <option value="admin" <?= $memberIsAdmin ? 'selected' : '' ?>>ADMIN</option>
                                            <option value="player" <?= !$memberIsAdmin ? 'selected' : '' ?> <?= $isLastAdmin ? 'disabled' : '' ?>>PLAYER</option>
                                        </select>
                                    </label>
                                    <label class="admin-user-edit-field">
                                        <span>Banned</span>
                                        <select name="is_banned">
                                            <option value="0" <?= !$isBanned ? 'selected' : '' ?>>NO</option>
                                            <option value="1" <?= $isBanned ? 'selected' : '' ?>>YES</option>
                                        </select>
                                    </label>
                                    <label class="admin-user-edit-field">
                                        <span>Bonus +/-</span>
                                        <input type="number" name="bonus_delta" step="0.01" value="0" placeholder="+10 or -5">
                                    </label>
                                </div>

                                <button class="button blue hover small" type="submit">Save</button>
                            </form>
                            <?php if ($isCurrentUser): ?>
                                <span class="muted admin-user-note">(you)</span>
                            <?php endif; ?>
                            <?php if ($isLastAdmin): ?>
                                <span class="muted admin-user-note">Last admin cannot be demoted.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel fade admin-tool-section admin-list-section" id="admin-pending-submissions">
    <div class="panel-head">
        <h2>Pending Submissions</h2>
    </div>

    <?php if ($pending === []): ?>
        <p class="muted">No pending submissions.</p>
    <?php endif; ?>

    <?php foreach ($pending as $item): ?>
        <article class="moderation-card">
            <div class="moderation-head">
                <strong>#<?= (int) $item['id'] ?></strong>
                <span class="badge"><?= e(strtoupper((string) $item['type'])) ?></span>
                <span class="muted">Submitted: <?= e(date('Y-m-d H:i', strtotime((string) $item['created_at']))) ?></span>
            </div>

            <dl class="key-value compact">
                <div><dt>Submitter</dt><dd><?= e((string) ($item['submitter_username'] ?: $item['player'] ?: 'Unknown')) ?></dd></div>
                <div><dt>Demon</dt><dd><?= e((string) $item['demon_name']) ?></dd></div>
                <div><dt>Progress</dt><dd><?= $item['progress'] !== null ? (int) $item['progress'] . '%' : '-' ?></dd></div>
                <div><dt>Platform</dt><dd><?= e((string) ($item['platform'] ?: '-')) ?></dd></div>
                <div><dt>Refresh</dt><dd><?= $item['refresh_rate'] !== null ? (int) $item['refresh_rate'] . 'Hz' : '-' ?></dd></div>
                <div><dt>Proof</dt><dd><a class="link" target="_blank" rel="noreferrer" href="<?= e((string) ($item['video_url'] ?: '#')) ?>">Open</a></dd></div>
                <div><dt>Raw Footage</dt><dd><?= !empty($item['raw_footage_url']) ? '<a class="link" target="_blank" rel="noreferrer" href="' . e((string) $item['raw_footage_url']) . '">Open</a>' : '-' ?></dd></div>
            </dl>

            <?php if (!empty($item['notes'])): ?>
                <p><strong>Notes:</strong> <?= e((string) $item['notes']) ?></p>
            <?php endif; ?>

            <form class="moderation-actions" method="post" action="<?= e(base_url('admin.php')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="review">
                <input type="hidden" name="submission_id" value="<?= (int) $item['id'] ?>">
                <label class="field">
                    <span>Review Note</span>
                    <input type="text" name="review_note" placeholder="Optional note">
                </label>
                <button class="button blue hover small" type="submit" name="decision" value="approved" data-confirm="Approve this submission?">Approve</button>
                <button class="button red hover small" type="submit" name="decision" value="rejected" data-confirm="Reject this submission?">Reject</button>
            </form>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel fade admin-tool-section admin-list-section" id="admin-reviewed-submissions">
    <div class="panel-head">
        <h2>Recently Reviewed</h2>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Demon</th>
                    <th>Submitter</th>
                    <th>Status</th>
                    <th>Reviewed At</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($reviewed === []): ?>
                    <tr><td colspan="6" class="muted">No reviewed submissions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($reviewed as $item): ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td><?= e((string) $item['type']) ?></td>
                        <td><?= e((string) $item['demon_name']) ?></td>
                        <td><?= e((string) ($item['submitter_username'] ?: $item['player'] ?: '-')) ?></td>
                        <td><span class="badge <?= $item['status'] === 'approved' ? 'success' : 'error' ?>"><?= e((string) $item['status']) ?></span></td>
                        <td><?= e((string) ($item['reviewed_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
(() => {
    const links = Array.from(document.querySelectorAll('[data-open-admin-section]'));
    const sections = Array.from(document.querySelectorAll('.admin-tool-section'));
    const params = new URLSearchParams(window.location.search);
    const preferredSectionId = params.get('users_q') ? 'admin-user-management' : '';

    const setActiveLink = (sectionId) => {
        links.forEach((link) => {
            if (!(link instanceof HTMLElement)) {
                return;
            }
            const linkSection = link.getAttribute('data-open-admin-section') || '';
            link.classList.toggle('is-active', sectionId !== '' && linkSection === sectionId);
        });
    };

    const showSection = (sectionId, updateHash = true) => {
        sections.forEach((section) => {
            if (!(section instanceof HTMLElement)) {
                return;
            }
            section.classList.remove('admin-tool-section-visible');
            section.style.display = 'none';
        });

        let target = null;
        if (sectionId !== '') {
            const candidate = document.getElementById(sectionId);
            if (candidate instanceof HTMLElement && candidate.classList.contains('admin-tool-section')) {
                target = candidate;
            }
        }
        if (!(target instanceof HTMLElement) && preferredSectionId !== '') {
            const preferred = document.getElementById(preferredSectionId);
            if (preferred instanceof HTMLElement && preferred.classList.contains('admin-tool-section')) {
                target = preferred;
            }
        }

        if (target instanceof HTMLElement) {
            target.classList.add('admin-tool-section-visible');
            target.style.display = '';
            setActiveLink(target.id || '');
            if (updateHash) {
                window.history.replaceState(null, '', `#${target.id}`);
            }
            return;
        }

        setActiveLink('');
        if (updateHash) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    };

    links.forEach((link) => {
        if (!(link instanceof HTMLElement)) {
            return;
        }
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const sectionId = link.getAttribute('data-open-admin-section') || '';
            showSection(sectionId, true);
            const target = document.getElementById(sectionId);
            if (target instanceof HTMLElement) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    window.addEventListener('hashchange', () => {
        showSection(window.location.hash.replace(/^#/, ''), false);
    });

    showSection(window.location.hash.replace(/^#/, ''), false);
})();
</script>
<?php render_footer(); ?>








