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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_file') {
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

$eventsStmt = db()->prepare('SELECT * FROM event_logs WHERE job_id=? ORDER BY event_time DESC, id DESC LIMIT 50');
$eventsStmt->execute([$job['id']]);
$events = $eventsStmt->fetchAll();

renderHeader('Commessa · ' . $job['code']);
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
        <div class="actions"><a class="btn secondary" href="jobs.php?edit=<?= (int) $job['id'] ?>">Modifica</a></div>
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
    'BTL · importa senza conversione' => 'Invia il file BTL all’endpoint /importBtl. Il gestionale inoltra il file così com’è al supervisore, senza richiedere la conversione prevista dall’altro endpoint.',
    'BTL · importa con conversione' => 'Invia il BTL all’endpoint /convertBtl. In questo caso è il supervisore Tecnoessetre a eseguire la funzione di conversione prevista dalla sua API durante l’importazione.',
    'TS7 · importa' => 'Invia un file TS7 all’endpoint /importTs7. Va usato solo quando il file di produzione è effettivamente in formato TS7.',
    'Progetto macchina' => 'È il riferimento usato per collegare la commessa ai dati restituiti dalla macchina, in particolare al campo Project dei log. Se il file viene inviato dal gestionale e il campo è vuoto, viene usato il nome del file senza estensione.',
    'Stato commessa' => 'Pianificata = creata ma non ancora inviata; Pronta = pronta per essere lavorata; Inviata = file spedito alla macchina; In lavorazione = sono stati rilevati eventi produttivi collegati; Completata e Annullata sono stati gestionali.',
    'Eventi archiviati collegati' => 'Sono eventi macchina già salvati nel database locale e associati a questa commessa tramite il riferimento progetto.'
], 'Help commessa'); ?>
<?php renderFooter(); ?>
