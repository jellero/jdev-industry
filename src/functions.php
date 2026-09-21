<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url(string $path): string
{
    return $path;
}

function db(): PDO
{
    global $pdo;
    return $pdo;
}

function config(string $key, mixed $default = null): mixed
{
    global $config;
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function machines(bool $onlyActive = false): array
{
    $sql = 'SELECT * FROM machines';
    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY name';
    return db()->query($sql)->fetchAll();
}

function machineById(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM machines WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function machineApi(array $machine): MachineApi
{
    return new MachineApi(
        (string) $machine['base_url'],
        max(2, (int) $machine['api_timeout_seconds'])
    );
}

function setFlash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function pullFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function statusLabel(string $status): string
{
    return match ($status) {
        'planned' => 'Pianificata',
        'ready' => 'Pronta',
        'sent' => 'Inviata alla macchina',
        'in_progress' => 'In lavorazione',
        'done' => 'Completata',
        'cancelled' => 'Annullata',
        default => $status,
    };
}

function normalizeList(mixed $payload): array
{
    if (!is_array($payload)) {
        return [];
    }

    if (array_is_list($payload)) {
        return $payload;
    }

    foreach (['Items', 'items', 'Data', 'data', 'Events', 'events', 'Log', 'log', 'Projects', 'projects'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return array_is_list($payload[$key]) ? $payload[$key] : [$payload[$key]];
        }
    }

    return [$payload];
}

function projectSummary(mixed $payload): array
{
    $items = normalizeList($payload);
    $selected = null;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $state = strtolower((string) ($item['Status'] ?? $item['status'] ?? $item['State'] ?? $item['state'] ?? ''));
        if (preg_match('/active|running|progress|lavor|work|execut/', $state)) {
            $selected = $item;
            break;
        }
    }

    if ($selected === null) {
        $selected = is_array($items[0] ?? null) ? $items[0] : [];
    }

    $name = $selected['Project']
        ?? $selected['project']
        ?? $selected['Name']
        ?? $selected['name']
        ?? $selected['Code']
        ?? $selected['code']
        ?? $selected['FileName']
        ?? $selected['filename']
        ?? null;

    $progress = $selected['Progress']
        ?? $selected['progress']
        ?? $selected['Percentage']
        ?? $selected['percentage']
        ?? $selected['Percent']
        ?? $selected['percent']
        ?? $selected['Advancement']
        ?? $selected['advancement']
        ?? null;

    if ($progress === null) {
        $done = $selected['Completed'] ?? $selected['completed'] ?? null;
        $total = $selected['Total'] ?? $selected['total'] ?? null;
        if (is_numeric($done) && is_numeric($total) && (float) $total > 0) {
            $progress = ((float) $done / (float) $total) * 100;
        }
    }

    if (is_numeric($progress)) {
        $progress = (float) $progress;
        if ($progress >= 0 && $progress <= 1) {
            $progress *= 100;
        }
        $progress = max(0, min(100, $progress));
    } else {
        $progress = null;
    }

    return [
        'name' => $name,
        'progress' => $progress,
        'item' => $selected,
        'items' => $items,
    ];
}

function eventDateTime(mixed $value): ?string
{
    if (is_numeric($value)) {
        $number = (float) $value;
        if ($number > 100000000000) {
            $number /= 1000;
        }
        return date('Y-m-d H:i:s', (int) $number);
    }

    if (is_string($value) && $value !== '') {
        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    return null;
}

function renderHeader(string $title): void
{
    $appName = (string) config('app.name', 'JDEV Industry');
    $flash = pullFlash();
    ?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php"><?= e($appName) ?></a>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="clients.php">Clienti</a>
        <a href="jobs.php">Commesse</a>
        <a href="activity.php">Attività recenti</a>
        <a href="history.php">Storico lavori</a>
        <a href="warehouse.php">Magazzino</a>
        <a href="settings.php">Impostazioni</a>
        <a href="api-test.php">Test API</a>
    </nav>
</header>
<main class="container">
    <div class="page-title"><h1><?= e($title) ?></h1></div>
    <?php if ($flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
<?php
}

function renderFooter(): void
{
    ?>
</main>
<script src="assets/app.js"></script>
</body>
</html>
<?php
}
