<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-t'));
$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT) ?: 0;
$machineId = filter_input(INPUT_GET, 'machine_id', FILTER_VALIDATE_INT) ?: 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
    http_response_code(400);
    exit('Periodo non valido.');
}

$company = EconomicsService::company();
$report = EconomicsService::reportJobs($from, $to, $clientId, $machineId);
$totals = $report['totals'];

$clientName = null;
if ($clientId) {
    $stmt = db()->prepare('SELECT company_name FROM clients WHERE id=?');
    $stmt->execute([$clientId]);
    $clientName = $stmt->fetchColumn() ?: null;
}
$machineName = null;
if ($machineId) {
    $machine = machineById($machineId);
    $machineName = $machine['name'] ?? null;
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report lavorazioni <?= e($from) ?> <?= e($to) ?></title>
    <link rel="stylesheet" href="assets/print.css">
</head>
<body>
<div class="print-toolbar no-print">
    <button onclick="window.print()">Stampa / Salva PDF</button>
    <button onclick="window.close()">Chiudi</button>
</div>

<main class="print-page">
    <header class="print-header">
        <div class="company-block">
            <?php if ($company['logo_path']): ?><img class="print-logo" src="<?= e($company['logo_path']) ?>" alt="Logo"><?php endif; ?>
            <div>
                <h1><?= e($company['company_name']) ?></h1>
                <?php if ($company['address']): ?><div><?= e($company['address']) ?></div><?php endif; ?>
                <?php if ($company['vat_number']): ?><div>P.IVA / C.F.: <?= e($company['vat_number']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="document-title"><strong>Report lavorazioni</strong><span><?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?></span></div>
    </header>

    <section class="print-section">
        <h2>Filtri</h2>
        <div class="print-grid">
            <div><span>Cliente</span><strong><?= e($clientName ?: 'Tutti') ?></strong></div>
            <div><span>Macchina</span><strong><?= e($machineName ?: 'Tutte') ?></strong></div>
        </div>
    </section>

    <section class="print-section">
        <h2>Statistiche periodo</h2>
        <div class="print-kpis">
            <div><span>Lavorazioni</span><strong><?= (int) $totals['jobs'] ?></strong></div>
            <div><span>Ore macchina</span><strong><?= number_format($totals['hours'], 2, ',', '.') ?></strong></div>
            <div><span>Tagli</span><strong><?= (int) $totals['cuts'] ?></strong></div>
            <div><span>Schemi</span><strong><?= (int) $totals['schemes'] ?></strong></div>
            <div><span>Scarto</span><strong><?= number_format($totals['waste'], 2, ',', '.') ?></strong></div>
            <div><span>Valore</span><strong><?= money($totals['total']) ?></strong></div>
        </div>
    </section>

    <section class="print-section">
        <h2>Lavorazioni</h2>
        <?php if (!$report['rows']): ?><p>Nessuna lavorazione nel periodo.</p>
        <?php else: ?><table>
            <thead><tr><th>Commessa</th><th>Cliente</th><th>Macchina</th><th>Ore</th><th>Tagli</th><th>Schemi</th><th>Scarto</th><th>Importo</th></tr></thead>
            <tbody>
            <?php foreach ($report['rows'] as $row): ?><tr>
                <td><strong><?= e($row['job']['code']) ?></strong><br><small><?= e($row['job']['name']) ?></small></td>
                <td><?= e($row['job']['company_name'] ?: '—') ?></td>
                <td><?= e($row['job']['machine_name'] ?: '—') ?></td>
                <td><?= number_format($row['metrics']['hours'], 2, ',', '.') ?></td>
                <td><?= (int) $row['metrics']['cut_count'] ?></td>
                <td><?= (int) $row['metrics']['scheme_count'] ?></td>
                <td><?= number_format($row['metrics']['waste_total'], 2, ',', '.') ?></td>
                <td><?= money($row['total']) ?></td>
            </tr><?php endforeach; ?>
            </tbody>
            <tfoot><tr class="grand-total"><th colspan="3">Totale</th><th><?= number_format($totals['hours'], 2, ',', '.') ?></th><th><?= (int) $totals['cuts'] ?></th><th><?= (int) $totals['schemes'] ?></th><th><?= number_format($totals['waste'], 2, ',', '.') ?></th><th><?= money($totals['total']) ?></th></tr></tfoot>
        </table><?php endif; ?>
    </section>

    <footer class="print-footer">
        <span>Stampato il <?= e(date('d/m/Y H:i')) ?></span>
        <?php if ($company['print_footer']): ?><span><?= e($company['print_footer']) ?></span><?php endif; ?>
    </footer>
</main>
</body>
</html>
