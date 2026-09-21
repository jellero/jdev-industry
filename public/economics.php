<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$company = EconomicsService::company();
$allMachines = machines(false);
$editRuleId = filter_input(INPUT_GET, 'edit_rule', FILTER_VALIDATE_INT) ?: 0;
$editingRule = [
    'id' => null, 'name' => '', 'basis' => 'hour', 'unit_price' => '',
    'machine_id' => '', 'material_match' => '', 'active' => 1, 'notes' => ''
];

if ($editRuleId) {
    $stmt = db()->prepare('SELECT * FROM pricing_rules WHERE id=?');
    $stmt->execute([$editRuleId]);
    $editingRule = $stmt->fetch() ?: $editingRule;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_company') {
        $companyName = trim((string) ($_POST['company_name'] ?? ''));
        if ($companyName === '') {
            setFlash('La ragione sociale aziendale è obbligatoria.', 'error');
            redirect('economics.php#company');
        }

        $logoPath = $company['logo_path'] ?? null;
        if (isset($_FILES['logo']) && (int) $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ((int) $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                setFlash('Caricamento logo non riuscito.', 'error');
                redirect('economics.php#company');
            }

            if ((int) $_FILES['logo']['size'] > 3 * 1024 * 1024) {
                setFlash('Il logo supera il limite di 3 MB.', 'error');
                redirect('economics.php#company');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file((string) $_FILES['logo']['tmp_name']);
            $extensions = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
            ];

            if (!isset($extensions[$mime])) {
                setFlash('Formato logo non valido. Usa PNG, JPG o WEBP.', 'error');
                redirect('economics.php#company');
            }

            $uploadDir = __DIR__ . '/uploads';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                setFlash('Impossibile creare la cartella public/uploads.', 'error');
                redirect('economics.php#company');
            }

            foreach (glob($uploadDir . '/company-logo.*') ?: [] as $old) {
                @unlink($old);
            }

            $filename = 'company-logo.' . $extensions[$mime];
            if (!move_uploaded_file((string) $_FILES['logo']['tmp_name'], $uploadDir . '/' . $filename)) {
                setFlash('Impossibile salvare il logo.', 'error');
                redirect('economics.php#company');
            }
            $logoPath = 'uploads/' . $filename;
        }

        db()->prepare(
            'INSERT INTO company_settings
             (id, company_name, address, vat_number, email, phone, logo_path, print_footer)
             VALUES (1,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               company_name=VALUES(company_name), address=VALUES(address),
               vat_number=VALUES(vat_number), email=VALUES(email), phone=VALUES(phone),
               logo_path=VALUES(logo_path), print_footer=VALUES(print_footer)'
        )->execute([
            $companyName,
            trim((string) ($_POST['address'] ?? '')) ?: null,
            trim((string) ($_POST['vat_number'] ?? '')) ?: null,
            trim((string) ($_POST['email'] ?? '')) ?: null,
            trim((string) ($_POST['phone'] ?? '')) ?: null,
            $logoPath,
            trim((string) ($_POST['print_footer'] ?? '')) ?: null,
        ]);

        setFlash('Dati aziendali per le stampe aggiornati.');
        redirect('economics.php#company');
    }

    if ($action === 'save_rule') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $name = trim((string) ($_POST['name'] ?? ''));
        $basis = (string) ($_POST['basis'] ?? '');
        $allowedBasis = ['hour','cut','scheme','job'];
        $price = str_replace(',', '.', trim((string) ($_POST['unit_price'] ?? '0')));

        if ($name === '' || !in_array($basis, $allowedBasis, true) || !is_numeric($price) || (float) $price < 0) {
            setFlash('Compila nome, base tariffaria e prezzo con valori validi.', 'error');
            redirect('economics.php#tariffs');
        }

        $data = [
            $name,
            $basis,
            (float) $price,
            filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT) ?: null,
            trim((string) ($_POST['material_match'] ?? '')) ?: null,
            isset($_POST['active']) ? 1 : 0,
            trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];

        if ($id) {
            db()->prepare(
                'UPDATE pricing_rules
                 SET name=?, basis=?, unit_price=?, machine_id=?, material_match=?, active=?, notes=?
                 WHERE id=?'
            )->execute([...$data, $id]);
            setFlash('Voce tariffario aggiornata.');
        } else {
            db()->prepare(
                'INSERT INTO pricing_rules
                 (name, basis, unit_price, machine_id, material_match, active, notes)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute($data);
            setFlash('Voce tariffario creata.');
        }
        redirect('economics.php#tariffs');
    }

    if ($action === 'toggle_rule') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            db()->prepare('UPDATE pricing_rules SET active=1-active WHERE id=?')->execute([$id]);
            setFlash('Stato della tariffa aggiornato.');
        }
        redirect('economics.php#tariffs');
    }
}

$company = EconomicsService::company();
$rules = EconomicsService::rules();

renderHeader('Tariffario e stampe');
renderPageIntro('definire come valorizzare economicamente le lavorazioni della macchina e impostare i dati aziendali che compariranno sulle stampe.');
?>
<div class="grid">
    <section class="card col-5" id="company">
        <h2>Dati azienda e logo</h2>
        <p class="muted small">Questi dati vengono usati nell’intestazione delle stampe di lavorazione e dei report.</p>

        <?php if ($company['logo_path']): ?>
            <div class="logo-preview"><img src="<?= e($company['logo_path']) ?>" alt="Logo azienda"></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="action" value="save_company">
            <div class="form-row full"><label>Ragione sociale *</label><input name="company_name" required value="<?= e($company['company_name']) ?>"></div>
            <div class="form-row full"><label>Indirizzo</label><input name="address" value="<?= e($company['address']) ?>"></div>
            <div class="form-row"><label>P.IVA / Codice fiscale</label><input name="vat_number" value="<?= e($company['vat_number']) ?>"></div>
            <div class="form-row"><label>Telefono</label><input name="phone" value="<?= e($company['phone']) ?>"></div>
            <div class="form-row full"><label>Email</label><input type="email" name="email" value="<?= e($company['email']) ?>"></div>
            <div class="form-row full"><label>Logo stampe</label><input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"><span class="muted small">PNG, JPG o WEBP, massimo 3 MB.</span></div>
            <div class="form-row full"><label>Piè di pagina stampe</label><textarea name="print_footer" placeholder="Es. Grazie per la collaborazione"><?= e($company['print_footer']) ?></textarea></div>
            <div class="form-row full"><button class="btn" type="submit">Salva dati stampa</button></div>
        </form>
    </section>

    <section class="card col-7" id="tariffs">
        <h2><?= $editRuleId ? 'Modifica tariffa' : 'Nuova tariffa' ?></h2>
        <p class="muted small">Le tariffe automatiche usano dati realmente disponibili nei log: tempo macchina, tagli completati, schemi completati e quota fissa per commessa.</p>

        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="save_rule">
            <input type="hidden" name="id" value="<?= (int) ($editingRule['id'] ?? 0) ?>">
            <div class="form-row full"><label>Nome tariffa *</label><input name="name" required value="<?= e($editingRule['name']) ?>" placeholder="Es. Costo macchina standard"></div>
            <div class="form-row"><label>Calcolo *</label><select name="basis" required>
                <?php foreach (['hour','cut','scheme','job'] as $basis): ?>
                    <option value="<?= e($basis) ?>" <?= $editingRule['basis'] === $basis ? 'selected' : '' ?>><?= e(EconomicsService::basisLabel($basis)) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>Prezzo unitario (€) *</label><input type="number" min="0" step="0.01" name="unit_price" required value="<?= e($editingRule['unit_price']) ?>"></div>
            <div class="form-row"><label>Macchina</label><select name="machine_id"><option value="">Tutte le macchine</option>
                <?php foreach ($allMachines as $machine): ?>
                    <option value="<?= (int) $machine['id'] ?>" <?= (string) $editingRule['machine_id'] === (string) $machine['id'] ? 'selected' : '' ?>><?= e($machine['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="form-row"><label>Solo materiale</label><input name="material_match" value="<?= e($editingRule['material_match']) ?>" placeholder="Lascia vuoto per tutti"><span class="muted small">Corrispondenza esatta con Material dei log.</span></div>
            <div class="form-row full"><label><input type="checkbox" name="active" value="1" <?= $editingRule['active'] ? 'checked' : '' ?>> Tariffa attiva</label></div>
            <div class="form-row full"><label>Note</label><textarea name="notes"><?= e($editingRule['notes']) ?></textarea></div>
            <div class="form-row full actions">
                <button class="btn" type="submit">Salva tariffa</button>
                <?php if ($editRuleId): ?><a class="btn secondary" href="economics.php#tariffs">Annulla</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card col-12">
        <h2>Tariffario attivo e storico</h2>
        <?php if (!$rules): ?>
            <div class="empty">Nessuna tariffa configurata. Il costo automatico resterà a zero finché non inserisci almeno una regola.</div>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Tariffa</th><th>Calcolo</th><th>Prezzo</th><th>Macchina</th><th>Materiale</th><th>Stato</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td><strong><?= e($rule['name']) ?></strong><?php if ($rule['notes']): ?><br><span class="muted small"><?= e($rule['notes']) ?></span><?php endif; ?></td>
                        <td><?= e(EconomicsService::basisLabel($rule['basis'])) ?></td>
                        <td><?= money($rule['unit_price']) ?></td>
                        <td><?= e($rule['machine_name'] ?: 'Tutte') ?></td>
                        <td><?= e($rule['material_match'] ?: 'Tutti') ?></td>
                        <td><span class="badge <?= $rule['active'] ? 'ok' : '' ?>"><?= $rule['active'] ? 'Attiva' : 'Disattivata' ?></span></td>
                        <td><div class="actions" style="margin:0">
                            <a class="btn secondary small" href="economics.php?edit_rule=<?= (int) $rule['id'] ?>#tariffs">Modifica</a>
                            <form method="post" class="inline"><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="id" value="<?= (int) $rule['id'] ?>"><button class="btn secondary small" type="submit"><?= $rule['active'] ? 'Disattiva' : 'Attiva' ?></button></form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>
<?php renderHelp([
    'Ora macchina' => 'Moltiplica le ore di produzione registrate nei log (ElapsedTime espresso in secondi) per il prezzo orario.',
    'Taglio completato' => 'Applica un prezzo per ogni evento CUT_COMPLETED archiviato e collegato alla commessa.',
    'Schema completato' => 'Applica un prezzo per ogni evento BIN_COMPLETED archiviato e collegato alla commessa.',
    'Quota fissa commessa' => 'Aggiunge un importo una sola volta alla lavorazione, indipendentemente dal numero di eventi.',
    'Filtro materiale' => 'Se compilato, la tariffa viene applicata solo agli eventi il cui campo Material coincide esattamente. È utile quando la stessa lavorazione ha prezzi diversi per materiale.',
    'Materiali e costi extra' => 'I log indicano materiale, lunghezze e scarto, ma non contengono il prezzo di acquisto. Per non inventare unità o valori economici, il costo materiale viene aggiunto nella singola commessa come voce manuale finché il tracciato reale non è validato.',
    'Consolidamento' => 'Nella commessa puoi salvare una fotografia del costo calcolato. Serve a conservare il valore economico del momento anche se il tariffario viene modificato successivamente.'
], 'Help tariffario'); ?>
<?php renderFooter(); ?>
