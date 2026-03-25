# Turnier.de-Ergebnisse

WordPress-Plugin zum automatischen Anzeigen von Badminton-Spielergebnissen und Tabellenstaenden von [dbv.turnier.de](https://dbv.turnier.de).

## Features

- **Tabellenstand** und **Spielergebnisse** automatisch von turnier.de laden
- **Einfache Einrichtung**: Nur die Team-URL eintragen - der Rest passiert automatisch
- **Mehrere Mannschaften** verwaltbar ueber die Admin-Einstellungsseite
- **Automatisches Caching** mit konfigurierbarer Dauer (Standard: 6 Stunden)
- **Cookie-Wall-Bypass**: Umgeht beide Cookie-Dialoge (DBV + turnier.de) automatisch
- **Responsive Design**: Mobile-optimiert, Spielort-Spalte wird auf kleinen Bildschirmen ausgeblendet
- **Theme-kompatibel**: Schriftart und Header-Farbe werden vom WordPress-Theme uebernommen
- **Hervorhebungen**:
  - Eigene Mannschaft in der Tabelle farblich hervorgehoben (blau/gold)
  - Aufstiegs- und Abstiegsplaetze farblich markiert
  - Siege (gruen), Niederlagen (rot), Unentschieden (gelb)
  - Heimspiele mit goldenem Rand

## Installation

1. Den Ordner `osc-badminton-ergebnisse` in `wp-content/plugins/` kopieren
2. Im WordPress-Admin unter **Plugins** das Plugin **OSC Badminton Ergebnisse** aktivieren
3. Unter **Einstellungen > Badminton Ergebnisse** die Mannschaft(en) konfigurieren

## Konfiguration

### Mannschaft hinzufuegen

1. Navigiere zu **Einstellungen > Badminton Ergebnisse**
2. Trage einen **Namen** ein (z.B. "OSC BG Essen-Werden 1")
3. Trage die **Team-URL** von turnier.de ein

Die Team-URL findest du auf turnier.de unter der jeweiligen Mannschaftsseite. Sie hat folgendes Format:

```
https://dbv.turnier.de/sport/league/team?id=XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX&team=XX
```

Alternativ wird auch die direkte Matches-URL unterstuetzt:

```
https://dbv.turnier.de/sport/teammatches.aspx?id=XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX&tid=XX
```

> **Hinweis:** Bei Verwendung der Team-URL werden sowohl Tabellenstand als auch Spielergebnisse geladen. Bei der Matches-URL nur die Spielergebnisse.

### Cache-Einstellungen

- **Cache-Dauer**: Wie oft die Daten von turnier.de neu geladen werden (Standard: 6 Stunden)
- **Cache leeren**: Button zum sofortigen Leeren des Caches

## Shortcodes

### `[badminton_alle]`

Zeigt **Tabellenstand und Spielergebnisse** zusammen an.

```
[badminton_alle]           <!-- Erste Mannschaft -->
[badminton_alle id="2"]    <!-- Zweite Mannschaft -->
```

### `[badminton_tabelle]`

Zeigt nur den **Tabellenstand** an. Benoetigt eine Team-URL (nicht Matches-URL).

```
[badminton_tabelle]
[badminton_tabelle id="2"]
```

### `[badminton_ergebnisse]`

Zeigt nur die **Spielergebnisse** an. Funktioniert mit beiden URL-Typen.

```
[badminton_ergebnisse]
[badminton_ergebnisse id="2"]
```

### Direkte URL per Shortcode

Alle Shortcodes unterstuetzen auch eine direkte URL als Parameter:

```
[badminton_alle url="https://dbv.turnier.de/sport/league/team?id=...&team=..."]
```

## Dateistruktur

```
osc-badminton-ergebnisse/
  osc-badminton-ergebnisse.php   # Hauptdatei: Plugin-Header, Shortcodes, Rendering
  includes/
    class-scraper.php             # Scraping-Logik (curl + DOMDocument)
    class-admin.php               # Admin-Einstellungsseite
  assets/
    css/
      frontend.css                # Tabellen-Styling (Hervorhebungen)
      admin.css                   # Admin-Styling
```

## Technische Details

- **Scraping**: Verwendet `curl` mit Cookie-Jar fuer zuverlaessigen Cookie-Wall-Bypass
- **Parsing**: `DOMDocument` + `DOMXPath` fuer robustes HTML-Parsing
- **Caching**: WordPress Transients API
- **URL-Erkennung**: Erkennt automatisch ob eine Team-URL oder Matches-URL angegeben wurde
- **Keine externen Abhaengigkeiten**: Nur PHP-Bordmittel (curl, DOM)

## Voraussetzungen

- WordPress 5.0 oder hoeher
- PHP 7.4 oder hoeher
- PHP curl-Extension (standardmaessig aktiviert)
- PHP DOM-Extension (standardmaessig aktiviert)

## Lizenz

MIT License
