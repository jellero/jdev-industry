<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$clients = db()->query('SELECT id, company_name FROM clients ORDER BY company_name')->fetchAll();
$allMachines = machines(false);

$preset = (string) ($_GET['preset'] ?? 'month');
$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT) ?: 0;
$machineId = filter_input(INPUT_GET, 'machine_id', FILTER_VALIDATE_INT) ?: 0;
$month = (string) ($_GET['month'] ?? date('Y-m'));
$year = max(2000, min(2100, (int) ($_GET['year'] ?? date('Y'))));
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-t'));

if ($preset === 'month' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
} elseif ($preset === 'year') {
    $from = sprintf('%04d-01-01', $year);
    $to = sprintf('%04d-12-31', $year);
} elseif ($preset !== 'custom') {
    $preset = 'month';
    $from = date('Y-m-01');
    $to = date('Y-m-t');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
    $from = date('Y-m-01');
    $to = date('Y-m-t');
}

$report = EconomicsService::reportJobs($from, $to, $clientId, $machineId);
$totals = $report['totals'];

$query = http_build_query([
    'from' => $from,
    'to' => $to,
    'client_id' => $clientId ?: null,
    'machine_id' => $machineId ?: null,
]);

renderHeader('Report lavorazioni');
renderPageIntro('analizzare le lavorazioni effettivamente registrate dalla macchina in un periodo, con ore, tagli, schemi, scarto e valorizzazione economica.');
?>
<section class="card" style="margin-bottom:16px">
    <form method="get" class="filter-panel" style="margin:0">
        <div class="filter-grid report-filter-grid">
            <div class="form-row"><label>Periodo</label><select name="preset">
                <option value="month" <?= $preset === 'month' ? 'selected' : '' ?>>Mese</option>
                <option value="year" <?= $preset === 'year' ? 'selected' : '' ?>>Anno</option>
                <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Intervallo personalizzato</option>
            </select></div>
            <div class="form-row"><label>Mese</label><input type="month" name="month" value="<?= e($month) ?>"></div>
            <div class="form-row"><label>Anno</label><input type="number" min="2000" max="2100" name="year" value="<?= (int) $year ?>"></div>
            <div class="form-row"><label>Dal</label><input type="date" name="from" value="<?= e($from) ?>"></div>
            <div class="form-row"><label>Al</label><input type="date" name="to" value="<?= e($to) ?>"></div>
            <div class="form-row"><label>Cliente</label><select name="client_id"><option value="">Tutti</option>
                <?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>" <?= $clientId === (int) $client['id'] ? 'selected' : '' ?>><?= e($client['company_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>Macchina</label><select name="machine_id"><option value="">Tutte</option>
                <?php foreach ($allMachines as $machine): ?><option value="<?= (int) $machine['id'] ?>" <?= $machineId === (int) $machine['id'] ? 'selected' : '' ?>><?= e($machine['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="filter-actions">
            <button class="btn" type="submit">Aggiorna report</button>
            <a class="btn secondary" target="_blank" href="print-report.php?<?= e($query) ?>">Stampa report</a>
        </div>
    </form>
</section>

<div class="grid">
    <div class="card col-3"><div class="muted small">Lavorazioni</div><div class="kpi"><?= (int) $totals['jobs'] ?></div></div>
    <div class="card col-3"><div class="muted small">Ore macchina</div><div class="kpi"><?= number_format($totals['hours'], 2, ',', '.') ?></div></div>
    <div class="card col-3"><div class="muted small">Tagli / schemi</div><div class="kpi"><?= (int) $totals['cuts'] ?> / <?= (int) $totals['schemes'] ?></div></div>
    <div class="card col-3"><div class="muted small">Valore lavorazioni</div><div class="kpi"><?= money($totals['total']) ?></div></div>

    <section class="card col-12">
        <div class="section-heading">
            <div><h2>Dettaglio <?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?></h2><p class="muted small">I dati produttivi sono calcolati sugli eventi macchina compresi nel periodo.</p></div>
        </div>
        <?php if (!$report['rows']): ?>
            <div class="empty">Nessuna lavorazione con eventi macchina nel periodo selezionato.</div>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Commessa</th><th>Cliente</th><th>Macchina</th><th>Ore</th><th>Tagli</th><th>Schemi</th><th>Scarto</th><th>Costo</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                    <tr>
                        <td><strong><?= e($row['job']['code']) ?></strong><br><span class="muted"><?= e($row['job']['name']) ?></span></td>
                        <td><?= e($row['job']['company_name'] ?: '—') ?></td>
                        <td><?= e($row['job']['machine_name'] ?: '—') ?></td>
                        <td><?= number_format($row['metrics']['hours'], 2, ',', '.') ?></td>
                        <td><?= (int) $row['metrics']['cut_count'] ?></td>
                        <td><?= (int) $row['metrics']['scheme_count'] ?></td>
                        <td><?= number_format($row['metrics']['waste_total'], 2, ',', '.') ?></td>
                        <td><strong><?= money($row['total']) ?></strong></td>
                        <td><a class="btn secondary small" href="job.php?id=<?= (int) $row['job']['id'] ?>">Apri</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="3">Totale periodo</th><th><?= number_format($totals['hours'], 2, ',', '.') ?></th><th><?= (int) $totals['cuts'] ?></th><th><?= (int) $totals['schemes'] ?></th><th><?= number_format($totals['waste'], 2, ',', '.') ?></th><th><?= money($totals['total']) ?></th><th></th></tr></tfoot>
            </table></div>
        <?php endif; ?>
    </section>
</div>
<?php renderHelp([
    'Mese / Anno' => 'Sono scorciatoie per ottenere subito un consuntivo mensile o annuale. Intervallo personalizzato permette qualsiasi periodo.',
    'Ore macchina' => 'Somma di ElapsedTime degli eventi nel periodo, convertita da secondi a ore.',
    'Tagli' => 'Numero degli eventi CUT_COMPLETED registrati nel periodo.',
    'Schemi' => 'Numero degli eventi BIN_COMPLETED registrati nel periodo.',
    'Scarto' => 'Somma del campo Waste sugli eventi BIN_COMPLETED. L’unità resta quella restituita dalla macchina, perché la specifica non definisce qui una conversione economica automatica.',
    'Valore lavorazioni' => 'Somma delle tariffe automatiche applicabili nel periodo e delle voci manuali con data costo compresa nel periodo.',
    'Cliente' => 'Selezionando un cliente ottieni il report di più lavorazioni dello stesso cliente, già pronto per la stampa.'
], 'Help report'); ?>
<?php renderFooter(); ?>
