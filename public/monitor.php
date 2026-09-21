<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$machine = $id ? machineById((int) $id) : null;
if (!$machine) {
    http_response_code(404);
    renderHeader('Macchina non trovata');
    echo '<div class="card">La macchina richiesta non esiste.</div>';
    renderFooter();
    exit;
}

renderHeader('Monitoraggio · ' . $machine['name']);
?>
<div class="machine-monitor"
     data-machine-id="<?= (int) $machine['id'] ?>"
     data-poll-seconds="<?= (int) $machine['poll_seconds'] ?>">
    <div class="grid">
        <section class="card col-4">
            <h2>Stato corrente</h2>
            <dl class="meta">
                <dt>Connessione</dt><dd data-role="connection">Lettura…</dd>
                <dt>Modalità</dt><dd data-role="mode">—</dd>
                <dt>Commenti</dt><dd data-role="comments">—</dd>
                <dt>Avvisi</dt><dd data-role="warnings">—</dd>
                <dt>Errori</dt><dd data-role="errors">—</dd>
                <dt>Aggiornato</dt><dd data-role="updated">—</dd>
            </dl>
        </section>

        <section class="card col-8">
            <h2>Lavorazione</h2>
            <dl class="meta">
                <dt>Progetto macchina</dt><dd data-role="project">—</dd>
                <dt>Commessa</dt><dd data-role="job">—</dd>
                <dt>Cliente</dt><dd data-role="client">—</dd>
                <dt>Avanzamento</dt><dd data-role="progress-label">—</dd>
            </dl>
            <div class="progress" style="height:16px"><span data-role="progress"></span></div>
            <p class="muted small">Il nome e la percentuale sono ricavati dai campi disponibili in <span class="code-note">/project/last10</span>. La pagina Test API serve a confermare il tracciato reale della macchina.</p>
        </section>

        <section class="card col-12">
            <h2>Attività oraria</h2>
            <div class="activity-grid" data-role="activity"></div>
            <div class="activity-labels">
                <?php for ($h = 0; $h < 24; $h += 2): ?><span><?= str_pad((string) $h, 2, '0', STR_PAD_LEFT) ?></span><?php endfor; ?>
            </div>
        </section>

        <section class="card col-6">
            <h2>Payload stato</h2>
            <pre class="raw" data-role="raw-state">Lettura…</pre>
        </section>
        <section class="card col-6">
            <h2>Payload ultimi progetti</h2>
            <pre class="raw" data-role="raw-projects">Lettura…</pre>
        </section>
    </div>
</div>
<?php renderFooter(); ?>
