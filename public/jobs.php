<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$clients = db()->query('SELECT id, company_name FROM clients ORDER BY company_name')->fetchAll();
$allMachines = machines(false);
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

$editing = $editId ? null : [
    'id' => null, 'client_id' => '', 'machine_id' => '', 'code' => '', 'name' => '',
    'external_project' => '', 'status' => 'planned', 'notes' => ''
];

if ($editId) {
    $stmt = db()->prepare('SELECT * FROM jobs WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $code = trim((string) ($_POST['code'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $allowedStatuses = ['planned','ready','sent','in_progress','done','cancelled'];
    $status = (string) ($_POST['status'] ?? 'planned');

    if ($code === '' || $name === '' || !in_array($status, $allowedStatuses, true)) {
        setFlash('Codice, descrizione e stato validi sono obbligatori.', 'error');
        redirect($id ? 'jobs.php?edit=' . $id : 'jobs.php');
    }

    $data = [
        filter_input(INPUT_POST, 'client_id', FILTER_VALIDATE_INT) ?: null,
        filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT) ?: null,
        $code,
        $name,
        trim((string) ($_POST['external_project'] ?? '')) ?: null,
        $status,
        trim((string) ($_POST['notes'] ?? '')) ?: null,
    ];

    try {
        if ($id) {
            $stmt = db()->prepare('UPDATE jobs SET client_id=?, machine_id=?, code=?, name=?, external_project=?, status=?, notes=? WHERE id=?');
            $stmt->execute([...$data, $id]);
            setFlash('Commessa aggiornata.');
        } else {
            $stmt = db()->prepare('INSERT INTO jobs (client_id, machine_id, code, name, external_project, status, notes) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute($data);
            setFlash('Commessa creata.');
        }
    } catch (PDOException $e) {
        setFlash('Impossibile salvare: verifica che il codice commessa sia univoco.', 'error');
    }
    redirect('jobs.php');
}

$jobs = db()->query(
    "SELECT j.*, c.company_name, m.name AS machine_name
     FROM jobs j
     LEFT JOIN clients c ON c.id=j.client_id
     LEFT JOIN machines m ON m.id=j.machine_id
     ORDER BY j.updated_at DESC, j.id DESC"
)->fetchAll();

renderHeader('Commesse');
?>
<div class="grid">
    <section class="card col-4">
        <h2><?= $editing && $editing['id'] ? 'Modifica commessa' : 'Nuova commessa' ?></h2>
        <?php if ($editing): ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="form-row"><label>Codice *</label><input name="code" required value="<?= e($editing['code']) ?>"></div>
            <div class="form-row"><label>Stato</label>
                <select name="status">
                    <?php foreach (['planned','ready','sent','in_progress','done','cancelled'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $editing['status'] === $s ? 'selected' : '' ?>><?= e(statusLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row full"><label>Descrizione *</label><input name="name" required value="<?= e($editing['name']) ?>"></div>
            <div class="form-row"><label>Cliente</label><select name="client_id"><option value="">—</option>
                <?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>" <?= (string)$editing['client_id'] === (string)$client['id'] ? 'selected' : '' ?>><?= e($client['company_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>Macchina</label><select name="machine_id"><option value="">—</option>
                <?php foreach ($allMachines as $machine): ?><option value="<?= (int) $machine['id'] ?>" <?= (string)$editing['machine_id'] === (string)$machine['id'] ? 'selected' : '' ?>><?= e($machine['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row full"><label>Riferimento progetto macchina</label><input name="external_project" value="<?= e($editing['external_project']) ?>" placeholder="Nome BTL / Project restituito dai log"></div>
            <div class="form-row full"><label>Note</label><textarea name="notes"><?= e($editing['notes']) ?></textarea></div>
            <div class="form-row full actions"><button class="btn" type="submit">Salva commessa</button><?php if ($editing['id']): ?><a class="btn secondary" href="jobs.php">Annulla</a><?php endif; ?></div>
        </form>
        <?php else: ?><div class="alert error">Commessa non trovata.</div><?php endif; ?>
    </section>

    <section class="card col-8">
        <h2>Elenco commesse</h2>
        <?php if (!$jobs): ?><div class="empty">Nessuna commessa inserita.</div>
        <?php else: ?><div class="table-wrap"><table>
            <thead><tr><th>Commessa</th><th>Cliente</th><th>Macchina</th><th>Stato</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><strong><?= e($job['code']) ?></strong><br><span class="muted"><?= e($job['name']) ?></span></td>
                    <td><?= e($job['company_name'] ?: '—') ?></td>
                    <td><?= e($job['machine_name'] ?: '—') ?></td>
                    <td><span class="badge"><?= e(statusLabel($job['status'])) ?></span></td>
                    <td><div class="actions" style="margin:0"><a class="btn small" href="job.php?id=<?= (int) $job['id'] ?>">Apri</a><a class="btn secondary small" href="jobs.php?edit=<?= (int) $job['id'] ?>">Modifica</a></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div><?php endif; ?>
    </section>
</div>
<?php renderFooter(); ?>
