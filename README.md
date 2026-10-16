# hautoo dist-bridge

Kleines Hilfsprojekt für Sites aus dem [hautuu](https://github.com/platomat/hautoo)-Template (oder Fork): Cloudflare Pages (oder lokal) baut die statische Site; ein Script auf dem **externen Host** holt das Build-Artefakt und legt es live.

Kein Secret gehört in dieses öffentliche Repo. Tokens und URLs nur auf dem Server (ENV oder lokale Config).

## Worum es geht

```text
GitHub (hautuu-Template)
    → Cloudflare Pages Build  (optional SITE=https://deine-domain.de)
    → Artefakt: ZIP der dist/
    → feste HTTPS-URL (oft: ZIP mit auf der Build-Site ausliefern)

Externer Host (Apache/Nginx/Shared Hosting)
    → CLI: php bin/dist-bridge.php
    → lädt ZIP, entpackt nach Staging, tauscht Webroot
    → Site live
```

Passt zu der hautuu-Doku [Externes Hosting](https://github.com/platomat/hautoo/blob/main/docs/hosting/README.md): `dist/` ist portable; `_redirects`/`_headers` bleiben Cloudflare-spezifisch (Apache/Nginx nutzen `.htaccess` / `nginx-redirects.conf` aus dem Build).

### ZIP auf der Build-Site mitausliefern (empfohlen, einfach)

Cloudflare Pages (oder ein anderer Build-Host) liefert die Site ohnehin per HTTPS aus. Die ZIP kann **derselbe Deploy** mitbringen:

1. `npm run build` → `dist/`
2. ZIP aus `dist/` packen (**ohne** die ZIP selbst hineinzupacken)
3. ZIP **danach** nach `dist/dist-bridge.zip` legen
4. Deploy von `dist/` → URL z. B. `https://projekt.pages.dev/dist-bridge.zip`
5. Fremdhost zieht genau diese URL

Schalter dafür: Cloudflare Pages Environment Variable **`HAUTOO_DIST_BRIDGE=1`**. Dann packt `pack-dist.sh` nach dem Build und legt die ZIP ins `dist/` (Mitauslieferung). Ohne die Variable (oder `0`) passiert beim Pack-Aufruf nichts Weiteres für die Bridge.

So braucht es kein R2 und kein separates Release. Deploy wird grob doppelt so groß (Site + ZIP der Site); für die meisten hautuu-Sites unkritisch.

**Nicht** die ZIP fest unter `public/` im Repo ablegen: Astro kopiert `public/` unverändert nach `dist/`. Dort läge dann immer ein **alter** Stand, nicht der aktuelle Build. Sinnvoll ist nur: **nach** dem Packen in das frische `dist/` legen (`HAUTOO_DIST_BRIDGE=1`).

Alternativen zur Mitauslieferung: R2, GitHub Release, eigener Download-Host. Nützlich bei Auth am Artefakt, sehr großen Sites oder wenn die Build-Domain die ZIP nicht öffentlich spiegeln soll.

## Spezifikation

### Ziele

| Ziel | Detail |
| --- | --- |
| Pull-Deploy | Der Fremdhost **holt** das Artefakt (kein Push von Cloudflare in den Kunden-Account nötig). |
| CLI zuerst | Ausführung per SSH/Terminal; kein öffentlicher Web-Trigger in v1. |
| Keine Secrets im Git | Nur Beispiele; echte Werte lokal/ENV. |
| Wiederverwendbar | Jedes hautuu-basierte Projekt kann denselben Ablauf nutzen. |

### Nicht-Ziele (v1)

- Kein automatischer Webhook von Cloudflare Pages (kann später mit Secret ergänzt werden).
- Kein Upload in fremde FTP-Accounts von CF aus.
- Kein Ersatz für `SITE` beim Build (Domain weiter beim Astro-Build setzen).

### Artefakt

- Inhalt: Inhalt von Astro `dist/` (HTML, Assets, `/admin/`, `.htaccess`, …), **ohne** die Download-ZIP selbst.
- Format: **ZIP**.
- Erzeugung: nach `npm run build`, siehe [`scripts/pack-dist.sh`](scripts/pack-dist.sh).
- Ablage: **feste HTTPS-URL** (Config/ENV), die das Pull-Script kennt. Typisch: `…/dist-bridge.zip` auf der Build-Site nach Mitauslieferung; sonst R2/Release/o. Ä. Keine freie Download-URL vom Aufrufer.

### Sicherheit (v1)

| Thema | Haltung |
| --- | --- |
| ZIP öffentlich lesbar | Inhalt ≈ öffentliche Site (inkl. `/admin/`-UI). Trotzdem URL nicht unnötig streuen. |
| Pull nur per CLI | Kein „jeder mit Trigger-URL deployt“. |
| Download-Auth | Optional: `DIST_BRIDGE_TOKEN` als Bearer beim Holen der ZIP-URL (wenn der Speicher Auth verlangt). |
| Feste ZIP-URL | Script akzeptiert **keine** freie Download-URL vom Aufrufer → kein „lad mal evil.zip“. |
| Staging + Tausch | Entpacken neben dem Webroot, dann ersetzen; kein blindes Entpacken über Path-Traversal in den Live-Baum hinein (Zip-Slip-Schutz). |

### Ablauf auf dem Fremdhost

1. Config: `config.local.php` (von `config.example.php`) oder ENV.
2. `php bin/dist-bridge.php`
3. Script: ZIP laden → prüfen → nach Staging entpacken → Webroot aktualisieren → Aufräumen.

## Schnellstart

### A) Build-Seite (Cloudflare / CI / lokal)

In Cloudflare Pages (Settings → Environment variables):

| Variable | Wert | Bedeutung |
| --- | --- | --- |
| `HAUTOO_DIST_BRIDGE` | `1` | Nach dem Build ZIP erzeugen und als `dist/dist-bridge.zip` mitdeployen |

Build-Befehl (Script liegt im hautuu-Projekt oder wird aus diesem Repo geholt):

```bash
npm run build && bash scripts/pack-dist.sh .
```

`pack-dist.sh` prüft `HAUTOO_DIST_BRIDGE`: bei `1` / `true` / `yes` packt es `dist/` und legt `dist/dist-bridge.zip` bereit; sonst Exit 0 ohne ZIP (kein Fehler).

Lokal zum Testen:

```bash
SITE=https://www.beispiel.de npm run build
HAUTOO_DIST_BRIDGE=1 ./scripts/pack-dist.sh /pfad/zum/hautuu-projekt
```

Nach dem Pages-Deploy: z. B. `https://projekt.pages.dev/dist-bridge.zip` → das ist die `DIST_BRIDGE_ZIP_URL` auf dem Fremdhost.

Ohne Mitauslieferung: Variable weglassen oder `0`; ZIP ggf. nach R2/Release legen (Credentials nur in CF Environment Variables, nicht im Git).

### B) Fremdhost

```bash
git clone https://github.com/platomat/hautoo-dist-brigde.git
cd hautoo-dist-brigde
cp config.example.php config.local.php
# config.local.php anpassen (ZIP-URL, Zielverzeichnis, optional Token)

php bin/dist-bridge.php
```

Oder per ENV (ohne `config.local.php`):

```bash
export DIST_BRIDGE_ZIP_URL='https://beispiel.de/releases/site.zip'
export DIST_BRIDGE_TARGET_DIR='/var/www/beispiel.de/httpdocs'
# optional:
# export DIST_BRIDGE_TOKEN='…'
php bin/dist-bridge.php
```

## Konfiguration

### Build (Cloudflare / CI)

| Variable | Pflicht | Bedeutung |
| --- | --- | --- |
| `HAUTOO_DIST_BRIDGE` | nein | `1`: ZIP packen und nach `dist/dist-bridge.zip` legen. Ohne/`0`: `pack-dist.sh` macht nichts. |

Optionale Overrides fürs Pack-Script: `DIST_BRIDGE_ZIP_OUT`, `DIST_BRIDGE_ZIP_NAME` (Default `dist-bridge.zip`).

### Fremdhost (Pull)

| Variable / Key | Pflicht | Bedeutung |
| --- | --- | --- |
| `DIST_BRIDGE_ZIP_URL` / `zipUrl` | ja | HTTPS-URL zum ZIP |
| `DIST_BRIDGE_TARGET_DIR` / `targetDir` | ja | Absoluter Pfad zum Webroot (Docroot) |
| `DIST_BRIDGE_TOKEN` / `token` | nein | `Authorization: Bearer …` beim Download |
| `DIST_BRIDGE_KEEP_BACKUP` / `keepBackup` | nein | `1`/`true`: vorherigen Webroot nach `*.dist-bridge.bak` sichern (Default an) |
| `DIST_BRIDGE_WORKDIR` / `workDir` | nein | Arbeitsverzeichnis für Download/Staging (Default: sys temp) |

`config.local.php` steht in `.gitignore`.

## PHP-Voraussetzungen

- PHP CLI **8.1+** (empfohlen)
- Extensions: `curl` oder `allow_url_fopen`, `zip` (`ZipArchive`)

## Lizenz

Wie hautuu: frei nutzbar mit Namensnennung der Quelle (dieses Repo bzw. hautuu). Keine Gewähr.
