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

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'open');
$clientFilter = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT) ?: 0;
$machineFilter = filter_input(INPUT_GET, 'machine_id', FILTER_VALIDATE_INT) ?: 0;
$sort = (string) ($_GET['sort'] ?? 'updated');

$allowedStatusFilters = ['all','open','planned','ready','sent','in_progress','done','cancelled'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'open';
}
$allowedSorts = ['updated','code','client','status'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'updated';
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(j.code LIKE ? OR j.name LIKE ? OR j.external_project LIKE ? OR j.notes LIKE ? OR c.company_name LIKE ? OR m.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if ($statusFilter === 'open') {
    $where[] = "j.status IN ('planned','ready','sent','in_progress')";
} elseif ($statusFilter !== 'all') {
    $where[] = 'j.status = ?';
    $params[] = $statusFilter;
}

if ($clientFilter > 0) {
    $where[] = 'j.client_id = ?';
    $params[] = $clientFilter;
}
if ($machineFilter > 0) {
    $where[] = 'j.machine_id = ?';
    $params[] = $machineFilter;
}

$orderBy = match ($sort) {
    'code' => 'j.code, j.id DESC',
    'client' => 'c.company_name, j.updated_at DESC',
    'status' => "FIELD(j.status,'in_progress','sent','ready','planned','done','cancelled'), j.updated_at DESC",
    default => 'j.updated_at DESC, j.id DESC',
};

$sql = "SELECT j.*, c.company_name, m.name AS machine_name
        FROM jobs j
        LEFT JOIN clients c ON c.id=j.client_id
        LEFT JOIN machines m ON m.id=j.machine_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY ' . $orderBy;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$filtersActive = $q !== '' || $statusFilter !== 'open' || $clientFilter > 0 || $machineFilter > 0 || $sort !== 'updated';

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
        <div class="section-heading">
            <div>
                <h2>Elenco commesse</h2>
                <p class="muted small"><?= count($jobs) ?> risultati. Di default vengono mostrate le commesse aperte.</p>
            </div>
        </div>

        <form method="get" class="filter-panel">
            <div class="filter-grid jobs-filter-grid">
                <div class="form-row filter-search">
                    <label for="job-q">Cerca</label>
                    <input id="job-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Codice, descrizione, progetto, cliente o macchina…">
                </div>
                <div class="form-row">
                    <label for="job-status">Stato</label>
                    <select id="job-status" name="status">
                        <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Aperte</option>
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Tutte</option>
                        <?php foreach (['planned','ready','sent','in_progress','done','cancelled'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(statusLabel($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="job-client">Cliente</label>
                    <select id="job-client" name="client_id">
                        <option value="">Tutti</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= (int) $client['id'] ?>" <?= (int) $clientFilter === (int) $client['id'] ? 'selected' : '' ?>><?= e($client['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="job-machine">Macchina</label>
                    <select id="job-machine" name="machine_id">
                        <option value="">Tutte</option>
                        <?php foreach ($allMachines as $machine): ?>
                            <option value="<?= (int) $machine['id'] ?>" <?= (int) $machineFilter === (int) $machine['id'] ? 'selected' : '' ?>><?= e($machine['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="job-sort">Ordina</label>
                    <select id="job-sort" name="sort">
                        <option value="updated" <?= $sort === 'updated' ? 'selected' : '' ?>>Più recenti</option>
                        <option value="code" <?= $sort === 'code' ? 'selected' : '' ?>>Codice commessa</option>
                        <option value="client" <?= $sort === 'client' ? 'selected' : '' ?>>Cliente</option>
                        <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>Priorità operativa</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button class="btn" type="submit">Cerca</button>
                <?php if ($filtersActive): ?><a class="btn secondary" href="jobs.php">Azzera filtri</a><?php endif; ?>
            </div>
        </form>

        <?php if (!$jobs): ?>
            <div class="empty">
                <strong>Nessuna commessa trovata.</strong><br>
                <span>Modifica o azzera i filtri. Se cerchi lavori conclusi, imposta Stato su “Tutte” o “Completata”.</span>
            </div>
        <?php else: ?><div class="table-wrap"><table>
            <thead><tr><th>Commessa</th><th>Cliente</th><th>Macchina</th><th>Stato</th><th>Aggiornata</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td>
                        <strong><?= e($job['code']) ?></strong><br>
                        <span class="muted"><?= e($job['name']) ?></span>
                        <?php if ($job['external_project']): ?><br><span class="muted small">Progetto: <?= e($job['external_project']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e($job['company_name'] ?: '—') ?></td>
                    <td><?= e($job['machine_name'] ?: '—') ?></td>
                    <td><span class="badge"><?= e(statusLabel($job['status'])) ?></span></td>
                    <td><?= e(date('d/m/Y H:i', strtotime((string) $job['updated_at']))) ?></td>
                    <td><div class="actions" style="margin:0"><a class="btn small" href="job.php?id=<?= (int) $job['id'] ?>">Apri</a><a class="btn secondary small" href="jobs.php?edit=<?= (int) $job['id'] ?>">Modifica</a></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div><?php endif; ?>
    </section>
</div>
<?php renderHelp([
    'Ricerca libera' => 'Cerca insieme in codice commessa, descrizione, riferimento progetto macchina, note, cliente e nome macchina.',
    'Aperte' => 'Mostra Pianificate, Pronte, Inviate e In lavorazione. È il filtro predefinito perché sono le commesse che richiedono normalmente attenzione operativa.',
    'Priorità operativa' => 'Ordina prima le commesse In lavorazione, poi Inviate, Pronte e Pianificate; le concluse vengono dopo.',
    'Filtro Cliente' => 'Puoi arrivare già filtrato da Clienti cliccando il conteggio delle commesse di una specifica anagrafica.',
    'Codice commessa' => 'Identificativo univoco interno del lavoro. Può anche essere usato per correlare i log se coincide con il riferimento Project restituito dalla macchina.',
    'Macchina' => 'È la macchina industriale associata alla commessa. Serve anche per abilitare l’invio del file di produzione dalla pagina della commessa.',
    'Riferimento progetto macchina' => 'È il nome con cui la macchina identifica il lavoro nei propri log. Compilarlo consente di collegare automaticamente eventi e consuntivi alla commessa.',
    'Stati' => 'Pianificata, Pronta, Inviata alla macchina, In lavorazione, Completata o Annullata. Alcuni passaggi possono essere aggiornati automaticamente quando vengono acquisiti eventi macchina.'
], 'Help commesse'); ?>
<?php renderFooter(); ?>
