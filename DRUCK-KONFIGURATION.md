# Druck-Konfiguration (CUPS, Workplace Pure)

## Konica Minolta Workplace Pure über CUPS

Die Feuerwehr-App druckt über CUPS (`lp`). Für **Workplace Pure** muss der Drucker im CUPS-Server (z.B. Linux-Container) angelegt werden – nicht über den Cloud-Drucker-Pfad der App.

### 1. CUPS installieren

```bash
sudo apt update
sudo apt install cups cups-client
sudo systemctl start cups
sudo systemctl enable cups
```

### 2. Workplace-Pure-Drucker in CUPS anlegen

**URI-Format:** `ipps://BENUTZER:PASSWORT@ipp.workplacepure.com:443/ipp/print/2b3a694f-d475-4198-97bb-83088831f31f/14619`

- **Port 443** muss explizit angegeben werden (CUPS nutzt sonst 631).
- Sonderzeichen in Benutzername/Passwort URL-kodieren: `@` → `%40`, `:` → `%3A`, `!` → `%21`

**Befehl:**

```bash
sudo lpadmin -p "WorkplacePure" -E \
  -v "ipps://dein_benutzer:dein_passwort@ipp.workplacepure.com:443/ipp/print/2b3a694f-d475-4198-97bb-83088831f31f/14619" \
  -m lsb/usr/cupsfilters/Generic-PDF_Printer-PDF.ppd
```

### 3. In der Feuerwehr-App

- **Druckmethode:** CUPS-Drucker
- **CUPS-Drucker:** `WorkplacePure` (oder der gewählte Name)
- **PostScript-Option:** **deaktiviert** (Workplace Pure erwartet PDF, nicht PostScript)

### 4. Bei Docker

Wenn die App in Docker läuft, muss `printer_cups_server` auf den Host gesetzt werden (z.B. `host.docker.internal:631` oder `192.168.x.x:631`), auf dem CUPS läuft.

### 5. Testdruck

```bash
echo "Testdruck" | enscript -p - 2>/dev/null | ps2pdf - /tmp/test.pdf
lp -d WorkplacePure /tmp/test.pdf
```

---

## Wichtige Hinweise

| Problem | Lösung |
|--------|--------|
| „Job has no data“ | PostScript-Option **deaktivieren** – PDF senden |
| „Connection timed out“ | Port **443** in der URI angeben |
| „IPP Everywhere driver requires IPP connection“ | Generic-PDF-Treiber verwenden: `lsb/usr/cupsfilters/Generic-PDF_Printer-PDF.ppd` |
