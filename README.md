# hautoo dist-bridge

*(Repo-Slug: `hautoo-dist-brigde`. Gemeint ist die **Dist-Brücke** / Fremdhost-Brücke.)*

Kleines Hilfsprojekt für Sites aus dem [hautuu](https://github.com/platomat/hautoo)-Template (oder Fork): Cloudflare Pages (oder lokal) baut die statische Site; ein Script auf dem **externen Host** holt das Build-Artefakt und legt es live.

Kein Secret gehört in dieses öffentliche Repo. Tokens und URLs nur auf dem Server (ENV oder lokale Config).

## Worum es geht

```text
GitHub (hautuu-Template)
    → Cloudflare Pages Build  (optional SITE=https://deine-domain.de)
    → Artefakt: ZIP der dist/
    → Ablage unter fester HTTPS-URL (R2, Pages-Asset, Release, …)

Externer Host (Apache/Nginx/Shared Hosting)
    → CLI: php bin/dist-bridge.php
    → lädt ZIP, entpackt nach Staging, tauscht Webroot
    → Site live
```

Passt zu der hautuu-Doku [Externes Hosting](https://github.com/platomat/hautoo/blob/main/docs/hosting/README.md): `dist/` ist portable; `_redirects`/`_headers` bleiben Cloudflare-spezifisch (Apache/Nginx nutzen `.htaccess` / `nginx-redirects.conf` aus dem Build).

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

- Inhalt: Inhalt von Astro `dist/` (HTML, Assets, `/admin/`, `.htaccess`, …).
- Format: **ZIP**.
- Erzeugung: nach `npm run build`, siehe [`scripts/pack-dist.sh`](scripts/pack-dist.sh).
- Ablage: eine **feste URL**, die das Pull-Script kennt (Config/ENV), keine URL aus CLI-Argumenten für beliebige Fremdquellen.

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

```bash
npm run build
# im hautuu-Projekt, ggf.:
# SITE=https://www.beispiel.de npm run build

# dieses Repo klonen oder pack-dist.sh kopieren:
./scripts/pack-dist.sh /pfad/zum/hautuu-projekt
# → schreibt dist-bridge.zip (oder DIST_BRIDGE_ZIP_OUT=…)
```

ZIP an eine HTTPS-URL legen, die der Server erreichen kann.

In Cloudflare Pages kannst du den Build erweitern, z. B.:

```bash
npm run build && bash path/to/pack-dist.sh . && # dann ZIP per Wrangler/curl nach R2 o.ä. hochladen
```

(Upload-Ziel und Credentials nur in CF Environment Variables, nicht in hautuu-Git.)

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
