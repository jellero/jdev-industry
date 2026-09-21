<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$allMachines = machines(false);
$machineId = filter_input(INPUT_GET, 'machine_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT)
    ?: 0;
$machine = $machineId ? machineById((int) $machineId) : null;
$warehouse = null;
$recovery = null;
$uploadResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    if (!$machine) {
        setFlash('Seleziona una macchina valida.', 'error');
        redirect('warehouse.php');
    } elseif (!isset($_FILES['warehouse_file']) || $_FILES['warehouse_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadResult = ['ok' => false, 'error' => 'Seleziona un file JSON valido.'];
    } else {
        $name = (string) $_FILES['warehouse_file']['name'];
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
            $uploadResult = ['ok' => false, 'error' => 'Il magazzino deve essere un file .json.'];
        } elseif ((int) $_FILES['warehouse_file']['size'] > 10 * 1024 * 1024) {
            $uploadResult = ['ok' => false, 'error' => 'File troppo grande (massimo applicativo: 10 MB).'];
        } else {
            $uploadResult = machineApi($machine)->upload('/importWarehouse', (string) $_FILES['warehouse_file']['tmp_name'], $name);
        }
    }
}

if ($machine) {
    $api = machineApi($machine);
    $warehouse = $api->get('/warehouse');
    $recovery = $api->get('/recovery');
}

function renderGenericPayload(?array $result): void
{
    if (!$result) {
        echo '<div class="empty">Seleziona una macchina.</div>';
        return;
    }
    if (!$result['ok']) {
        echo '<div class="alert error">Errore: ' . e($result['error'] ?: ('HTTP ' . $result['status'])) . '</div>';
        echo '<pre class="raw">' . e($result['body']) . '</pre>';
        return;
    }
    $items = normalizeList($result['json']);
    if (!$items || !is_array($items[0] ?? null)) {
        echo '<pre class="raw">' . e($result['body']) . '</pre>';
        return;
    }

    $columns = array_slice(array_keys($items[0]), 0, 12);
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($columns as $column) {
        echo '<th>' . e($column) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        echo '<tr>';
        foreach ($columns as $column) {
            $value = $item[$column] ?? null;
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            echo '<td>' . e($value ?? '—') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

renderHeader('Magazzino macchina');
?>
<section class="card" style="margin-bottom:16px">
    <form method="get" class="form-grid">
        <div class="form-row"><label>Macchina</label><select name="machine_id" required>
            <option value="">Seleziona…</option>
            <?php foreach ($allMachines as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int)$machineId === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row actions" style="justify-content:flex-end"><button class="btn" type="submit">Leggi magazzino</button></div>
    </form>
</section>

<?php if ($machine): ?>
<div class="grid">
    <section class="card col-12">
        <h2>Barre / materie prime <span class="code-note">GET /warehouse</span></h2>
        <?php renderGenericPayload($warehouse); ?>
    </section>
    <section class="card col-12">
        <h2>Residui recuperabili <span class="code-note">GET /recovery</span></h2>
        <?php renderGenericPayload($recovery); ?>
    </section>
    <section class="card col-12">
        <h2>Importa archivio magazzino</h2>
        <p class="muted small">Invia un file JSON tramite <span class="code-note">POST /importWarehouse</span>. Usa prima un file di collaudo e verifica il tracciato effettivo della versione installata.</p>
        <?php if ($uploadResult): ?>
            <div class="alert <?= $uploadResult['ok'] ? 'success' : 'error' ?>"><?= $uploadResult['ok'] ? 'Importazione richiesta correttamente.' : e($uploadResult['error'] ?: ('HTTP ' . ($uploadResult['status'] ?? 0))) ?></div>
            <?php if (isset($uploadResult['body'])): ?><pre class="raw"><?= e($uploadResult['body']) ?></pre><?php endif; ?>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="action" value="import"><input type="hidden" name="machine_id" value="<?= (int) $machine['id'] ?>">
            <div class="form-row"><label>File JSON</label><input type="file" name="warehouse_file" accept=".json,application/json" required></div>
            <div class="form-row actions" style="justify-content:flex-end"><button class="btn" type="submit">Invia magazzino</button></div>
        </form>
    </section>
</div>
<?php endif; ?>
<?php renderHelp([
    'Barre / materie prime' => 'Dati restituiti da /warehouse. Il gestionale li visualizza senza imporre un tracciato rigido, perché la struttura può dipendere dalla versione installata.',
    'Residui recuperabili' => 'Dati restituiti da /recovery relativi al materiale residuo che il supervisore espone come recuperabile.',
    'Importa archivio magazzino' => 'Invia un file JSON a /importWarehouse. Usa un file di collaudo prima di operare su dati reali, perché il formato deve essere compatibile con il supervisore installato.',
    'JSON' => 'È il formato dati previsto dall’endpoint di importazione magazzino. Il gestionale non modifica il contenuto del file prima dell’invio.'
], 'Help magazzino'); ?>
<?php renderFooter(); ?>
