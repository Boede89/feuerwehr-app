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

### Schritt 4: CUPS für Docker freigeben

Damit der Container den CUPS-Server des Hosts nutzen kann:

```bash
# 1. CUPS auf allen Interfaces hören lassen
sudo sed -i 's/Listen localhost:631/Listen 0.0.0.0:631/' /etc/cups/cupsd.conf

# 2. Docker-Netzwerk Zugriff erlauben (sonst: "lpstat: Forbidden")
# In /etc/cups/cupsd.conf beim Abschnitt <Location /> ergänzen:
#   Allow from 127.0.0.1
#   Allow from 172.17.0.0/16
sudo nano /etc/cups/cupsd.conf
# Im Block <Location /> nach "Allow from 127.0.0.1" die Zeile "Allow from 172.17.0.0/16" einfügen

sudo systemctl restart cups
```

**Hinweis:** Wenn der Server nur lokal erreichbar ist, reicht das. Für Produktion ggf. Firewall-Regeln anpassen.

### Schritt 5: docker-compose anpassen

In `docker-compose.yml` beim `web`-Service:

```yaml
environment:
  - APACHE_DOCUMENT_ROOT=/var/www/html
  - CUPS_SERVER=172.17.0.1
```

`172.17.0.1` ist die Standard-IP des Docker-Hosts vom Container aus. Falls das nicht funktioniert, die tatsächliche Host-IP verwenden.

### Schritt 6: Container neu starten

```bash
cd ~/feuerwehr-app
git pull
docker compose down
docker compose up -d
```

### Schritt 7: Einstellungen in der App

1. **Admin** → **Globale Einstellungen**
2. Unter **Drucker**:
   - **Druckertyp:** Lokaler Drucker (CUPS)
   - **CUPS-Server (Docker):** `172.17.0.1` (oder Host-IP) – wichtig, damit der Container den Host-CUPS nutzt. Kann leer bleiben, wenn `CUPS_SERVER` in docker-compose gesetzt ist.
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

## Fehlerbehebung

### „The printer or class does not exist“

- Drucker in CUPS anlegen (Schritt 2)
- Druckername in den Einstellungen exakt so eintragen wie bei `lpadmin -p`
- **CUPS-Server (Docker)** in den globalen Einstellungen setzen: `172.17.0.1` oder die Host-IP. Damit wird der Host-CUPS explizit angesprochen.
- Test im Container: `docker compose exec web sh -c 'CUPS_SERVER=172.17.0.1 lpstat -p'` – sollten Drucker erscheinen

### „lpstat: Forbidden“ / Zugriff verweigert

- CUPS blockiert den Zugriff vom Container. In `/etc/cups/cupsd.conf` beim Abschnitt `<Location />` die Zeile `Allow from 172.17.0.0/16` ergänzen (nach `Allow from 127.0.0.1`), dann `sudo systemctl restart cups`.

### „Unable to connect“ / „Connection refused“

- `CUPS_SERVER` in docker-compose prüfen oder **CUPS-Server** in den App-Einstellungen setzen
- CUPS auf dem Host läuft: `systemctl status cups`
- CUPS hört auf 0.0.0.0:631 (siehe Schritt 4)

### Drucker druckt nicht

- Testdruck vom Host: `echo "Test" | lp -d workplacepure`
- Logs prüfen: `sudo tail -f /var/log/cups/error_log`

---

## Kurz-Checkliste

- [ ] CUPS auf dem Host installiert und gestartet
- [ ] Drucker mit `lpadmin` angelegt (Name: `workplacepure`)
- [ ] `lpstat -p` zeigt den Drucker
- [ ] CUPS hört auf 0.0.0.0:631 und erlaubt 172.17.0.0/16 (Schritt 4)
- [ ] `CUPS_SERVER=172.17.0.1` in docker-compose gesetzt
- [ ] Container neu gestartet
- [ ] In der App: Druckername `workplacepure` und ggf. CUPS-Server eingetragen
