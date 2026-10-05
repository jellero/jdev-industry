# Ambiente di test locale

L'ambiente usa PHP 8.3 con Apache e MariaDB 11.4. Il database viene
inizializzato automaticamente da `database/schema.sql` al primo avvio.
Il servizio `scheduler` esegue `bin/scheduler.php` ogni minuto e avvia solo
le operazioni abilitate nella pagina Impostazioni. `/newlog` resta disattivato
per impostazione predefinita. Stato, progetti, log, magazzino, residui e
versione sono configurati con intervallo predefinito di un minuto.

## Avvio

```powershell
docker compose -f compose.test.yml up -d --build
```

Apri <http://localhost:8080>. MariaDB è raggiungibile dalla macchina host
sulla porta `3307` con database `jdev_industry`, utente `jdev_test` e password
`jdev_test`.

Le credenziali sono esclusivamente locali e non vanno usate in produzione.

## Controlli

```powershell
docker compose -f compose.test.yml ps
docker compose -f compose.test.yml logs --tail=100 web db scheduler
```

Risultato atteso:

- `db` è `healthy`;
- `web` è raggiungibile su <http://localhost:8080>;
- `scheduler` resta attivo e registra un esito per ogni operazione in scadenza;
- `/newlog` non viene eseguito finché non viene abilitato esplicitamente.

Per seguire lo scheduler in tempo reale:

```powershell
docker compose -f compose.test.yml logs -f scheduler
```

## Aggiornamento

```powershell
git pull --ff-only
docker compose -f compose.test.yml up -d --build
```

Il comando ricrea i container quando necessario e mantiene il volume MariaDB.
Se il nuovo codice aggiunge una migrazione, applica in ordine gli script non
ancora eseguiti presenti in `database/migrations/`.

## Arresto

```powershell
docker compose -f compose.test.yml down
```

Per eliminare anche il database di test e ripartire da uno schema vuoto:

```powershell
docker compose -f compose.test.yml down -v
```
