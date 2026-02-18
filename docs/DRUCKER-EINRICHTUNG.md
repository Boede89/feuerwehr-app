# Drucker-Einrichtung – Workplace Pure (IPP Cloud)

## Übersicht

Ihr Freigabelink: `https://ipp.workplacepure.com/ipp/print/b976ec50-c0c3-40e7-85b3-46720271cb69/14619`

Der Drucker muss in CUPS angelegt werden. Da die App in einem Docker-Container läuft, gibt es zwei Wege:

---

## Option A: Drucker auf dem Host (Linux-Server) anlegen

### Schritt 1: CUPS auf dem Host installieren (falls noch nicht vorhanden)

```bash
sudo apt update
sudo apt install cups cups-client
sudo systemctl enable cups
sudo systemctl start cups
```

### Schritt 2: Drucker in CUPS anlegen

Der Freigabelink nutzt HTTPS. Für IPP mit TLS verwenden Sie `ipps://`:

```bash
sudo lpadmin -p workplacepure \
  -E \
  -v "ipps://ipp.workplacepure.com/ipp/print/b976ec50-c0c3-40e7-85b3-46720271cb69/14619" \
  -m everywhere
```

**Falls Benutzername und Passwort nötig sind** (von Workplace Pure):

```bash
sudo lpadmin -p workplacepure \
  -E \
  -v "ipps://BENUTZERNAME:PASSWORT@ipp.workplacepure.com/ipp/print/b976ec50-c0c3-40e7-85b3-46720271cb69/14619" \
  -m everywhere
```

(Ersetzen Sie `BENUTZERNAME` und `PASSWORT` durch Ihre Zugangsdaten.)

### Schritt 3: Drucker prüfen

```bash
lpstat -p
```

Sie sollten `printer workplacepure` sehen.

### Schritt 4: CUPS für Docker – Socket (empfohlen) oder Netzwerk

**Option A: Socket-Passthrough (empfohlen, kein Forbidden)**

Die docker-compose nutzt bereits den CUPS-Socket vom Host. Keine cupsd.conf-Änderungen nötig. Voraussetzung: CUPS läuft auf dem Host, der Socket existiert unter `/run/cups/cups.sock`.

**Option B: Netzwerk-Zugriff (falls Socket nicht möglich)**

```bash
# 1. CUPS auf allen Interfaces hören lassen
sudo sed -i 's/Listen localhost:631/Listen 0.0.0.0:631/' /etc/cups/cupsd.conf

# 2. In /etc/cups/cupsd.conf: ServerAlias * und Allow from 172.17.0.0/16 im <Location />
sudo nano /etc/cups/cupsd.conf

sudo systemctl restart cups
```

Dann in docker-compose `CUPS_SERVER=172.17.0.1` setzen und den Socket-Volume-Eintrag auskommentieren.

### Schritt 5: docker-compose (bereits vorkonfiguriert)

Die `docker-compose.yml` enthält bereits:
- Volume: `/run/cups/cups.sock` vom Host
- Environment: `CUPS_SERVER=/run/cups/cups.sock`

Keine Änderung nötig, wenn Sie den Socket nutzen.

### Schritt 6: Container neu bauen und starten

```bash
cd ~/feuerwehr-app
git pull
docker compose build web
docker compose down
docker compose up -d
```

**Hinweis:** `build` ist nötig, da www-data für den CUPS-Socket-Zugriff der Gruppe `lp` hinzugefügt wird.

### Schritt 7: Einstellungen in der App

1. **Admin** → **Globale Einstellungen**
2. Unter **Drucker**:
   - **Druckertyp:** Lokaler Drucker (CUPS)
   - **CUPS-Server (Docker):** Leer lassen bei Socket-Nutzung. Bei Netzwerk: `172.17.0.1` eintragen.
   - **Druckername:** `workplacepure` (genau wie bei lpadmin)
3. Optional: **Verfügbare Drucker** klicken – `workplacepure` sollte erscheinen
4. **Speichern**

---

## Option B: Drucker direkt im Container anlegen

Falls CUPS auf dem Host nicht genutzt werden soll:

### Schritt 1: CUPS im Container installieren

Im `Dockerfile` zusätzlich `cups` (nicht nur `cups-client`) installieren und den CUPS-Dienst starten. Das ist aufwändiger und wird hier nicht im Detail beschrieben.

**Empfehlung:** Option A nutzen.

---

## CUPS automatisch beim Host-Neustart starten

CUPS läuft auf dem **Host**, nicht im Container. Damit CUPS beim Neustart des Hosts automatisch startet:

**Option 1: Setup-Skript (empfohlen)** – einmalig auf dem Host ausführen:

```bash
sudo bash docker/host-setup-cups.sh
```

**Option 2: Manuell** – falls CUPS bereits installiert ist:

```bash
sudo systemctl enable cups
sudo systemctl start cups
```

Danach startet CUPS bei jedem Host-Boot automatisch. Der Container-Neustart hat keinen Einfluss auf CUPS – der Dienst muss auf dem Host laufen.

---

## Fehlerbehebung

### „Scheduler is not running“ / CUPS läuft nicht

- CUPS-Dienst auf dem Host starten: `sudo systemctl start cups`
- **CUPS läuft auf dem Host, Fehler bleibt im Container?** Apache muss `CUPS_SERVER` an PHP weitergeben. Die Apache-Konfiguration enthält `PassEnv CUPS_SERVER`. Nach Änderungen: `docker compose up -d --force-recreate web` ausführen.
- Für automatischen Start beim Host-Neustart: `sudo systemctl enable cups`
- Prüfen: `systemctl status cups` – Status sollte „active (running)“ sein
- **CUPS startet nicht?** Fehler prüfen: `journalctl -u cups -n 30 --no-pager`
- **Windows-Host?** Docker auf Windows hat keinen nativen CUPS. Optionen: Linux-VM als Host, WSL2 mit CUPS, oder separater CUPS-Container
- **Druckername manuell eintragen:** Ohne „Verfügbare Drucker“ klicken – wenn Sie den Druckernamen kennen (z.B. von `lpstat -p` auf dem Host), tragen Sie ihn direkt ein

### „The printer or class does not exist“

- Drucker in CUPS anlegen (Schritt 2)
- Druckername in den Einstellungen exakt so eintragen wie bei `lpadmin -p`
- **CUPS-Server (Docker)** in den globalen Einstellungen setzen: `172.17.0.1` oder die Host-IP. Damit wird der Host-CUPS explizit angesprochen.
- Test im Container: `docker compose exec web sh -c 'CUPS_SERVER=172.17.0.1 lpstat -p'` – sollten Drucker erscheinen

### „lpstat: Forbidden“ / Zugriff verweigert

- CUPS blockiert den Zugriff vom Container (Host-Header oder IP). In `/etc/cups/cupsd.conf`:
  1. **ServerAlias \*** einfügen (z.B. nach der ersten Zeile) – erlaubt Anfragen mit Host 172.17.0.1
  2. Im Abschnitt `<Location />` die Zeile **Allow from 172.17.0.0/16** ergänzen
- Dann: `sudo systemctl restart cups`

### „Unable to connect“ / „Connection refused“

- `CUPS_SERVER` in docker-compose prüfen oder **CUPS-Server** in den App-Einstellungen setzen
- CUPS auf dem Host läuft: `systemctl status cups`
- CUPS hört auf 0.0.0.0:631 (siehe Schritt 4)

### Drucker druckt nicht

- Testdruck vom Host: `echo "Test" | lp -d workplacepure`
- Logs prüfen: `sudo tail -f /var/log/cups/error_log`

---

## Kurz-Checkliste

- [ ] CUPS auf dem Host installiert und gestartet (`/run/cups/cups.sock` existiert)
- [ ] CUPS für Auto-Start aktiviert: `sudo systemctl enable cups` (damit CUPS beim Host-Neustart startet)
- [ ] Drucker mit `lpadmin` angelegt (Name: `workplacepure`)
- [ ] `lpstat -p` auf dem Host zeigt den Drucker
- [ ] docker-compose mit CUPS-Socket-Volume (bereits vorkonfiguriert)
- [ ] Container neu gebaut und gestartet: `docker compose build web && docker compose up -d`
- [ ] In der App: Druckername `workplacepure`, CUPS-Server leer lassen
