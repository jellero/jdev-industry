<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editing = $editId ? null : [
    'id' => null, 'company_name' => '', 'code' => '', 'contact_name' => '',
    'email' => '', 'phone' => '', 'notes' => ''
];

if ($editId) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $company = trim((string) ($_POST['company_name'] ?? ''));
    if ($company === '') {
        setFlash('La ragione sociale è obbligatoria.', 'error');
        redirect($id ? 'clients.php?edit=' . $id : 'clients.php');
    }

    $data = [
        $company,
        trim((string) ($_POST['code'] ?? '')) ?: null,
        trim((string) ($_POST['contact_name'] ?? '')) ?: null,
        trim((string) ($_POST['email'] ?? '')) ?: null,
        trim((string) ($_POST['phone'] ?? '')) ?: null,
        trim((string) ($_POST['notes'] ?? '')) ?: null,
    ];

    if ($id) {
        $stmt = db()->prepare('UPDATE clients SET company_name=?, code=?, contact_name=?, email=?, phone=?, notes=? WHERE id=?');
        $stmt->execute([...$data, $id]);
        setFlash('Cliente aggiornato.');
    } else {
        $stmt = db()->prepare('INSERT INTO clients (company_name, code, contact_name, email, phone, notes) VALUES (?,?,?,?,?,?)');
        $stmt->execute($data);
        setFlash('Cliente creato.');
    }
    redirect('clients.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$jobsFilter = (string) ($_GET['jobs'] ?? 'all');
$sort = (string) ($_GET['sort'] ?? 'company');

$allowedJobsFilters = ['all', 'with_jobs', 'without_jobs', 'open_jobs'];
if (!in_array($jobsFilter, $allowedJobsFilters, true)) {
    $jobsFilter = 'all';
}

$allowedSorts = ['company', 'updated', 'created'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'company';
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(c.company_name LIKE ? OR c.code LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.notes LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if ($jobsFilter === 'with_jobs') {
    $where[] = 'EXISTS (SELECT 1 FROM jobs jx WHERE jx.client_id=c.id)';
} elseif ($jobsFilter === 'without_jobs') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM jobs jx WHERE jx.client_id=c.id)';
} elseif ($jobsFilter === 'open_jobs') {
    $where[] = "EXISTS (
        SELECT 1 FROM jobs jx
        WHERE jx.client_id=c.id
          AND jx.status IN ('planned','ready','sent','in_progress')
    )";
}

$orderBy = match ($sort) {
    'updated' => 'c.updated_at DESC, c.company_name',
    'created' => 'c.created_at DESC, c.company_name',
    default => 'c.company_name',
};

$sql = "SELECT c.*,
        (SELECT COUNT(*) FROM jobs j WHERE j.client_id=c.id) AS jobs_count,
        (SELECT COUNT(*) FROM jobs j WHERE j.client_id=c.id AND j.status IN ('planned','ready','sent','in_progress')) AS open_jobs_count
        FROM clients c";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY ' . $orderBy;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll();
$filtersActive = $q !== '' || $jobsFilter !== 'all' || $sort !== 'company';

renderHeader('Clienti');
renderPageIntro('trovare rapidamente un cliente, gestirne i riferimenti e passare con un clic alle sue commesse.');
?>
<div class="grid">
    <section class="card col-4">
        <h2><?= $editing && $editing['id'] ? 'Modifica cliente' : 'Nuovo cliente' ?></h2>
        <?php if ($editId && !$editing): ?><div class="alert error">Cliente non trovato.</div><?php endif; ?>
        <?php if ($editing): ?>
        <form method="post" class="form-grid">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="form-row full"><label>Ragione sociale *</label><input name="company_name" required value="<?= e($editing['company_name']) ?>"></div>
            <div class="form-row"><label>Codice cliente</label><input name="code" value="<?= e($editing['code']) ?>"></div>
            <div class="form-row"><label>Referente</label><input name="contact_name" value="<?= e($editing['contact_name']) ?>"></div>
            <div class="form-row"><label>Email</label><input type="email" name="email" value="<?= e($editing['email']) ?>"></div>
            <div class="form-row"><label>Telefono</label><input name="phone" value="<?= e($editing['phone']) ?>"></div>
            <div class="form-row full"><label>Note</label><textarea name="notes"><?= e($editing['notes']) ?></textarea></div>
            <div class="form-row full actions">
                <button class="btn" type="submit">Salva cliente</button>
                <?php if ($editing['id']): ?><a class="btn secondary" href="clients.php">Annulla</a><?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <section class="card col-8">
        <div class="section-heading">
            <div>
                <h2>Elenco clienti</h2>
                <p class="muted small"><?= count($clients) ?> risultati<?= $filtersActive ? ' con i filtri attivi' : '' ?>.</p>
            </div>
        </div>

        <form method="get" class="filter-panel">
            <div class="filter-grid clients-filter-grid">
                <div class="form-row filter-search">
                    <label for="client-q">Cerca</label>
                    <input id="client-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Ragione sociale, codice, referente, email, telefono…">
                </div>
                <div class="form-row">
                    <label for="client-jobs">Commesse</label>
                    <select id="client-jobs" name="jobs">
                        <option value="all" <?= $jobsFilter === 'all' ? 'selected' : '' ?>>Tutti i clienti</option>
                        <option value="with_jobs" <?= $jobsFilter === 'with_jobs' ? 'selected' : '' ?>>Con almeno una commessa</option>
                        <option value="open_jobs" <?= $jobsFilter === 'open_jobs' ? 'selected' : '' ?>>Con commesse aperte</option>
                        <option value="without_jobs" <?= $jobsFilter === 'without_jobs' ? 'selected' : '' ?>>Senza commesse</option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="client-sort">Ordina</label>
                    <select id="client-sort" name="sort">
                        <option value="company" <?= $sort === 'company' ? 'selected' : '' ?>>Ragione sociale A-Z</option>
                        <option value="updated" <?= $sort === 'updated' ? 'selected' : '' ?>>Modificati di recente</option>
                        <option value="created" <?= $sort === 'created' ? 'selected' : '' ?>>Inseriti di recente</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button class="btn" type="submit">Cerca</button>
                <?php if ($filtersActive): ?><a class="btn secondary" href="clients.php">Azzera filtri</a><?php endif; ?>
            </div>
        </form>

        <?php if (!$clients): ?>
            <div class="empty">
                <strong>Nessun cliente trovato.</strong><br>
                <span>Modifica o azzera i filtri di ricerca.</span>
            </div>
        <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Cliente</th><th>Codice</th><th>Referente</th><th>Contatti</th><th>Commesse</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($clients as $client): ?>
                <tr>
                    <td><strong><?= e($client['company_name']) ?></strong></td>
                    <td><?= e($client['code'] ?: '—') ?></td>
                    <td><?= e($client['contact_name'] ?: '—') ?></td>
                    <td><?= e($client['email'] ?: '—') ?><br><span class="muted"><?= e($client['phone'] ?: '') ?></span></td>
                    <td>
                        <a href="jobs.php?client_id=<?= (int) $client['id'] ?>&status=all"><?= (int) $client['jobs_count'] ?> totali</a>
                        <?php if ((int) $client['open_jobs_count'] > 0): ?>
                            · <a href="jobs.php?client_id=<?= (int) $client['id'] ?>&status=open"><?= (int) $client['open_jobs_count'] ?> aperte</a>
                        <?php endif; ?>
                    </td>
                    <td><div class="actions" style="margin:0">
                        <a class="btn secondary small" href="clients.php?edit=<?= (int) $client['id'] ?>">Modifica</a>
                        <a class="btn secondary small" href="reports.php?client_id=<?= (int) $client['id'] ?>&preset=year">Report</a>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
</div>
<?php renderHelp([
    'Ricerca libera' => 'Cerca contemporaneamente in ragione sociale, codice cliente, referente, email, telefono e note. Non devi scegliere prima il campo.',
    'Filtro commesse' => 'Permette di isolare clienti con commesse, senza commesse oppure con almeno una commessa ancora aperta.',
    'Commesse' => 'Il conteggio è cliccabile e apre direttamente la pagina Commesse già filtrata sul cliente selezionato.',
    'Codice cliente' => 'Campo facoltativo per un codice interno, codice ERP o altro riferimento aziendale. Non viene inviato alla macchina.',
    'Referente' => 'Persona di riferimento del cliente. È un dato anagrafico e non influenza la comunicazione con la macchina.',
    'Note' => 'Spazio libero per informazioni operative o amministrative relative al cliente.'
], 'Help clienti'); ?>
<?php renderFooter(); ?>
