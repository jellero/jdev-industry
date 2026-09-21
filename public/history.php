<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$allMachines = machines(false);
$machineId = filter_input(INPUT_GET, 'machine_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT)
    ?: 0;
$date = (string) ($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$machine = $machineId ? machineById((int) $machineId) : null;
$liveResult = null;
$events = [];
$archivedCount = null;

if ($machine && ($_SERVER['REQUEST_METHOD'] === 'GET' || ($_POST['action'] ?? '') === 'archive')) {
    $endpoint = '/logDate/' . str_replace('-', '', $date);
    $liveResult = machineApi($machine)->get($endpoint);
    if ($liveResult['ok'] && is_array($liveResult['json'])) {
        $events = normalizeList($liveResult['json']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    if (!$machine) {
        setFlash('Seleziona una macchina valida.', 'error');
        redirect('history.php');
    }
    if (!$liveResult || !$liveResult['ok'] || !is_array($liveResult['json'])) {
        setFlash('Impossibile leggere il log della giornata dalla macchina.', 'error');
        redirect('history.php?machine_id=' . $machineId . '&date=' . urlencode($date));
    }

    $inserted = 0;
    $insert = db()->prepare(
        'INSERT IGNORE INTO event_logs
        (machine_id, job_id, event_key, event_time, event_type, message, project, reference, material, value_num, number_num, elapsed_time, waste, source_day, payload_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $findJob = db()->prepare('SELECT id FROM jobs WHERE external_project=? OR code=? ORDER BY id DESC LIMIT 1');

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $project = trim((string) ($event['Project'] ?? ''));
        $jobId = null;
        if ($project !== '') {
            $findJob->execute([$project, $project]);
            $jobId = $findJob->fetchColumn() ?: null;
        }

        $guid = trim((string) ($event['Guid'] ?? ''));
        $eventKey = hash('sha256', $guid !== '' ? ('guid:' . $guid) : json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $insert->execute([
            $machine['id'],
            $jobId,
            $eventKey,
            eventDateTime($event['DateTime'] ?? null),
            isset($event['Type']) ? (string) $event['Type'] : null,
            isset($event['Message']) ? (string) $event['Message'] : null,
            $project !== '' ? $project : null,
            isset($event['Reference']) ? (string) $event['Reference'] : null,
            isset($event['Material']) ? (string) $event['Material'] : null,
            is_numeric($event['Value'] ?? null) ? $event['Value'] : null,
            is_numeric($event['Number'] ?? null) ? (int) $event['Number'] : null,
            is_numeric($event['ElapsedTime'] ?? null) ? $event['ElapsedTime'] : null,
            is_numeric($event['Waste'] ?? null) ? $event['Waste'] : null,
            $date,
            $payload,
        ]);
        $inserted += $insert->rowCount();
    }

    setFlash('Storico aggiornato: ' . $inserted . ' nuovi eventi archiviati.');
    redirect('history.php?machine_id=' . $machineId . '&date=' . urlencode($date));
}

$localEvents = [];
if ($machine) {
    $stmt = db()->prepare('SELECT e.*, j.code AS job_code FROM event_logs e LEFT JOIN jobs j ON j.id=e.job_id WHERE e.machine_id=? AND e.source_day=? ORDER BY e.event_time DESC, e.id DESC LIMIT 500');
    $stmt->execute([$machine['id'], $date]);
    $localEvents = $stmt->fetchAll();
}

renderHeader('Storico lavori');
?>
<section class="card" style="margin-bottom:16px">
    <form method="get" class="form-grid">
        <div class="form-row"><label>Macchina</label><select name="machine_id" required>
            <option value="">Seleziona…</option>
            <?php foreach ($allMachines as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int)$machineId === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Giorno</label><input type="date" name="date" value="<?= e($date) ?>" required></div>
        <div class="form-row full actions"><button class="btn" type="submit">Leggi dalla macchina</button></div>
    </form>
</section>

<?php if ($machine): ?>
<div class="grid">
    <section class="card col-12">
        <div class="page-title">
            <div><h2>Log macchina · <?= e($date) ?></h2><div class="muted small">Endpoint: /logDate/<?= e(str_replace('-', '', $date)) ?></div></div>
            <?php if ($liveResult && $liveResult['ok'] && $events): ?>
                <form method="post">
                    <input type="hidden" name="action" value="archive"><input type="hidden" name="machine_id" value="<?= (int) $machine['id'] ?>"><input type="hidden" name="date" value="<?= e($date) ?>">
                    <button class="btn secondary" type="submit">Archivia nel gestionale</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$liveResult || !$liveResult['ok']): ?>
            <div class="alert error">Lettura non riuscita: <?= e($liveResult['error'] ?? ('HTTP ' . ($liveResult['status'] ?? 0))) ?></div>
            <?php if ($liveResult): ?><pre class="raw"><?= e($liveResult['body']) ?></pre><?php endif; ?>
        <?php elseif (!$events): ?>
            <div class="empty">La risposta è valida ma non contiene un elenco eventi riconosciuto.</div>
            <pre class="raw"><?= e(json_encode($liveResult['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Data/ora</th><th>Tipo</th><th>Progetto</th><th>Riferimento</th><th>Materiale</th><th>Tempo</th><th>Scarto</th></tr></thead>
                <tbody><?php foreach ($events as $event): if (!is_array($event)) continue; ?><tr>
                    <td><?= e(eventDateTime($event['DateTime'] ?? null) ?: ($event['DateTime'] ?? '—')) ?></td>
                    <td><strong><?= e($event['Type'] ?? '—') ?></strong><br><span class="muted"><?= e($event['Message'] ?? '') ?></span></td>
                    <td><?= e($event['Project'] ?? '—') ?></td>
                    <td><?= e($event['Reference'] ?? '—') ?></td>
                    <td><?= e($event['Material'] ?? '—') ?></td>
                    <td><?= isset($event['ElapsedTime']) ? e($event['ElapsedTime']) . ' s' : '—' ?></td>
                    <td><?= e($event['Waste'] ?? '—') ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="card col-12">
        <h2>Archivio locale · <?= count($localEvents) ?> eventi</h2>
        <?php if (!$localEvents): ?><div class="empty">Non hai ancora archiviato eventi per questa giornata.</div>
        <?php else: ?><div class="table-wrap"><table>
            <thead><tr><th>Data/ora</th><th>Tipo</th><th>Commessa</th><th>Progetto</th><th>Riferimento</th><th>Materiale</th></tr></thead>
            <tbody><?php foreach ($localEvents as $event): ?><tr>
                <td><?= e($event['event_time'] ?: '—') ?></td>
                <td><?= e($event['event_type'] ?: '—') ?></td>
                <td><?= e($event['job_code'] ?: '—') ?></td>
                <td><?= e($event['project'] ?: '—') ?></td>
                <td><?= e($event['reference'] ?: '—') ?></td>
                <td><?= e($event['material'] ?: '—') ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
</div>
<?php endif; ?>
<?php renderFooter(); ?>
