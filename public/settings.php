<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editing = $editId ? machineById((int) $editId) : [
    'id' => null, 'name' => '', 'base_url' => 'http://192.168.1.100:8030',
    'active' => 1, 'api_timeout_seconds' => 5, 'poll_seconds' => 5, 'notes' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if ($action === 'test' && $id) {
        $machine = machineById((int) $id);
        if (!$machine) {
            setFlash('Macchina non trovata.', 'error');
        } else {
            $result = machineApi($machine)->get('/version');
            if ($result['ok']) {
                $version = trim($result['body']);
                db()->prepare('UPDATE machines SET last_version=?, last_seen_at=NOW() WHERE id=?')->execute([$version, $id]);
                setFlash('Connessione riuscita. Versione supervisore: ' . ($version ?: 'risposta vuota'));
            } else {
                setFlash('Test fallito: ' . ($result['error'] ?: ('HTTP ' . $result['status'])), 'error');
            }
        }
        redirect('settings.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
    $timeout = max(2, min(30, (int) ($_POST['api_timeout_seconds'] ?? 5)));
    $poll = max(3, min(60, (int) ($_POST['poll_seconds'] ?? 5)));
    $active = isset($_POST['active']) ? 1 : 0;

    if ($name === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl)) {
        setFlash('Inserisci nome e URL HTTP/HTTPS validi, ad esempio http://192.168.1.100:8030.', 'error');
        redirect($id ? 'settings.php?edit=' . $id : 'settings.php');
    }

    try {
        if ($id) {
            db()->prepare('UPDATE machines SET name=?, base_url=?, active=?, api_timeout_seconds=?, poll_seconds=?, notes=? WHERE id=?')
                ->execute([$name, $baseUrl, $active, $timeout, $poll, trim((string) ($_POST['notes'] ?? '')) ?: null, $id]);
            setFlash('Macchina aggiornata.');
        } else {
            db()->prepare('INSERT INTO machines (name, base_url, active, api_timeout_seconds, poll_seconds, notes) VALUES (?,?,?,?,?,?)')
                ->execute([$name, $baseUrl, $active, $timeout, $poll, trim((string) ($_POST['notes'] ?? '')) ?: null]);
            setFlash('Macchina aggiunta.');
        }
    } catch (PDOException $e) {
        setFlash('Impossibile salvare: verifica che il nome macchina sia univoco.', 'error');
    }
    redirect('settings.php');
}

$allMachines = machines(false);
renderHeader('Impostazioni');
?>
<div class="grid">
    <section class="card col-5">
        <h2><?= $editing && $editing['id'] ? 'Modifica macchina' : 'Aggiungi macchina' ?></h2>
        <?php if (!$editing): ?><div class="alert error">Macchina non trovata.</div><?php else: ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>"><input type="hidden" name="action" value="save">
            <div class="form-row full"><label>Nome macchina *</label><input name="name" required value="<?= e($editing['name']) ?>" placeholder="Essetre 1"></div>
            <div class="form-row full"><label>URL API *</label><input name="base_url" required value="<?= e($editing['base_url']) ?>" placeholder="http://192.168.1.100:8030"></div>
            <div class="form-row"><label>Timeout API (s)</label><input type="number" min="2" max="30" name="api_timeout_seconds" value="<?= (int) $editing['api_timeout_seconds'] ?>"></div>
            <div class="form-row"><label>Aggiornamento dashboard (s)</label><input type="number" min="3" max="60" name="poll_seconds" value="<?= (int) $editing['poll_seconds'] ?>"></div>
            <div class="form-row full"><label><input style="width:auto" type="checkbox" name="active" value="1" <?= $editing['active'] ? 'checked' : '' ?>> Macchina attiva</label></div>
            <div class="form-row full"><label>Note</label><textarea name="notes"><?= e($editing['notes']) ?></textarea></div>
            <div class="form-row full actions"><button class="btn" type="submit">Salva macchina</button><?php if ($editing['id']): ?><a class="btn secondary" href="settings.php">Annulla</a><?php endif; ?></div>
        </form>
        <?php endif; ?>
    </section>

    <section class="card col-7">
        <h2>Macchine configurate</h2>
        <?php if (!$allMachines): ?><div class="empty">Nessuna macchina configurata.</div>
        <?php else: ?><div class="table-wrap"><table>
            <thead><tr><th>Macchina</th><th>API</th><th>Versione</th><th>Ultimo contatto</th><th></th></tr></thead>
            <tbody><?php foreach ($allMachines as $machine): ?><tr>
                <td><strong><?= e($machine['name']) ?></strong><br><span class="badge <?= $machine['active'] ? 'ok' : '' ?>"><?= $machine['active'] ? 'Attiva' : 'Disattivata' ?></span></td>
                <td><?= e($machine['base_url']) ?></td>
                <td><?= e($machine['last_version'] ?: '—') ?></td>
                <td><?= e($machine['last_seen_at'] ?: '—') ?></td>
                <td>
                    <div class="actions" style="margin:0">
                        <a class="btn secondary small" href="settings.php?edit=<?= (int) $machine['id'] ?>">Modifica</a>
                        <form method="post" class="inline"><input type="hidden" name="id" value="<?= (int) $machine['id'] ?>"><input type="hidden" name="action" value="test"><button class="btn small" type="submit">Verifica /version</button></form>
                    </div>
                </td>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>

    <section class="card col-12">
        <h2>Nota rete e sicurezza</h2>
        <p>Questo gestionale è volutamente privo di login. Va quindi pubblicato solo su rete aziendale/OT protetta. Anche il servizio Tecnoessetre sulla porta 8030 deve rimanere accessibile esclusivamente dai sistemi autorizzati e non essere esposto direttamente su Internet.</p>
    </section>
</div>
<?php renderFooter(); ?>
