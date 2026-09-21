<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$allMachines = machines(false);
$machineId = filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'machine_id', FILTER_VALIDATE_INT)
    ?: 0;
$machine = $machineId ? machineById((int) $machineId) : null;
$selected = (string) ($_POST['endpoint'] ?? 'version');
$date = (string) ($_POST['date'] ?? date('Y-m-d'));
$result = null;

$endpoints = [
    'version' => ['GET', '/version', 'Versione supervisore'],
    'state' => ['GET', '/state', 'Stato corrente'],
    'state_date' => ['GET', '/state/{YYYYMMDD}', 'Stato storico per data'],
    'projects' => ['GET', '/project/last10', 'Ultimi 10 progetti'],
    'log' => ['GET', '/log', 'Log corrente'],
    'log_date' => ['GET', '/logDate/{YYYYMMDD}', 'Log per data'],
    'newlog' => ['GET', '/newlog', 'Nuovi log non ancora letti'],
    'warehouse' => ['GET', '/warehouse', 'Magazzino macchina'],
    'recovery' => ['GET', '/recovery', 'Residui recuperabili'],
    'import_btl' => ['UPLOAD', '/importBtl', 'Importa BTL'],
    'convert_btl' => ['UPLOAD', '/convertBtl', 'Importa BTL con conversione'],
    'import_ts7' => ['UPLOAD', '/importTs7', 'Importa TS7'],
    'import_warehouse' => ['UPLOAD', '/importWarehouse', 'Importa magazzino JSON'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
    if (!$machine) {
        $result = ['ok' => false, 'status' => 0, 'error' => 'Macchina non valida.', 'body' => '', 'duration_ms' => 0, 'url' => ''];
    } elseif (!isset($endpoints[$selected])) {
        $result = ['ok' => false, 'status' => 0, 'error' => 'Endpoint non consentito.', 'body' => '', 'duration_ms' => 0, 'url' => ''];
    } else {
        [$method, $path] = $endpoints[$selected];
        if (str_contains($path, '{YYYYMMDD}')) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d');
            }
            $path = str_replace('{YYYYMMDD}', str_replace('-', '', $date), $path);
        }

        $api = machineApi($machine);
        if ($method === 'GET') {
            $result = $api->get($path);
        } elseif (!isset($_FILES['api_file']) || $_FILES['api_file']['error'] !== UPLOAD_ERR_OK) {
            $result = ['ok' => false, 'status' => 0, 'error' => 'Per questo test devi selezionare un file.', 'body' => '', 'duration_ms' => 0, 'url' => $machine['base_url'] . $path];
        } else {
            $result = $api->upload($path, (string) $_FILES['api_file']['tmp_name'], (string) $_FILES['api_file']['name']);
        }

        if ($selected === 'version' && $result['ok']) {
            db()->prepare('UPDATE machines SET last_version=?, last_seen_at=NOW() WHERE id=?')
                ->execute([trim($result['body']), $machine['id']]);
        }
    }
}

renderHeader('Test API Tecnoessetre');
?>
<div class="grid">
    <section class="card col-5">
        <h2>Esegui richiesta</h2>
        <p class="muted small">Questa pagina è pensata per il collaudo: mostra la risposta grezza così puoi copiarla e usarla per adeguare i parser ai payload reali della macchina.</p>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <div class="form-row full"><label>Macchina</label><select name="machine_id" required>
                <option value="">Seleziona…</option>
                <?php foreach ($allMachines as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int)$machineId === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?> · <?= e($m['base_url']) ?></option><?php endforeach; ?>
            </select></div>

            <div class="form-row full"><label>API</label><select name="endpoint" required>
                <?php foreach ($endpoints as $key => [$method, $path, $label]): ?>
                    <option value="<?= e($key) ?>" <?= $selected === $key ? 'selected' : '' ?>><?= e($method . ' ' . $path . ' · ' . $label) ?></option>
                <?php endforeach; ?>
            </select></div>

            <div class="form-row"><label>Data per endpoint storici</label><input type="date" name="date" value="<?= e($date) ?>"></div>
            <div class="form-row"><label>File per endpoint di upload</label><input type="file" name="api_file" accept=".btl,.ts7,.json"></div>
            <div class="form-row full"><button class="btn" type="submit" name="run" value="1">Esegui test</button></div>
        </form>

        <div class="alert warn" style="margin-top:16px">
            <strong>/newlog:</strong> la specifica lo indica per un unico interlocutore e disponibile dalla versione 7.7.2. Non viene usato dal polling automatico del gestionale.
        </div>
        <p class="muted small">Gli endpoint obsoleti <span class="code-note">/deleteLog</span> e <span class="code-note">/deleteLogDate/{YYYYMMDD}</span> non sono eseguibili da questa pagina per evitare cancellazioni accidentali.</p>
    </section>

    <section class="card col-7">
        <h2>Risposta grezza</h2>
        <?php if (!$result): ?>
            <div class="empty">Esegui una richiesta per vedere HTTP status, tempi e body.</div>
        <?php else: ?>
            <dl class="meta">
                <dt>Esito</dt><dd><span class="badge <?= $result['ok'] ? 'ok' : 'danger' ?>"><?= $result['ok'] ? 'OK' : 'ERRORE' ?></span></dd>
                <dt>HTTP</dt><dd><?= (int) ($result['status'] ?? 0) ?></dd>
                <dt>Tempo</dt><dd><?= (int) ($result['duration_ms'] ?? 0) ?> ms</dd>
                <dt>URL</dt><dd><?= e($result['url'] ?? '') ?></dd>
                <dt>Content-Type</dt><dd><?= e($result['content_type'] ?? '—') ?></dd>
                <dt>Errore cURL</dt><dd><?= e($result['error'] ?? '—') ?></dd>
            </dl>
            <?php
            $raw = $result['body'] ?? '';
            if (isset($result['json']) && $result['json'] !== null) {
                $raw = json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            ?>
            <pre class="raw"><?= e($raw) ?></pre>
        <?php endif; ?>
    </section>
</div>
<?php renderFooter(); ?>
