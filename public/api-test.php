<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$allMachines = machines(false);
$machineId = filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'machine_id', FILTER_VALIDATE_INT)
    ?: 0;
$machine = $machineId ? machineById((int) $machineId) : null;
$mode = (string) ($_POST['mode'] ?? 'suite');
$selected = (string) ($_POST['endpoint'] ?? 'version');
$date = (string) ($_POST['date'] ?? date('Y-m-d'));
$includeNewLog = isset($_POST['include_newlog']);
$includeUploads = isset($_POST['include_uploads']);
$report = null;
$single = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
    if (!$machine) {
        setFlash('Seleziona una macchina valida.', 'error');
    } elseif ($mode === 'single') {
        $fileMap = [
            'import_btl' => $_FILES['btl_file'] ?? null,
            'convert_btl' => $_FILES['btl_file'] ?? null,
            'import_ts7' => $_FILES['ts7_file'] ?? null,
            'import_warehouse' => $_FILES['warehouse_file'] ?? null,
        ];
        $single = ApiTestService::runSingle($machine, $selected, $date, $fileMap[$selected] ?? null);
    } else {
        $report = ApiTestService::runSuite(
            $machine,
            $date,
            $includeNewLog,
            $includeUploads,
            [
                'btl_file' => $_FILES['btl_file'] ?? null,
                'ts7_file' => $_FILES['ts7_file'] ?? null,
                'warehouse_file' => $_FILES['warehouse_file'] ?? null,
            ]
        );
    }
}

$tests = $report['tests'] ?? ($single ? [$single] : []);
$overall = $report['overall'] ?? ($single['status'] ?? null);
$counts = $report['counts'] ?? null;

function testBadgeClass(string $status): string
{
    return match ($status) {
        'pass' => 'ok',
        'warning' => 'warn',
        'fail' => 'danger',
        default => '',
    };
}

function testStatusLabel(string $status): string
{
    return match ($status) {
        'pass' => 'PASS',
        'warning' => 'WARNING',
        'fail' => 'FAIL',
        'skipped' => 'SKIPPED',
        default => strtoupper($status),
    };
}

renderHeader('Test API Tecnoessetre');
?>
<div class="api-test-layout">
    <section class="card api-test-config">
        <div class="section-heading">
            <div>
                <h2>Wizard di collaudo</h2>
                <p class="muted small">Configura una volta i parametri e avvia la suite completa oppure un singolo endpoint.</p>
            </div>
            <span class="badge">3 passaggi</span>
        </div>

        <form method="post" enctype="multipart/form-data" class="test-wizard">
            <div class="wizard-step">
                <div class="wizard-number">1</div>
                <div class="wizard-content">
                    <h3>Macchina</h3>
                    <label for="machine_id">Macchina da verificare</label>
                    <select id="machine_id" name="machine_id" required>
                        <option value="">Seleziona…</option>
                        <?php foreach ($allMachines as $m): ?>
                            <option value="<?= (int) $m['id'] ?>" <?= (int) $machineId === (int) $m['id'] ? 'selected' : '' ?>>
                                <?= e($m['name']) ?> · <?= e($m['base_url']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="wizard-step">
                <div class="wizard-number">2</div>
                <div class="wizard-content">
                    <h3>Tipo di collaudo</h3>
                    <div class="choice-grid">
                        <label class="choice-card">
                            <input type="radio" name="mode" value="suite" <?= $mode !== 'single' ? 'checked' : '' ?>>
                            <span><strong>Suite completa</strong><small>Testa in un colpo solo tutte le API GET sicure.</small></span>
                        </label>
                        <label class="choice-card">
                            <input type="radio" name="mode" value="single" <?= $mode === 'single' ? 'checked' : '' ?>>
                            <span><strong>Test singolo</strong><small>Utile per analizzare un endpoint specifico.</small></span>
                        </label>
                    </div>

                    <div class="form-row" style="margin-top:14px">
                        <label for="endpoint">Endpoint per test singolo</label>
                        <select id="endpoint" name="endpoint">
                            <?php foreach (ApiTestService::ENDPOINTS as $key => [$method, $path, $label]): ?>
                                <option value="<?= e($key) ?>" <?= $selected === $key ? 'selected' : '' ?>>
                                    <?= e($method . ' ' . $path . ' · ' . $label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row" style="margin-top:14px">
                        <label for="test-date">Data per endpoint storici</label>
                        <input id="test-date" type="date" name="date" value="<?= e($date) ?>">
                    </div>
                </div>
            </div>

            <div class="wizard-step">
                <div class="wizard-number">3</div>
                <div class="wizard-content">
                    <h3>Opzioni avanzate</h3>

                    <label class="check-row">
                        <input type="checkbox" name="include_newlog" value="1" <?= $includeNewLog ? 'checked' : '' ?>>
                        <span>
                            <strong>Includi /newlog</strong>
                            <small>Attivalo solo se questo gestionale è l’unico interlocutore che consuma i nuovi log.</small>
                        </span>
                    </label>

                    <label class="check-row">
                        <input type="checkbox" name="include_uploads" value="1" <?= $includeUploads ? 'checked' : '' ?>>
                        <span>
                            <strong>Includi test di upload</strong>
                            <small>I POST vengono eseguiti solo se fornisci i relativi file di collaudo.</small>
                        </span>
                    </label>

                    <div class="upload-grid">
                        <div class="form-row">
                            <label>BTL di test</label>
                            <input type="file" name="btl_file" accept=".btl">
                        </div>
                        <div class="form-row">
                            <label>TS7 di test</label>
                            <input type="file" name="ts7_file" accept=".ts7">
                        </div>
                        <div class="form-row">
                            <label>Magazzino JSON di test</label>
                            <input type="file" name="warehouse_file" accept=".json,application/json">
                        </div>
                    </div>
                </div>
            </div>

            <div class="wizard-actions">
                <button class="btn" type="submit" name="run" value="1">
                    <?= $mode === 'single' ? 'Esegui test selezionato' : 'Esegui tutti i test' ?>
                </button>
                <span class="muted small">Gli endpoint di cancellazione obsoleti non vengono mai eseguiti.</span>
            </div>
        </form>
    </section>

    <section class="card api-test-results">
        <div class="section-heading">
            <div>
                <h2>Risultati</h2>
                <p class="muted small">Esito strutturato con indicazioni operative e payload grezzo espandibile.</p>
            </div>
        </div>

        <?php if (!$tests): ?>
            <div class="empty result-empty">
                <strong>Nessun test eseguito</strong>
                <span>Seleziona la macchina e premi “Esegui tutti i test”.</span>
            </div>
        <?php else: ?>
            <?php if ($report): ?>
                <div class="test-summary <?= e(testBadgeClass($report['overall'])) ?>">
                    <div>
                        <span class="muted small">Esito complessivo</span>
                        <strong><?= e($report['headline']) ?></strong>
                    </div>
                    <div class="summary-counts">
                        <span class="badge ok"><?= (int) $counts['pass'] ?> PASS</span>
                        <span class="badge warn"><?= (int) $counts['warning'] ?> WARNING</span>
                        <span class="badge danger"><?= (int) $counts['fail'] ?> FAIL</span>
                        <span class="badge"><?= (int) $counts['skipped'] ?> SKIPPED</span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="test-results-list">
                <?php foreach ($tests as $test): ?>
                    <article class="test-result">
                        <div class="test-result-head">
                            <div>
                                <div class="status-line">
                                    <span class="badge <?= e(testBadgeClass($test['status'])) ?>"><?= e(testStatusLabel($test['status'])) ?></span>
                                    <strong><?= e($test['label']) ?></strong>
                                </div>
                                <div class="muted small">
                                    <?= e($test['method']) ?> <?= e($test['path']) ?>
                                    <?php if ($test['duration_ms']): ?> · <?= (int) $test['duration_ms'] ?> ms<?php endif; ?>
                                    <?php if ($test['http_status']): ?> · HTTP <?= (int) $test['http_status'] ?><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="result-detail"><?= e($test['detail']) ?></div>
                        <div class="guidance-box">
                            <strong>Indicazione</strong>
                            <span><?= e($test['guidance']) ?></span>
                        </div>

                        <?php if ($test['body'] !== '' || $test['json'] !== null || $test['error'] !== ''): ?>
                            <details class="raw-details">
                                <summary>Dettaglio tecnico / risposta grezza</summary>
                                <dl class="meta compact">
                                    <dt>URL</dt><dd><?= e($test['url'] ?: '—') ?></dd>
                                    <dt>Content-Type</dt><dd><?= e($test['content_type'] ?: '—') ?></dd>
                                    <dt>Errore cURL</dt><dd><?= e($test['error'] ?: '—') ?></dd>
                                </dl>
                                <?php
                                $raw = $test['body'];
                                if ($test['json'] !== null) {
                                    $raw = json_encode($test['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                }
                                ?>
                                <pre class="raw"><?= e($raw) ?></pre>
                            </details>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php renderHelp([
    'Suite completa' => 'Esegue in sequenza le API GET sicure: versione, stato, progetti, log, stato/log storico, magazzino e residui. /newlog e gli upload restano esclusi finché non li abiliti esplicitamente.',
    'Test singolo' => 'Esegue solo l’endpoint selezionato. È utile per analizzare un problema specifico o copiare una singola risposta grezza.',
    'PASS' => 'La richiesta è riuscita e la risposta contiene una struttura interpretabile per quel test.',
    'WARNING' => 'La macchina ha risposto, ma il payload è incompleto, anomalo o non ancora mappato con certezza. Apri il dettaglio tecnico e copia la risposta grezza.',
    'FAIL' => 'La richiesta non è riuscita: per esempio timeout, connessione rifiutata, HTTP di errore o altro problema tecnico.',
    'SKIPPED' => 'Il test non è stato eseguito volontariamente, ad esempio perché manca un file di upload o perché /newlog non è stato abilitato.',
    'Includi /newlog' => 'Usa l’endpoint incrementale dei nuovi log. Abilitalo solo se questo gestionale è l’unico consumatore del flusso.',
    'Test di upload' => 'Per /importBtl, /convertBtl, /importTs7 e /importWarehouse devi fornire i file di collaudo. Senza file il test viene indicato come SKIPPED.'
], 'Help Test API'); ?>
<?php renderFooter(); ?>
