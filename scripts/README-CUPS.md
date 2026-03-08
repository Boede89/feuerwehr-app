# CUPS neu einrichten

CUPS läuft auf dem **Host** (nicht im Docker-Container). Die App verbindet sich über `host.docker.internal:631`.

## Voraussetzungen

- **Linux** (Debian/Ubuntu) oder **WSL2** unter Windows
- Root-Rechte (sudo)

## CUPS komplett deinstallieren und neu einrichten

```bash
cd scripts
chmod +x cups-neu-einrichten.sh
sudo ./cups-neu-einrichten.sh
```

Das Skript:

1. Stoppt CUPS
2. Deinstalliert CUPS und alle zugehörigen Pakete
3. Löscht alle alten Konfigurationen und Drucker
4. Installiert CUPS neu
5. Konfiguriert CUPS für Zugriff von Docker (Port 631, alle Interfaces)
6. Startet CUPS

## Nach dem Neustart: Drucker hinzufügen

```bash
# IPP-Drucker (z.B. Workplace Pure, Netzwerkdrucker)
sudo lpadmin -p workplacepure -E -v ipp://DRUCKER-IP/ipp/print -m everywhere

# USB-Drucker (URI mit: lpinfo -v)
sudo lpadmin -p meindrucker -E -v usb://Hersteller/Modell -m everywhere

# Drucker anzeigen
lpstat -v

# Testdruck
lp -d workplacepure /etc/hosts
```

## App-Einstellungen

In der Feuerwehr-App: **Admin → Einstellungen → Drucker-Tab**:

- **CUPS-Server**: `host.docker.internal:631`
- **Druckername**: Der Name, den Sie mit `lpadmin -p` vergeben haben (z.B. `workplacepure`)

## Unter Windows

CUPS läuft nicht nativ unter Windows. Optionen:

1. **WSL2** mit Ubuntu installieren, dort CUPS einrichten und das Skript ausführen
2. **Separater Linux-Server** oder **VM** mit CUPS – dann in der App die IP des Servers statt `host.docker.internal` eintragen
