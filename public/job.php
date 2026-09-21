<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$stmt = db()->prepare(
    "SELECT j.*, c.company_name, m.name AS machine_name, m.base_url
     FROM jobs j
     LEFT JOIN clients c ON c.id=j.client_id
     LEFT JOIN machines m ON m.id=j.machine_id
     WHERE j.id=?"
);
$stmt->execute([$id]);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    renderHeader('Commessa non trovata');
    echo '<div class="card">La commessa richiesta non esiste.</div>';
    renderFooter();
    exit;
}

$uploadResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_cost') {
        $category = (string) ($_POST['category'] ?? 'material');
        $description = trim((string) ($_POST['description'] ?? ''));
        $quantity = str_replace(',', '.', trim((string) ($_POST['quantity'] ?? '1')));
        $unitPrice = str_replace(',', '.', trim((string) ($_POST['unit_price'] ?? '0')));
        $unit = trim((string) ($_POST['unit'] ?? 'pz'));
        $costDate = (string) ($_POST['cost_date'] ?? date('Y-m-d'));
        $allowedCategories = ['material','extra','discount'];

        if (
            !in_array($category, $allowedCategories, true)
            || $description === ''
            || !is_numeric($quantity)
            || (float) $quantity <= 0
            || !is_numeric($unitPrice)
            || (float) $unitPrice < 0
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $costDate)
        ) {
            setFlash('Compila correttamente la voce di costo.', 'error');
        } else {
            db()->prepare(
                'INSERT INTO job_cost_items
                 (job_id, category, description, quantity, unit, unit_price, cost_date, notes)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $job['id'],
                $category,
                $description,
                (float) $quantity,
                $unit !== '' ? $unit : 'pz',
                (float) $unitPrice,
                $costDate,
                trim((string) ($_POST['cost_notes'] ?? '')) ?: null,
            ]);
            setFlash('Voce economica aggiunta.');
        }
        redirect('job.php?id=' . (int) $job['id'] . '#economics');
    }

    if ($action === 'delete_cost') {
        $costId = filter_input(INPUT_POST, 'cost_id', FILTER_VALIDATE_INT);
        if ($costId) {
            db()->prepare('DELETE FROM job_cost_items WHERE id=? AND job_id=?')->execute([$costId, $job['id']]);
            setFlash('Voce economica eliminata.');
        }
        redirect('job.php?id=' . (int) $job['id'] . '#economics');
    }

    if ($action === 'snapshot_cost') {
        EconomicsService::snapshot((int) $job['id']);
        setFlash('Costo consolidato: è stata salvata una fotografia del calcolo attuale.');
        redirect('job.php?id=' . (int) $job['id'] . '#economics');
    }

    if ($action === 'send_file') {
        $machine = $job['machine_id'] ? machineById((int) $job['machine_id']) : null;
        $mode = (string) ($_POST['mode'] ?? '');
        $endpointMap = [
            'btl' => '/importBtl',
            'btl_convert' => '/convertBtl',
            'ts7' => '/importTs7',
        ];

        if (!$machine) {
            $uploadResult = ['ok' => false, 'error' => 'Assegna prima una macchina alla commessa.'];
        } elseif (!isset($endpointMap[$mode])) {
            $uploadResult = ['ok' => false, 'error' => 'Tipo di invio non valido.'];
        } elseif (!isset($_FILES['production_file']) || $_FILES['production_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadResult = ['ok' => false, 'error' => 'Seleziona un file BTL o TS7 valido.'];
        } else {
            $name = (string) $_FILES['production_file']['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $expected = $mode === 'ts7' ? 'ts7' : 'btl';
            if ($ext !== $expected) {
                $uploadResult = ['ok' => false, 'error' => 'Estensione file non coerente con il tipo di invio selezionato.'];
            } elseif ((int) $_FILES['production_file']['size'] > 25 * 1024 * 1024) {
                $uploadResult = ['ok' => false, 'error' => 'File troppo grande (massimo applicativo: 25 MB).'];
            } else {
                $uploadResult = machineApi($machine)->upload(
                    $endpointMap[$mode],
                    (string) $_FILES['production_file']['tmp_name'],
                    $name
                );
                if ($uploadResult['ok']) {
                    db()->prepare("UPDATE jobs SET status='sent', external_project=COALESCE(NULLIF(external_project,''), ?) WHERE id=?")
                        ->execute([pathinfo($name, PATHINFO_FILENAME), $job['id']]);
                    $job['status'] = 'sent';
                    if (!$job['external_project']) {
                        $job['external_project'] = pathinfo($name, PATHINFO_FILENAME);
                    }
                }
            }
        }
    }
}

$eventsStmt = db()->prepare('SELECT * FROM event_logs WHERE job_id=? ORDER BY event_time DESC, id DESC LIMIT 50');
$eventsStmt->execute([$job['id']]);
$events = $eventsStmt->fetchAll();

$cost = EconomicsService::calculate((int) $job['id']);
$snapshot = EconomicsService::snapshotForJob((int) $job['id']);

renderHeader('Commessa · ' . $job['code']);
renderPageIntro('gestire una singola lavorazione dall’invio alla macchina fino al consuntivo tecnico ed economico, con stampa finale.');
?>
<div class="grid">
    <section class="card col-6">
        <h2>Dati commessa</h2>
        <dl class="meta">
            <dt>Codice</dt><dd><?= e($job['code']) ?></dd>
            <dt>Descrizione</dt><dd><?= e($job['name']) ?></dd>
            <dt>Cliente</dt><dd><?= e($job['company_name'] ?: '—') ?></dd>
            <dt>Macchina</dt><dd><?= e($job['machine_name'] ?: '—') ?></dd>
            <dt>Progetto macchina</dt><dd><?= e($job['external_project'] ?: '—') ?></dd>
            <dt>Stato</dt><dd><span class="badge"><?= e(statusLabel($job['status'])) ?></span></dd>
        </dl>
        <div class="actions">
            <a class="btn secondary" href="jobs.php?edit=<?= (int) $job['id'] ?>">Modifica</a>
            <a class="btn" target="_blank" href="print-job.php?id=<?= (int) $job['id'] ?>">Stampa lavorazione</a>
            <?php if ($snapshot): ?><a class="btn secondary" target="_blank" href="print-job.php?id=<?= (int) $job['id'] ?>&snapshot=1">Stampa costo consolidato</a><?php endif; ?>
            <?php if ($job['client_id']): ?><a class="btn secondary" href="reports.php?client_id=<?= (int) $job['client_id'] ?>&preset=year">Report cliente</a><?php endif; ?>
        </div>
    </section>

    <section class="card col-6">
        <h2>Invia lavoro alla macchina</h2>
        <p class="muted small">Il file viene inoltrato direttamente al supervisore Tecnoessetre e non viene conservato dal gestionale.</p>
        <?php if ($uploadResult): ?>
            <div class="alert <?= $uploadResult['ok'] ? 'success' : 'error' ?>">
                <?= $uploadResult['ok'] ? 'Invio completato.' : e($uploadResult['error'] ?: ('HTTP ' . ($uploadResult['status'] ?? 0))) ?>
            </div>
            <?php if (isset($uploadResult['body'])): ?><pre class="raw"><?= e($uploadResult['body']) ?></pre><?php endif; ?>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="id" value="<?= (int) $job['id'] ?>"><input type="hidden" name="action" value="send_file">
            <div class="form-row full"><label>Modalità</label><select name="mode" required>
                <option value="btl">BTL · importa senza conversione</option>
                <option value="btl_convert">BTL · importa con conversione</option>
                <option value="ts7">TS7 · importa</option>
            </select></div>
            <div class="form-row full"><label>File produzione</label><input type="file" name="production_file" accept=".btl,.ts7" required></div>
            <div class="form-row full"><button class="btn" type="submit">Invia alla macchina</button></div>
        </form>
    </section>

    <section class="card col-12" id="economics">
        <div class="section-heading">
            <div>
                <h2>Consuntivo economico</h2>
                <p class="muted small">Il costo automatico viene calcolato dal tariffario sui dati archiviati della macchina. Materiali e costi non deducibili con certezza dalle API si aggiungono manualmente.</p>
            </div>
            <div class="economic-total"><?= money($cost['total']) ?></div>
        </div>

        <div class="grid economic-kpis">
            <div class="metric-box col-3"><span>Ore macchina</span><strong><?= number_format($cost['metrics']['hours'], 2, ',', '.') ?></strong></div>
            <div class="metric-box col-3"><span>Tagli completati</span><strong><?= (int) $cost['metrics']['cut_count'] ?></strong></div>
            <div class="metric-box col-3"><span>Schemi completati</span><strong><?= (int) $cost['metrics']['scheme_count'] ?></strong></div>
            <div class="metric-box col-3"><span>Scarto</span><strong><?= number_format($cost['metrics']['waste_total'], 2, ',', '.') ?></strong></div>
        </div>

        <div class="split" style="margin-top:18px">
            <div>
                <h3>Calcolo automatico</h3>
                <?php if (!$cost['automatic_items']): ?>
                    <div class="empty compact-empty">Nessuna tariffa applicabile. Configura il <a href="economics.php">Tariffario</a>.</div>
                <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Voce</th><th>Quantità</th><th>Prezzo</th><th>Importo</th></tr></thead>
                        <tbody><?php foreach ($cost['automatic_items'] as $item): ?><tr>
                            <td><?= e($item['name']) ?><br><span class="muted small"><?= e($item['basis_label']) ?></span></td>
                            <td><?= number_format($item['quantity'], 3, ',', '.') ?> <?= e($item['unit']) ?></td>
                            <td><?= money($item['unit_price']) ?></td>
                            <td><?= money($item['amount']) ?></td>
                        </tr><?php endforeach; ?></tbody>
                        <tfoot><tr><th colspan="3">Totale automatico</th><th><?= money($cost['automatic_total']) ?></th></tr></tfoot>
                    </table></div>
                <?php endif; ?>
            </div>

            <div>
                <h3>Materiali, extra e sconti</h3>
                <?php if (!$cost['manual_items']): ?>
                    <div class="empty compact-empty">Nessuna voce manuale.</div>
                <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Voce</th><th>Q.tà</th><th>Importo</th><th></th></tr></thead>
                        <tbody><?php foreach ($cost['manual_items'] as $item): ?><tr>
                            <td><strong><?= e($item['description']) ?></strong><br><span class="muted small"><?= e(ucfirst($item['category'])) ?> · <?= e(date('d/m/Y', strtotime($item['cost_date']))) ?></span></td>
                            <td><?= number_format((float) $item['quantity'], 3, ',', '.') ?> <?= e($item['unit']) ?></td>
                            <td><?= money($item['amount']) ?></td>
                            <td><form method="post" class="inline"><input type="hidden" name="id" value="<?= (int) $job['id'] ?>"><input type="hidden" name="action" value="delete_cost"><input type="hidden" name="cost_id" value="<?= (int) $item['id'] ?>"><button type="submit" class="btn secondary small">Elimina</button></form></td>
                        </tr><?php endforeach; ?></tbody>
                        <tfoot><tr><th colspan="2">Totale manuale</th><th><?= money($cost['manual_total']) ?></th><th></th></tr></tfoot>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>

        <form method="post" class="form-grid cost-entry">
            <input type="hidden" name="id" value="<?= (int) $job['id'] ?>"><input type="hidden" name="action" value="add_cost">
            <div class="form-row"><label>Tipo voce</label><select name="category">
                <option value="material">Materiale</option>
                <option value="extra">Costo extra</option>
                <option value="discount">Sconto</option>
            </select></div>
            <div class="form-row"><label>Data costo</label><input type="date" name="cost_date" value="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="form-row full"><label>Descrizione *</label><input name="description" required placeholder="Es. Trave lamellare GL24, utensile speciale, trasporto…"></div>
            <div class="form-row"><label>Quantità *</label><input type="number" min="0.0001" step="0.0001" name="quantity" value="1" required></div>
            <div class="form-row"><label>Unità</label><input name="unit" value="pz" placeholder="pz, m, m², m³, kg…"></div>
            <div class="form-row"><label>Prezzo unitario (€) *</label><input type="number" min="0" step="0.01" name="unit_price" value="0" required></div>
            <div class="form-row"><label>Note</label><input name="cost_notes"></div>
            <div class="form-row full actions"><button class="btn" type="submit">Aggiungi voce</button></div>
        </form>

        <div class="cost-total-bar">
            <div><span>Automatico</span><strong><?= money($cost['automatic_total']) ?></strong></div>
            <div><span>Manuale</span><strong><?= money($cost['manual_total']) ?></strong></div>
            <div class="grand"><span>Totale lavorazione</span><strong><?= money($cost['total']) ?></strong></div>
        </div>

        <div class="actions">
            <form method="post" class="inline"><input type="hidden" name="id" value="<?= (int) $job['id'] ?>"><input type="hidden" name="action" value="snapshot_cost"><button class="btn secondary" type="submit">Consolida costo attuale</button></form>
            <?php if ($snapshot): ?><span class="muted small">Ultimo consolidamento: <?= e(date('d/m/Y H:i', strtotime($snapshot['calculated_at']))) ?> · <?= money($snapshot['total']) ?></span><?php endif; ?>
        </div>
    </section>

    <section class="card col-12">
        <h2>Eventi archiviati collegati</h2>
        <?php if (!$events): ?><div class="empty">Nessun evento locale collegato. Importa una giornata dallo Storico lavori per popolare questa sezione.</div>
        <?php else: ?><div class="table-wrap"><table>
            <thead><tr><th>Data/ora</th><th>Tipo</th><th>Messaggio</th><th>Riferimento</th><th>Materiale</th><th>Tempo</th></tr></thead>
            <tbody><?php foreach ($events as $event): ?><tr>
                <td><?= e($event['event_time'] ?: '—') ?></td>
                <td><?= e($event['event_type'] ?: '—') ?></td>
                <td><?= e($event['message'] ?: '—') ?></td>
                <td><?= e($event['reference'] ?: '—') ?></td>
                <td><?= e($event['material'] ?: '—') ?></td>
                <td><?= $event['elapsed_time'] !== null ? e($event['elapsed_time']) . ' s' : '—' ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
</div>
<?php renderHelp([
    'BTL · importa senza conversione' => 'Invia il file BTL all’endpoint /importBtl. Il gestionale inoltra il file così com’è al supervisore.',
    'BTL · importa con conversione' => 'Invia il BTL a /convertBtl: la conversione prevista viene eseguita dal supervisore Tecnoessetre secondo la configurazione macchina.',
    'TS7 · importa' => 'Invia un file TS7 all’endpoint /importTs7.',
    'Costo automatico' => 'Usa solo grandezze documentate dai log: ElapsedTime per le ore, CUT_COMPLETED per i tagli e BIN_COMPLETED per gli schemi, più eventuali quote fisse definite nel tariffario.',
    'Materiali / extra' => 'Servono per valorizzare ciò che le API non possono prezzare da sole. Il campo Material identifica il materiale, ma il log non contiene il suo prezzo di acquisto.',
    'Sconto' => 'Inserisci quantità e prezzo come valore positivo: il gestionale lo sottrae automaticamente dal totale.',
    'Consolida costo' => 'Salva una fotografia dell’attuale calcolo economico. È utile prima di modificare le tariffe o prima di consegnare/stampare un consuntivo definitivo.',
    'Stampa lavorazione' => 'Genera una scheda stampabile con intestazione aziendale, dati commessa, ore, tagli, schemi, materiali rilevati, costi ed eventi macchina.'
], 'Help commessa'); ?>
<?php renderFooter(); ?>
