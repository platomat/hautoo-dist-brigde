# hautoo dist-bridge

Kleines Hilfsprojekt für Sites aus dem [hautuu](https://github.com/platomat/hautoo)-Template (oder Fork): Cloudflare Pages (oder lokal) baut die statische Site; **ein** Clone dieses Repos auf dem Fremdhost bedient beliebig viele Projekte über Configs.

Kein Secret gehört in dieses öffentliche Repo. Tokens und Pfade nur in `config/*.local.php` (gitignored) oder ENV.

### HTTP-Absicherung

Dieses Repo ist **nur CLI** (SSH). Ideal: kein DocumentRoot darauf. Zeigt trotzdem eine Domain hierhin, liefert Apache nichts aus:

- Root-`.htaccess`: `Require all denied` (kein Index, kein Git, keine Dateien)
- Zusätzlich `.htaccess` in `config/`, `backup/`, `lib/`, `bin/`, `scripts/`
- Fallback `index.php` → HTTP 403, falls `.htaccess` ignoriert wird
- Nginx: siehe `nginx-deny.conf.example`

Voraussetzung Apache: `AllowOverride` greift für diesen Pfad.

## Worum es geht

```text
GitHub (hautuu-Template)
    → Cloudflare Pages Build  (optional SITE=…, HAUTOO_DIST_BRIDGE=1)
    → Artefakt: dist/dist-bridge.zip mitdeployen

Externer Host (eine Installation)
    → ./hdb.sh init                 Config pro Projekt
    → ./hdb.sh check requirements
    → ./hdb.sh check project beispiel.de
    → ./hdb.sh pull beispiel.de      ZIP holen → Webroot live
```

Passt zur hautuu-Doku [Externes Hosting](https://github.com/platomat/hautoo/blob/main/docs/hosting/README.md).

### ZIP auf der Build-Site mitausliefern (empfohlen)

1. `npm run build` → `dist/`
2. Mit `HAUTOO_DIST_BRIDGE=1` packt `scripts/pack-dist.sh` und legt `dist/dist-bridge.zip`
3. Deploy → z. B. `https://projekt.pages.dev/dist-bridge.zip`
4. Fremdhost-Config: Basis-URL `https://projekt.pages.dev` (ZIP-Name wird angehängt) oder volle ZIP-URL

**Nicht** die ZIP unter `public/` im Repo ablegen (alter Stand). Alternativen: R2, GitHub Release.

## Fremdhost: ein Repo, viele Projekte

| Pfad | Bedeutung |
| --- | --- |
| `config/beispiel.de.local.php` | Config für Projekt-Id `beispiel.de` |
| `backup/beispiel.de/` | ZIP-Archive der letzten Pulls |
| `./hdb.sh` / `bin/hdb.php` | Kurzbefehl |
| `bin/hautoo-dist-bridge.php` | gleicher CLI, langer Name |

```bash
./hdb.sh init
./hdb.sh list
./hdb.sh check requirements
./hdb.sh check project beispiel.de
./hdb.sh pull beispiel.de
# gleichwertig:
php bin/hautoo-dist-bridge.php beispiel.de
```

### Interaktives `init`

1. **Projekt-Name** (z. B. `beispiel.de`) → `config/beispiel.de.local.php`
2. **ZIP-URL oder Basis-URL** (ohne `.zip` → `/dist-bridge.zip` anhängen)
3. **Zielverzeichnis** (absoluter Webroot)
4. **Keep-Backups** (Enter = `1`): wie viele ZIPs unter `backup/<projekt>/` behalten
5. Optional Bearer-Token

### Backups

Nach jedem erfolgreichen Download (vor dem Webroot-Tausch) landet eine Kopie hier:

```text
backup/<projekt>/yyyy-mm-dd--hh-mm-ss--dist-bridge.zip
```

Beispiel: `backup/beispiel.de/2026-10-16--23-40-01--dist-bridge.zip`

`keepBackups` begrenzt die Anzahl (älteste werden gelöscht). `0` = keine ZIP-Backups. Der alte Webroot wird beim Tausch verworfen; die Historie steckt in diesen ZIPs.

### Checks

```bash
./hdb.sh check requirements   # PHP >= 8.1, zip, curl/fopen, Schreibrechte
./hdb.sh check project beispiel.de   # Config, ZIP erreichbar, Zielpfad, lokale Backups
```

### Pull

```bash
./hdb.sh pull beispiel.de
```

Lädt die ZIP (HTTPS), sichert sie unter `backup/`, entpackt nach Staging (Zip-Slip-Schutz), tauscht den Webroot.

## Build-Seite (Cloudflare)

| Variable | Wert | Bedeutung |
| --- | --- | --- |
| `SITE` | `https://www.beispiel.de` | Kanonische Site-URL für den Astro-Build (Sitemap, absolute Links). Bei Fremdhost = **Live-Domain**, nicht die `*.pages.dev`-URL. |
| `HAUTOO_DIST_BRIDGE` | `1` | Nach dem Build ZIP erzeugen und als `dist/dist-bridge.zip` mitdeployen |

```bash
npm run build && bash scripts/pack-dist.sh .
```

`SITE` kann auch nur für den Build gesetzt werden (`SITE=https://www.beispiel.de npm run build`). Ohne `HAUTOO_DIST_BRIDGE=1`: `pack-dist.sh` macht nichts (Exit 0).

## Spezifikation (kurz)

| Ziel | Detail |
| --- | --- |
| Pull-Deploy | Fremdhost holt das Artefakt |
| Multi-Projekt | Eine Installation, viele `*.local.php` |
| CLI zuerst | Kein öffentlicher Web-Trigger in v1 |
| Keine Secrets im Git | Nur `config/example.php` |

ZIP-Inhalt = Astro `dist/` **ohne** die Download-ZIP selbst. Feste URL pro Projekt-Config (keine freie Download-URL vom Aufrufer).

## Konfiguration

### Build

| Variable | Bedeutung |
| --- | --- |
| `HAUTOO_DIST_BRIDGE` | `1` = packen + in `dist/` legen |

### Fremdhost (pro Projekt in `*.local.php` oder ENV)

| Key / ENV | Bedeutung |
| --- | --- |
| `zipUrl` / `DIST_BRIDGE_ZIP_URL` | HTTPS-ZIP oder Basis-URL |
| `targetDir` / `DIST_BRIDGE_TARGET_DIR` | Webroot |
| `token` / `DIST_BRIDGE_TOKEN` | optional Bearer |
| `keepBackups` / `DIST_BRIDGE_KEEP_BACKUPS` | Anzahl ZIP-Backups unter `backup/<projekt>/` (Default 1) |
| `workDir` / `DIST_BRIDGE_WORKDIR` | optional Arbeitsverzeichnis |

## PHP

- PHP CLI **8.1+**
- Extensions: `curl` (bevorzugt), `zip` (`ZipArchive`)

## Lizenz

Wie hautuu: frei nutzbar mit Namensnennung. Keine Gewähr.
