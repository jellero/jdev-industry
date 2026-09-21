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
            $result = SyncService::run($machine, 'version');
            setFlash(
                $result['ok'] ? $result['message'] : ('Test fallito: ' . ($result['message'] ?? $result['error'] ?? 'errore')),
                $result['ok'] ? 'success' : 'error'
            );
        }
        redirect('settings.php');
    }

    if ($action === 'save_schedule' && $id) {
        $machine = machineById((int) $id);
        if (!$machine) {
            setFlash('Macchina non trovata.', 'error');
            redirect('settings.php');
        }

        SyncService::ensureTasks((int) $id);
        $update = db()->prepare(
            'UPDATE scheduled_tasks
             SET enabled=?, interval_minutes=?, next_run_at=CASE WHEN ?=1 THEN NOW() ELSE next_run_at END
             WHERE machine_id=? AND operation=?'
        );

        foreach (SyncService::DEFAULT_TASKS as $operation => $defaults) {
            $enabled = isset($_POST['enabled'][$operation]) ? 1 : 0;
            $interval = max(1, min(10080, (int) ($_POST['interval'][$operation] ?? $defaults['interval'])));
            $update->execute([$enabled, $interval, $enabled, $id, $operation]);
        }

        setFlash('Pianificazione automatica aggiornata per ' . $machine['name'] . '.');
        redirect('settings.php#automation-' . $id);
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
            SyncService::ensureTasks((int) $id);
            setFlash('Macchina aggiornata.');
        } else {
            db()->prepare('INSERT INTO machines (name, base_url, active, api_timeout_seconds, poll_seconds, notes) VALUES (?,?,?,?,?,?)')
                ->execute([$name, $baseUrl, $active, $timeout, $poll, trim((string) ($_POST['notes'] ?? '')) ?: null]);
            $newId = (int) db()->lastInsertId();
            SyncService::ensureTasks($newId);
            setFlash('Macchina aggiunta.');
        }
    } catch (PDOException $e) {
        setFlash('Impossibile salvare: verifica che il nome macchina sia univoco.', 'error');
    }
    redirect('settings.php');
}

$allMachines = machines(false);
$tasksByMachine = [];
foreach ($allMachines as $machine) {
    SyncService::ensureTasks((int) $machine['id']);
    $tasksByMachine[(int) $machine['id']] = SyncService::tasks((int) $machine['id']);
}

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
        <h2>Pianificazione automatica</h2>
        <p class="muted">
            Lo scheduler CLI esegue solo le operazioni abilitate e scadute. Il cron di sistema può richiamarlo ogni minuto;
            gli intervalli sottostanti stabiliscono la frequenza reale delle singole API.
        </p>
        <pre class="raw">* * * * * /usr/bin/php /percorso/jdev-industry/bin/scheduler.php &gt;&gt; /var/log/jdev-industry-scheduler.log 2&gt;&amp;1</pre>

        <?php foreach ($allMachines as $machine): ?>
            <form method="post" id="automation-<?= (int) $machine['id'] ?>" style="margin-top:22px">
                <input type="hidden" name="action" value="save_schedule">
                <input type="hidden" name="id" value="<?= (int) $machine['id'] ?>">
                <h3><?= e($machine['name']) ?></h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>Operazione</th><th>Automatica</th><th>Ogni (minuti)</th><th>Ultima esecuzione</th><th>Prossima</th><th>Esito</th></tr></thead>
                    <tbody>
                    <?php foreach ($tasksByMachine[(int) $machine['id']] as $task): ?>
                        <tr>
                            <td>
                                <strong><?= e(SyncService::operationLabel($task['operation'])) ?></strong>
                                <?php if ($task['operation'] === 'newlog'): ?><br><span class="muted small">Abilitare solo se il gestionale è l'unico consumatore di /newlog.</span><?php endif; ?>
                            </td>
                            <td><input style="width:auto" type="checkbox" name="enabled[<?= e($task['operation']) ?>]" value="1" <?= $task['enabled'] ? 'checked' : '' ?>></td>
                            <td><input style="max-width:120px" type="number" min="1" max="10080" name="interval[<?= e($task['operation']) ?>]" value="<?= (int) $task['interval_minutes'] ?>"></td>
                            <td><?= e($task['last_run_at'] ?: '—') ?></td>
                            <td><?= e($task['next_run_at'] ?: '—') ?></td>
                            <td><span class="badge <?= $task['last_status'] === 'ok' ? 'ok' : ($task['last_status'] === 'error' ? 'danger' : '') ?>"><?= e($task['last_status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="actions"><button class="btn" type="submit">Salva pianificazione</button></div>
            </form>
        <?php endforeach; ?>
    </section>

    <section class="card col-12">
        <h2>Nota rete e sicurezza</h2>
        <p>Questo gestionale è volutamente privo di login. Va quindi pubblicato solo su rete aziendale/OT protetta. Anche il servizio Tecnoessetre sulla porta 8030 deve rimanere accessibile esclusivamente dai sistemi autorizzati e non essere esposto direttamente su Internet.</p>
    </section>
</div>
<?php renderFooter(); ?>
