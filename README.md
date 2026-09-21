# JDEV Industry

Gestionale minimale in **PHP + MySQL/MariaDB** per una o più macchine Essetre/Tecnoessetre interconnesse tramite le Web API Industria 4.0.

La base funzionale deriva dalla specifica tecnica Tecnoessetre Rev. 1.0 del 15/07/2026. Il documento è una bozza tecnica: endpoint, nomi proprietà e payload vanno verificati sulla versione del supervisore effettivamente installata.

## Funzioni

L'interfaccia resta volutamente semplice e senza login:

- Dashboard multi-macchina con stato live e avanzamento via AJAX.
- Pulsante **Stato** per ogni macchina con popup di sincronizzazione, versione supervisore, ultimo contatto, scheduler, ultimo successo/errore e prossima esecuzione per ogni operazione automatica.
- Monitoraggio dedicato di stato, segnalazioni, attività oraria, progetto, commessa e cliente.
- Clienti e commesse con ricerca logica, filtri e collegamenti rapidi.
- Tariffario configurabile per ora macchina, taglio completato, schema completato e quota fissa per commessa.
- Consuntivo economico della singola lavorazione con materiali, extra, sconti e consolidamento del costo.
- Report per cliente, mese, anno o intervallo personalizzato con ore, tagli, schemi, scarto e costi.
- Stampe A4 della singola lavorazione e dei report, con logo e dati aziendali configurabili.
- Invio BTL, BTL con conversione e TS7 direttamente dalla commessa.
- **Attività recenti** tramite `/log`, con archiviazione locale.
- Acquisizione incrementale manuale o schedulata tramite `/newlog`.
- Storico per data tramite `/logDate/{YYYYMMDD}`.
- Stato storico macchina tramite `/state/{YYYYMMDD}`.
- Archivio eventi locale con deduplicazione e correlazione automatica alla commessa tramite `Project`.
- Magazzino, residui e importazione archivio magazzino.
- Scheduler CLI configurabile per macchina.
- Pagina **Test API** con risposta grezza, HTTP status, tempi e URL.

## API integrate

| Metodo | Endpoint | Funzione gestionale |
|---|---|---|
| GET | `/version` | Test connessione, popup stato e scheduler |
| GET | `/state` | Dashboard, monitor e scheduler |
| GET | `/state/{YYYYMMDD}` | Stato storico nella pagina Storico lavori |
| GET | `/project/last10` | Avanzamento e progetto corrente |
| GET | `/log` | Attività recenti + archiviazione/scheduler |
| GET | `/logDate/{YYYYMMDD}` | Storico produzione giornaliero |
| GET | `/newlog` | Sincronizzazione incrementale manuale/schedulata |
| GET | `/warehouse` | Magazzino live + scheduler |
| GET | `/recovery` | Residui live + scheduler |
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

## Installazione nuova

1. Clona il repository.
2. Crea il database importando `database/schema.sql`.
3. Copia `config.example.php` in `config.php` e imposta le credenziali MySQL, oppure usa le variabili ambiente.
4. Imposta il document root su `public/`.
5. Apri **Impostazioni**, aggiungi la macchina con URL tipo `http://192.168.1.100:8030`.
6. Premi **Verifica /version**.
7. Configura la pianificazione automatica.
8. Usa **Test API** per verificare i payload reali della versione installata.

Esempio rapido con PHP integrato:

```bash
php -S 0.0.0.0:8080 -t public
```

## Aggiornamento di un database già creato

Esegui in ordine le migrazioni non ancora applicate:

```bash
mysql -u USER -p jdev_industry < database/migrations/002_automation.sql
mysql -u USER -p jdev_industry < database/migrations/003_economics.sql
```

La migrazione 003 aggiunge tariffario, dati aziendali per la stampa, voci economiche manuali e snapshot del costo. Non modifica clienti, commesse o storico esistenti.

Per il caricamento del logo il processo PHP deve poter scrivere nella directory `public/uploads/`.

## Scheduler automatico

Il file:

```text
bin/scheduler.php
```

è un runner CLI. Va richiamato periodicamente dal sistema operativo; è lui a verificare quali operazioni sono effettivamente in scadenza.

Cron Linux consigliato, ogni minuto:

```cron
* * * * * /usr/bin/php /percorso/jdev-industry/bin/scheduler.php >> /var/log/jdev-industry-scheduler.log 2>&1
```

La frequenza del cron **non corrisponde alla frequenza delle API**: in **Impostazioni → Pianificazione automatica** puoi definire per ogni macchina l'intervallo reale di ciascuna operazione.

Default:

| Operazione | Default |
|---|---:|
| Stato macchina `/state` | 1 minuto |
| Ultimi progetti `/project/last10` | 1 minuto |
| Log corrente `/log` | 10 minuti |
| Nuovi eventi `/newlog` | disattivato |
| Magazzino `/warehouse` | 60 minuti |
| Residui `/recovery` | 60 minuti |
| Versione `/version` | 1440 minuti |

Lo scheduler utilizza un lock MySQL per evitare due esecuzioni contemporanee.

### Nota su /newlog

`/newlog` è **disattivato di default** perché la specifica lo indica per un unico interlocutore. Va attivato automaticamente solo quando è certo che nessun altro MES/ERP stia consumando lo stesso flusso.

Il normale `/log` resta attivo come sincronizzazione sicura: gli eventi vengono deduplicati localmente mediante `Guid` oppure, in assenza di GUID, tramite hash del payload.

## Stato sincronizzazione

Dalla Dashboard il pulsante **Stato** esegue una verifica immediata di `/version` e `/state`, poi mostra:

- URL macchina;
- versione supervisore;
- ultimo contatto riuscito;
- ultima esecuzione dello scheduler;
- operazioni automatiche abilitate/disabilitate;
- ultimo successo;
- prossimo tentativo;
- ultimo errore o messaggio;
- quantità di record acquisiti quando applicabile.

Le informazioni persistono in MySQL e sono quindi consultabili anche dopo errori temporanei della macchina.

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

Il polling dashboard/monitor parte da 5 secondi per default, configurabile per macchina, con backoff fino a 30 secondi dopo errori. Lo scheduler ha una frequenza indipendente e configurabile.

La proprietà di stato è letta sia come `Conneted` sia come `Connected`, perché la specifica segnala esplicitamente l'incongruenza.

Il parser di `/project/last10` usa un adattatore prudente per alcuni nomi campo comuni. Finché non abbiamo il payload reale, la pagina Monitoraggio mostra anche il JSON grezzo; una volta raccolta una risposta reale conviene rendere il mapping deterministico.

Gli eventi `CUT_COMPLETED` e `BIN_COMPLETED` collegati a una commessa possono portare automaticamente una commessa pianificata/pronta/inviata allo stato **In lavorazione**. Non viene marcata automaticamente come completata perché la specifica non documenta un evento certo di fine progetto/commessa.

## Modulo economico e stampe

Il calcolo automatico usa soltanto grandezze che la specifica documenta nei log: `ElapsedTime` per il tempo macchina, `CUT_COMPLETED` per i tagli e `BIN_COMPLETED` per gli schemi. Le regole possono essere globali, specifiche per macchina e opzionalmente limitate a un valore `Material`.

Il campo `Material` identifica il materiale lavorato, ma le API non forniscono il relativo prezzo di acquisto. Per questo materiali, utensili, trasporto, lavorazioni esterne, extra e sconti vengono gestiti come voci economiche manuali nella commessa. In questo modo il software non assume unità di misura o costi che non siano stati verificati sulla macchina reale.

Il pulsante **Consolida costo attuale** salva una fotografia del calcolo economico della commessa. La stampa consolidata continua quindi a mostrare quel valore anche dopo successive modifiche al tariffario.

Le stampe utilizzano il normale motore di stampa del browser e possono essere stampate su carta o salvate come PDF.

## Sicurezza

Il gestionale è volutamente **senza login**, come richiesto. Non deve quindi essere pubblicato su Internet: va limitato alla rete aziendale o a una VLAN/segmento autorizzato.

Anche il servizio Tecnoessetre sulla porta 8030 non deve essere esposto direttamente su reti pubbliche. Limitare firewall e routing ai client strettamente necessari.

## Collaudo consigliato

Prima della produzione:

1. `/version`
2. `/state`
3. `/project/last10`
4. `/log`
5. `/logDate/{YYYYMMDD}`
6. `/state/{YYYYMMDD}`
7. upload di un BTL/TS7 di test
8. `/warehouse` e `/recovery`
9. eventuale `/importWarehouse`
10. solo se applicabile, `/newlog`

Per analizzare differenze di versione, copia direttamente la risposta dalla pagina **Test API**.
