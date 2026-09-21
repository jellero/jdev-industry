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
$stateResult = null;
$events = [];

if ($machine) {
    $api = machineApi($machine);
    $compactDate = str_replace('-', '', $date);

    $liveResult = $api->get('/logDate/' . $compactDate);
    if ($liveResult['ok'] && is_array($liveResult['json'])) {
        $events = normalizeList($liveResult['json']);
    }
    SyncService::recordStatus(
        (int) $machine['id'],
        'log_date',
        $liveResult,
        count($events),
        $liveResult['ok'] ? count($events) . ' eventi letti per ' . $date . '.' : ($liveResult['error'] ?: 'Lettura storico log fallita.')
    );

    $stateResult = $api->get('/state/' . $compactDate);
    SyncService::recordStatus(
        (int) $machine['id'],
        'state_history',
        $stateResult,
        0,
        $stateResult['ok'] ? 'Stato storico letto per ' . $date . '.' : ($stateResult['error'] ?: 'Stato storico non disponibile.')
    );
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

    $archive = SyncService::archiveEvents($machine, $events, $date);
    setFlash(sprintf(
        'Storico aggiornato: %d nuovi eventi archiviati su %d letti.',
        $archive['inserted'],
        $archive['read']
    ));
    redirect('history.php?machine_id=' . $machineId . '&date=' . urlencode($date));
}

$localEvents = [];
if ($machine) {
    $stmt = db()->prepare(
        'SELECT e.*, j.code AS job_code
         FROM event_logs e
         LEFT JOIN jobs j ON j.id=e.job_id
         WHERE e.machine_id=? AND e.source_day=?
         ORDER BY e.event_time DESC, e.id DESC
         LIMIT 500'
    );
    $stmt->execute([$machine['id'], $date]);
    $localEvents = $stmt->fetchAll();
}

$stateJson = $stateResult && is_array($stateResult['json']) ? $stateResult['json'] : [];

renderHeader('Storico lavori');
?>
<section class="card" style="margin-bottom:16px">
    <form method="get" class="form-grid">
        <div class="form-row"><label>Macchina</label><select name="machine_id" required>
            <option value="">Seleziona…</option>
            <?php foreach ($allMachines as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= (int) $machineId === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Giorno</label><input type="date" name="date" value="<?= e($date) ?>" required></div>
        <div class="form-row full actions"><button class="btn" type="submit">Leggi dalla macchina</button></div>
    </form>
</section>

<?php if ($machine): ?>
<div class="grid">
    <section class="card col-12">
        <h2>Stato macchina del <?= e($date) ?> <span class="code-note">GET /state/<?= e(str_replace('-', '', $date)) ?></span></h2>
        <?php if (!$stateResult || !$stateResult['ok']): ?>
            <div class="alert warn">
                Stato storico non disponibile su questa richiesta/versione:
                <?= e($stateResult['error'] ?? ('HTTP ' . ($stateResult['status'] ?? 0))) ?>
            </div>
            <?php if ($stateResult && $stateResult['body'] !== ''): ?><pre class="raw"><?= e($stateResult['body']) ?></pre><?php endif; ?>
        <?php else: ?>
            <dl class="meta">
                <dt>Connessione</dt><dd><?= e($stateJson['Conneted'] ?? $stateJson['Connected'] ?? '—') ?></dd>
                <dt>Modalità</dt><dd><?= e($stateJson['Mode'] ?? '—') ?></dd>
                <dt>Commenti</dt><dd><?= e($stateJson['Comments'] ?? '—') ?></dd>
                <dt>Avvisi</dt><dd><?= e($stateJson['Warnings'] ?? '—') ?></dd>
                <dt>Errori</dt><dd><?= e($stateJson['Errors'] ?? '—') ?></dd>
            </dl>
            <details style="margin-top:14px">
                <summary>Payload completo dello stato storico</summary>
                <pre class="raw" style="margin-top:10px"><?= e(json_encode($stateResult['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            </details>
        <?php endif; ?>
    </section>

    <section class="card col-12">
        <div class="page-title">
            <div><h2>Log produzione · <?= e($date) ?></h2><div class="muted small">GET /logDate/<?= e(str_replace('-', '', $date)) ?></div></div>
            <?php if ($liveResult && $liveResult['ok'] && $events): ?>
                <form method="post">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="machine_id" value="<?= (int) $machine['id'] ?>">
                    <input type="hidden" name="date" value="<?= e($date) ?>">
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
<?php renderHelp([
    'Stato macchina del giorno' => 'Legge /state/{data} per mostrare lo stato storico disponibile sul supervisore per la giornata selezionata.',
    'Log produzione del giorno' => 'Legge /logDate/{data} e mostra gli eventi produttivi registrati dalla macchina nella data scelta.',
    'Archivia nel gestionale' => 'Copia gli eventi del giorno nel database locale. Gli eventi già archiviati non vengono duplicati.',
    'Archivio locale' => 'È lo storico già memorizzato dal gestionale. Può includere il collegamento alla commessa quando il campo Project della macchina corrisponde al riferimento della commessa.',
    'Scarto' => 'È il valore Waste restituito dall’evento macchina, se presente. Il significato e l’unità esatti dipendono dal tracciato restituito dalla versione installata.'
], 'Help storico lavori'); ?>
<?php renderFooter(); ?>
