# JDEV Industry

Gestionale minimale in **PHP + MySQL/MariaDB** per una o più macchine Essetre/Tecnoessetre interconnesse tramite le Web API Industria 4.0.

La base funzionale deriva dalla specifica tecnica Tecnoessetre Rev. 1.0 del 15/07/2026. Il documento è una bozza tecnica: endpoint, nomi proprietà e payload vanno verificati sulla versione del supervisore effettivamente installata.

## Obiettivo

Interfaccia molto semplice, senza login e senza workflow complessi:

- Dashboard con stato di tutte le macchine.
- Lavorazione/progetto corrente o più recente e avanzamento, quando il payload di `/project/last10` espone campi interpretabili.
- Pulsante **Monitora** con pagina dedicata, polling AJAX, stato completo, segnalazioni, attività oraria e payload grezzi.
- Clienti.
- Commesse.
- Invio file BTL / TS7 dalla commessa alla macchina.
- Storico lavori per giorno tramite `/logDate/{YYYYMMDD}`.
- Archivio locale degli eventi, collegabile alla commessa tramite `Project`.
- Magazzino, residui e importazione archivio magazzino.
- Pagina **Test API** con risposta grezza, HTTP status, tempi e URL per validare rapidamente i tracciati reali.

## API integrate

| Metodo | Endpoint | Uso |
|---|---|---|
| GET | `/version` | Compatibilità / test connessione |
| GET | `/state` | Stato corrente |
| GET | `/state/{YYYYMMDD}` | Stato storico, disponibile nel collaudo API |
| GET | `/project/last10` | Avanzamento ultimi progetti |
| GET | `/log` | Log corrente, disponibile nel collaudo API |
| GET | `/logDate/{YYYYMMDD}` | Storico produzione per giorno |
| GET | `/newlog` | Nuovi log; solo pagina test, non polling automatico |
| GET | `/warehouse` | Materie prime / barre |
| GET | `/recovery` | Residui recuperabili |
| POST | `/importBtl` | Invio BTL |
| POST | `/convertBtl` | Invio BTL con conversione |
| POST | `/importTs7` | Invio TS7 |
| POST | `/importWarehouse` | Import magazzino JSON |

Gli endpoint obsoleti `/deleteLog` e `/deleteLogDate/{YYYYMMDD}` sono volutamente esclusi dalle azioni eseguibili.

## Requisiti

- PHP 8.1+.
- Estensioni PHP: PDO MySQL, cURL, fileinfo.
- MySQL 8+ oppure MariaDB compatibile.
- Web server con document root impostata sulla directory `public/`.
- Il server del gestionale deve poter raggiungere il PC macchina sulla rete OT/LAN, tipicamente sulla porta TCP 8030.

## Installazione

1. Clona il repository.
2. Crea il database importando `database/schema.sql`.
3. Copia `config.example.php` in `config.php` e imposta le credenziali MySQL, oppure usa le variabili ambiente.
4. Imposta il document root su `public/`.
5. Apri **Impostazioni**, aggiungi la macchina con URL tipo `http://192.168.1.100:8030`.
6. Premi **Verifica /version**.
7. Usa **Test API** per acquisire i payload reali di `/state`, `/project/last10`, log e magazzino.

Esempio rapido con PHP integrato:

```bash
php -S 0.0.0.0:8080 -t public
```

## Configurazione Tecnoessetre prevista dalla specifica

Sul supervisore deve essere attivo il Web Server, con configurazione equivalente a:

```ini
[WEBSERVER]
HOST=HTTP://+:8030/
```

La specifica indica inoltre la URL reservation Windows:

```text
netsh http add urlacl url=http://+:8030/ user=Everyone
```

In un'installazione definitiva va preferito l'account di servizio realmente usato dal supervisore, secondo la configurazione Essetre.

## Scelte implementative

Il browser **non chiama direttamente la macchina**: le richieste passano dal backend PHP. In questo modo non dipendiamo da CORS e manteniamo un unico punto di rete verso l'ambiente macchina.

Il polling dashboard/monitor parte da 5 secondi per default, configurabile per macchina, con backoff fino a 30 secondi dopo errori. Non viene eseguito polling continuo senza intervallo.

La proprietà di stato è letta sia come `Conneted` sia come `Connected`, perché la specifica segnala esplicitamente l'incongruenza.

Il parser di `/project/last10` usa un adattatore prudente per alcuni nomi campo comuni. Finché non abbiamo il payload reale, la pagina Monitoraggio mostra anche il JSON grezzo; una volta raccolta una risposta reale conviene rendere il mapping deterministico.

## Sicurezza

Il gestionale è volutamente **senza login**, come richiesto. Non deve quindi essere pubblicato su Internet: va limitato alla rete aziendale o a una VLAN/segmento autorizzato.

Anche il servizio Tecnoessetre sulla porta 8030 non deve essere esposto direttamente su reti pubbliche. Limitare firewall e routing ai client strettamente necessari.

## Collaudo consigliato

Prima della produzione:

1. `/version`
2. `/state`
3. `/project/last10`
4. `/logDate/{YYYYMMDD}` su giornata nota
5. upload di un BTL/TS7 di test
6. `/warehouse` e `/recovery`
7. eventuale `/importWarehouse` con archivio non produttivo

Per analizzare differenze di versione, copia direttamente la risposta dalla pagina **Test API**.
