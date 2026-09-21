<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$allMachines = machines(false);
$machineId = filter_input(INPUT_GET, 'machine_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT)
    ?: 0;
$machine = $machineId ? machineById((int) $machineId) : null;
$currentResult = null;
$events = [];
$actionResult = null;

if ($machine && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'sync_log') {
        $actionResult = SyncService::run($machine, 'log');
    } elseif ($action === 'sync_newlog') {
        $actionResult = SyncService::run($machine, 'newlog');
    }
}

if ($machine) {
    $currentResult = machineApi($machine)->get('/log');
    if ($currentResult['ok'] && is_array($currentResult['json'])) {
        $events = normalizeList($currentResult['json']);
    }
    SyncService::recordStatus(
        (int) $machine['id'],
        'log_view',
        $currentResult,
        count($events),
        $currentResult['ok'] ? count($events) . ' eventi nel log corrente.' : ($currentResult['error'] ?: 'Lettura log fallita.')
    );
}

$localEvents = [];
if ($machine) {
    $stmt = db()->prepare(
        'SELECT e.*, j.code AS job_code
         FROM event_logs e
         LEFT JOIN jobs j ON j.id=e.job_id
         WHERE e.machine_id=?
         ORDER BY e.event_time DESC, e.id DESC
         LIMIT 200'
    );
    $stmt->execute([$machine['id']]);
    $localEvents = $stmt->fetchAll();
}

renderHeader('Attività recenti');
?>
<section class="card" style="margin-bottom:16px">
    <form method="get" class="form-grid">
        <div class="form-row"><label>Macchina</label><select name="machine_id" required>
            <option value="">Seleziona…</option>
            <?php foreach ($allMachines as $m): ?>
                <option value="<?= (int) $m['id'] ?>" <?= (int) $machineId === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="form-row actions" style="justify-content:flex-end"><button class="btn" type="submit">Leggi attività</button></div>
    </form>
</section>

<?php if ($machine): ?>
<div class="grid">
    <section class="card col-12">
        <div class="page-title">
            <div>
                <h2>Log corrente macchina</h2>
                <div class="muted small">GET /log · il supervisore rinnova il log con periodicità settimanale.</div>
            </div>
            <div class="actions" style="margin:0">
                <form method="post" class="inline">
                    <input type="hidden" name="machine_id" value="<?= (int) $machine['id'] ?>">
                    <input type="hidden" name="action" value="sync_log">
                    <button class="btn secondary" type="submit">Archivia log corrente</button>
                </form>
                <form method="post" class="inline">
                    <input type="hidden" name="machine_id" value="<?= (int) $machine['id'] ?>">
                    <input type="hidden" name="action" value="sync_newlog">
                    <button class="btn" type="submit">Acquisisci nuovi eventi</button>
                </form>
            </div>
        </div>

        <?php if ($actionResult): ?>
            <div class="alert <?= $actionResult['ok'] ? 'success' : 'error' ?>">
                <?= e($actionResult['message'] ?? ($actionResult['error'] ?? '')) ?>
            </div>
        <?php endif; ?>

        <div class="alert warn">
            <strong>/newlog:</strong> usalo solo se questo gestionale è l'unico interlocutore che consuma i nuovi eventi dalla macchina. La pianificazione automatica è disattivata di default.
        </div>

        <?php if (!$currentResult || !$currentResult['ok']): ?>
            <div class="alert error">Lettura /log non riuscita: <?= e($currentResult['error'] ?? ('HTTP ' . ($currentResult['status'] ?? 0))) ?></div>
        <?php elseif (!$events): ?>
            <div class="empty">Il log corrente non contiene eventi riconosciuti.</div>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Data/ora</th><th>Tipo</th><th>Progetto</th><th>Riferimento</th><th>Materiale</th><th>Tempo</th><th>Scarto</th></tr></thead>
                <tbody>
                <?php foreach ($events as $event): if (!is_array($event)) continue; ?>
                    <tr>
                        <td><?= e(eventDateTime($event['DateTime'] ?? null) ?: ($event['DateTime'] ?? '—')) ?></td>
                        <td><strong><?= e($event['Type'] ?? '—') ?></strong><br><span class="muted"><?= e($event['Message'] ?? '') ?></span></td>
                        <td><?= e($event['Project'] ?? '—') ?></td>
                        <td><?= e($event['Reference'] ?? '—') ?></td>
                        <td><?= e($event['Material'] ?? '—') ?></td>
                        <td><?= isset($event['ElapsedTime']) ? e($event['ElapsedTime']) . ' s' : '—' ?></td>
                        <td><?= e($event['Waste'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="card col-12">
        <h2>Ultimi eventi archiviati</h2>
        <?php if (!$localEvents): ?>
            <div class="empty">Nessun evento archiviato per questa macchina.</div>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Data/ora</th><th>Tipo</th><th>Commessa</th><th>Progetto</th><th>Messaggio</th></tr></thead>
                <tbody>
                <?php foreach ($localEvents as $event): ?>
                    <tr>
                        <td><?= e($event['event_time'] ?: '—') ?></td>
                        <td><?= e($event['event_type'] ?: '—') ?></td>
                        <td><?= e($event['job_code'] ?: '—') ?></td>
                        <td><?= e($event['project'] ?: '—') ?></td>
                        <td><?= e($event['message'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
<?php renderFooter(); ?>
