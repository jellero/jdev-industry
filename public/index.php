<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$activeMachines = machines(true);
$clientCount = (int) db()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$openJobs = (int) db()->query("SELECT COUNT(*) FROM jobs WHERE status IN ('planned','ready','sent','in_progress')")->fetchColumn();
$doneJobs = (int) db()->query("SELECT COUNT(*) FROM jobs WHERE status = 'done'")->fetchColumn();

renderHeader('Dashboard');
?>
<div class="grid">
    <div class="card col-3"><div class="muted small">Macchine attive</div><div class="kpi"><?= count($activeMachines) ?></div></div>
    <div class="card col-3"><div class="muted small">Clienti</div><div class="kpi"><?= $clientCount ?></div></div>
    <div class="card col-3"><div class="muted small">Commesse aperte</div><div class="kpi"><?= $openJobs ?></div></div>
    <div class="card col-3"><div class="muted small">Commesse completate</div><div class="kpi"><?= $doneJobs ?></div></div>
</div>

<div class="page-title" style="margin-top:24px">
    <h2 style="margin:0">Stato macchine</h2>
    <a class="btn secondary" href="settings.php">Configura macchine</a>
</div>

<?php if (!$activeMachines): ?>
    <div class="card empty">Nessuna macchina attiva. Aggiungila da <a href="settings.php">Impostazioni</a>.</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($activeMachines as $machine): ?>
            <section class="card col-6 machine-card machine-live"
                     data-machine-id="<?= (int) $machine['id'] ?>"
                     data-poll-seconds="<?= (int) $machine['poll_seconds'] ?>">
                <div class="machine-head">
                    <div>
                        <h2><?= e($machine['name']) ?></h2>
                        <div class="muted small"><?= e($machine['base_url']) ?></div>
                    </div>
                    <span class="badge" data-role="connection">Lettura…</span>
                </div>
                <dl class="meta">
                    <dt>Modalità</dt><dd data-role="mode">—</dd>
                    <dt>Lavoro / progetto</dt><dd data-role="project">—</dd>
                    <dt>Segnalazioni</dt><dd data-role="message">—</dd>
                    <dt>Aggiornato</dt><dd data-role="updated">—</dd>
                </dl>
                <div style="margin-top:14px">
                    <strong class="small">Avanzamento</strong>
                    <div class="progress"><span data-role="progress"></span></div>
                    <div class="muted small" data-role="progress-label">—</div>
                </div>
                <div class="actions">
                    <a class="btn" href="monitor.php?id=<?= (int) $machine['id'] ?>">Monitora</a>
                    <button class="btn secondary" type="button" data-sync-status data-machine-id="<?= (int) $machine['id'] ?>">Stato</button>
                    <a class="btn secondary" href="history.php?machine_id=<?= (int) $machine['id'] ?>">Storico lavori</a>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<dialog class="sync-dialog" id="sync-status-dialog">
    <div class="dialog-head">
        <h2 data-sync-title>Stato sincronizzazione</h2>
        <button class="dialog-close" type="button" data-dialog-close aria-label="Chiudi">×</button>
    </div>
    <div data-sync-body><div class="empty">Lettura…</div></div>
</dialog>
<?php renderFooter(); ?>
