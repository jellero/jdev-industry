(() => {
    const fmt = (value, fallback = '—') => value === null || value === undefined || value === '' ? fallback : String(value);
    const asConnected = (value) => {
        if (typeof value === 'boolean') return value;
        const s = String(value ?? '').trim().toLowerCase();
        return ['1', 'true', 'yes', 'ok', 'connected', 'connesso'].includes(s);
    };

    async function getOverview(id) {
        const response = await fetch('ajax/machine-overview.php?id=' + encodeURIComponent(id), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || 'Errore di comunicazione');
        return payload;
    }

    function renderCard(card, data) {
        const s = data.summary || {};
        const badge = card.querySelector('[data-role="connection"]');
        const connected = asConnected(s.connected);
        badge.textContent = connected ? 'Connessa' : (s.connected ? fmt(s.connected) : 'Stato non disponibile');
        badge.className = 'badge ' + (connected ? 'ok' : 'warn');
        card.querySelector('[data-role="mode"]').textContent = fmt(s.mode);
        card.querySelector('[data-role="project"]').textContent = fmt(s.project_name, 'Nessun progetto rilevato');

        const progress = card.querySelector('[data-role="progress"]');
        const progressLabel = card.querySelector('[data-role="progress-label"]');
        if (typeof s.progress === 'number') {
            progress.style.width = Math.max(0, Math.min(100, s.progress)) + '%';
            progressLabel.textContent = s.progress.toFixed(0) + '%';
        } else {
            progress.style.width = '0%';
            progressLabel.textContent = 'Avanzamento non disponibile';
        }

        card.querySelector('[data-role="message"]').textContent = fmt(s.errors || s.warnings || s.comments, 'Nessuna segnalazione');
        card.querySelector('[data-role="updated"]').textContent = new Date().toLocaleTimeString('it-IT');
    }

    function renderMonitor(root, data) {
        const s = data.summary || {};
        root.querySelector('[data-role="connection"]').textContent = fmt(s.connected);
        root.querySelector('[data-role="mode"]').textContent = fmt(s.mode);
        root.querySelector('[data-role="comments"]').textContent = fmt(s.comments);
        root.querySelector('[data-role="warnings"]').textContent = fmt(s.warnings);
        root.querySelector('[data-role="errors"]').textContent = fmt(s.errors);
        root.querySelector('[data-role="project"]').textContent = fmt(s.project_name);
        root.querySelector('[data-role="progress-label"]').textContent = typeof s.progress === 'number' ? s.progress.toFixed(1) + '%' : '—';
        root.querySelector('[data-role="progress"]').style.width = typeof s.progress === 'number' ? Math.max(0, Math.min(100, s.progress)) + '%' : '0%';

        root.querySelector('[data-role="raw-state"]').textContent = JSON.stringify(data.state?.json ?? data.state?.body ?? null, null, 2);
        root.querySelector('[data-role="raw-projects"]').textContent = JSON.stringify(data.projects?.json ?? data.projects?.body ?? null, null, 2);

        const activity = Array.isArray(s.activity) ? s.activity : [];
        const activityEl = root.querySelector('[data-role="activity"]');
        activityEl.innerHTML = '';
        const max = Math.max(1, ...activity.map(v => Number(v) || 0));
        activity.slice(0, 24).forEach((value, hour) => {
            const bar = document.createElement('div');
            bar.className = 'activity-bar';
            const number = Number(value) || 0;
            bar.style.height = Math.max(3, (number / max) * 100) + '%';
            bar.title = String(hour).padStart(2, '0') + ':00 · ' + number;
            activityEl.appendChild(bar);
        });

        root.querySelector('[data-role="updated"]').textContent = new Date().toLocaleTimeString('it-IT');
    }

    function startPoll(element, renderer) {
        const id = element.dataset.machineId;
        const interval = Math.max(3000, Number(element.dataset.pollSeconds || 5) * 1000);
        let timer = null;
        let failures = 0;

        const run = async () => {
            clearTimeout(timer);
            try {
                const data = await getOverview(id);
                failures = 0;
                renderer(element, data);
            } catch (error) {
                failures += 1;
                const target = element.querySelector('[data-role="connection"]');
                if (target) {
                    target.textContent = 'Non raggiungibile';
                    if (target.classList.contains('badge')) target.className = 'badge danger';
                }
                const raw = element.querySelector('[data-role="raw-state"]');
                if (raw) raw.textContent = error.message;
            } finally {
                const backoff = Math.min(30000, interval * Math.max(1, failures));
                timer = setTimeout(run, backoff);
            }
        };

        run();
    }

    document.querySelectorAll('.machine-live').forEach(el => startPoll(el, renderCard));
    document.querySelectorAll('.machine-monitor').forEach(el => startPoll(el, renderMonitor));
})();
