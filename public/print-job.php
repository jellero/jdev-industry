<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$useSnapshot = filter_input(INPUT_GET, 'snapshot', FILTER_VALIDATE_BOOLEAN);
$company = EconomicsService::company();
$snapshot = $id ? EconomicsService::snapshotForJob($id) : null;

if ($useSnapshot && $snapshot) {
    $calc = json_decode((string) $snapshot['details_json'], true);
    if (!is_array($calc)) {
        $calc = EconomicsService::calculate($id);
    }
    $printNote = 'Costo consolidato il ' . date('d/m/Y H:i', strtotime((string) $snapshot['calculated_at']));
} else {
    $calc = EconomicsService::calculate($id);
    $printNote = 'Costo calcolato al momento della stampa';
}

$job = $calc['job'];
$metrics = $calc['metrics'];

$stmt = db()->prepare('SELECT * FROM event_logs WHERE job_id=? ORDER BY event_time, id');
$stmt->execute([$id]);
$events = $stmt->fetchAll();
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lavorazione <?= e($job['code']) ?></title>
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
                <div><?= e(implode(' · ', array_filter([$company['phone'], $company['email']]))) ?></div>
            </div>
        </div>
        <div class="document-title">
            <strong>Scheda lavorazione</strong>
            <span><?= e($job['code']) ?></span>
        </div>
    </header>

    <section class="print-section">
        <h2>Dati lavorazione</h2>
        <div class="print-grid">
            <div><span>Descrizione</span><strong><?= e($job['name']) ?></strong></div>
            <div><span>Cliente</span><strong><?= e($job['company_name'] ?: '—') ?></strong></div>
            <div><span>Macchina</span><strong><?= e($job['machine_name'] ?: '—') ?></strong></div>
            <div><span>Progetto macchina</span><strong><?= e($job['external_project'] ?: '—') ?></strong></div>
            <div><span>Stato</span><strong><?= e(statusLabel($job['status'])) ?></strong></div>
            <div><span>Periodo produzione</span><strong><?= e($metrics['first_event_at'] ? date('d/m/Y H:i', strtotime($metrics['first_event_at'])) : '—') ?> – <?= e($metrics['last_event_at'] ? date('d/m/Y H:i', strtotime($metrics['last_event_at'])) : '—') ?></strong></div>
        </div>
    </section>

    <section class="print-section">
        <h2>Consuntivo produzione</h2>
        <div class="print-kpis">
            <div><span>Ore macchina</span><strong><?= number_format($metrics['hours'], 2, ',', '.') ?></strong></div>
            <div><span>Tagli completati</span><strong><?= (int) $metrics['cut_count'] ?></strong></div>
            <div><span>Schemi completati</span><strong><?= (int) $metrics['scheme_count'] ?></strong></div>
            <div><span>Scarto</span><strong><?= number_format($metrics['waste_total'], 2, ',', '.') ?></strong></div>
        </div>

        <?php if ($metrics['materials']): ?>
            <h3>Materiali rilevati dai log</h3>
            <table><thead><tr><th>Materiale</th><th>Eventi</th><th>Valore lavorato</th><th>Scarto</th></tr></thead><tbody>
            <?php foreach ($metrics['materials'] as $material): ?><tr>
                <td><?= e($material['material']) ?></td>
                <td><?= (int) $material['events'] ?></td>
                <td><?= e($material['worked_value']) ?></td>
                <td><?= e($material['waste_value']) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </section>

    <section class="print-section">
        <h2>Valorizzazione economica</h2>
        <p class="print-note"><?= e($printNote) ?>.</p>
        <table>
            <thead><tr><th>Voce</th><th>Quantità</th><th>Prezzo unitario</th><th>Importo</th></tr></thead>
            <tbody>
            <?php foreach ($calc['automatic_items'] as $item): ?><tr>
                <td><?= e($item['name']) ?><br><small><?= e($item['basis_label']) ?><?= $item['material_match'] ? ' · ' . e($item['material_match']) : '' ?></small></td>
                <td><?= number_format((float) $item['quantity'], 3, ',', '.') ?> <?= e($item['unit']) ?></td>
                <td><?= money($item['unit_price']) ?></td>
                <td><?= money($item['amount']) ?></td>
            </tr><?php endforeach; ?>
            <?php foreach ($calc['manual_items'] as $item): ?><tr>
                <td><?= e($item['description']) ?><br><small><?= e(ucfirst($item['category'])) ?> · <?= e(date('d/m/Y', strtotime($item['cost_date']))) ?></small></td>
                <td><?= number_format((float) $item['quantity'], 3, ',', '.') ?> <?= e($item['unit']) ?></td>
                <td><?= money($item['unit_price']) ?></td>
                <td><?= money($item['amount']) ?></td>
            </tr><?php endforeach; ?>
            <?php if (!$calc['automatic_items'] && !$calc['manual_items']): ?><tr><td colspan="4">Nessuna voce economica configurata.</td></tr><?php endif; ?>
            </tbody>
            <tfoot>
                <tr><th colspan="3">Automatico</th><th><?= money($calc['automatic_total']) ?></th></tr>
                <tr><th colspan="3">Materiali / extra / sconti</th><th><?= money($calc['manual_total']) ?></th></tr>
                <tr class="grand-total"><th colspan="3">Totale lavorazione</th><th><?= money($calc['total']) ?></th></tr>
            </tfoot>
        </table>
    </section>

    <section class="print-section">
        <h2>Dettaglio eventi macchina</h2>
        <?php if (!$events): ?><p>Nessun evento macchina archiviato.</p>
        <?php else: ?><table class="compact-table">
            <thead><tr><th>Data/ora</th><th>Tipo</th><th>Riferimento</th><th>Materiale</th><th>Tempo</th><th>Scarto</th></tr></thead>
            <tbody><?php foreach ($events as $event): ?><tr>
                <td><?= e($event['event_time'] ? date('d/m/Y H:i:s', strtotime($event['event_time'])) : '—') ?></td>
                <td><?= e($event['event_type'] ?: '—') ?></td>
                <td><?= e($event['reference'] ?: '—') ?></td>
                <td><?= e($event['material'] ?: '—') ?></td>
                <td><?= $event['elapsed_time'] !== null ? e($event['elapsed_time']) . ' s' : '—' ?></td>
                <td><?= e($event['waste'] ?? '—') ?></td>
            </tr><?php endforeach; ?></tbody>
        </table><?php endif; ?>
    </section>

    <?php if ($job['notes']): ?><section class="print-section"><h2>Note commessa</h2><p><?= nl2br(e($job['notes'])) ?></p></section><?php endif; ?>

    <footer class="print-footer">
        <span>Stampato il <?= e(date('d/m/Y H:i')) ?></span>
        <?php if ($company['print_footer']): ?><span><?= e($company['print_footer']) ?></span><?php endif; ?>
    </footer>
</main>
</body>
</html>
