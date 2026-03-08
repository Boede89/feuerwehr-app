# CUPS neu einrichten

CUPS läuft auf dem **Host** (nicht im Docker-Container). Die App verbindet sich über den CUPS-Server.

## Voraussetzungen

- **Linux** (Debian/Ubuntu)
- Root-Rechte (sudo)

## 1. CUPS komplett deinstallieren und neu einrichten

```bash
cd scripts
chmod +x cups-neu-einrichten.sh
sudo ./cups-neu-einrichten.sh
```

## 2. Drucker per Shell hinzufügen

**Workplace Pure (mit Benutzer/Passwort):**
```bash
sudo lpadmin -p WacheAmern -E -v 'https://BENUTZER:PASSWORT@ipp.workplacepure.com/ipp/print/IHRE-ID/DRUCKER-ID' -m everywhere
```

**IPP-Netzwerkdrucker (ohne Auth):**
```bash
sudo lpadmin -p DruckerName -E -v ipp://192.168.1.10/ipp/print -m everywhere
```

**USB-Drucker:**
```bash
lpinfo -v | grep usb   # URI ermitteln
sudo lpadmin -p DruckerName -E -v usb://Hersteller/Modell -m everywhere
```

**Drucker prüfen:**
```bash
lpstat -v
lp -d WacheAmern /etc/hosts   # Testdruck
```

## 3. App-Einstellungen

**Admin → Einstellungen → Drucker-Tab**:

- **CUPS-Server**: `172.17.0.1:631/version=1.1` (Docker auf Linux)
- **Drucker**: Der Name aus `lpstat -v` (z.B. WacheAmern)

Speichern, dann „Drucker auflisten“ klicken um die installierten Drucker zu laden.
