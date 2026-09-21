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

$clients = db()->query('SELECT * FROM clients ORDER BY company_name')->fetchAll();

renderHeader('Clienti');
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
        <h2>Elenco clienti</h2>
        <?php if (!$clients): ?>
            <div class="empty">Nessun cliente inserito.</div>
        <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Cliente</th><th>Codice</th><th>Referente</th><th>Contatti</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($clients as $client): ?>
                <tr>
                    <td><strong><?= e($client['company_name']) ?></strong></td>
                    <td><?= e($client['code'] ?: '—') ?></td>
                    <td><?= e($client['contact_name'] ?: '—') ?></td>
                    <td><?= e($client['email'] ?: '') ?><br><span class="muted"><?= e($client['phone'] ?: '') ?></span></td>
                    <td><a class="btn secondary small" href="clients.php?edit=<?= (int) $client['id'] ?>">Modifica</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
</div>
<?php renderFooter(); ?>
