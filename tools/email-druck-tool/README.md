# E-Mail Druck Tool

Überwacht ein IMAP-Postfach und druckt PDF-Anhänge bei passendem Betreff. Für den Druck per E-Mail aus der Feuerwehr-App.

## Voraussetzungen

- Python 3.7+
- Windows (für Drucker-Anbindung)

## Installation

1. Python installieren (falls nicht vorhanden): [python.org](https://www.python.org/downloads/)
2. Tool starten: `start.bat` doppelklicken
3. Einstellungen konfigurieren und **Speichern** klicken

## Einstellungen (alle werden in config.json gespeichert)

- **Postfach (IMAP)**: Host, Port, Benutzername, **App-Passwort** (Gmail: [App-Passwörter](https://myaccount.google.com/apppasswords))
- **Betreff-Filter**: z.B. „DRUCK“ – muss mit dem Betreff in der Feuerwehr-App übereinstimmen
- **Drucker**: Druckername, optional SumatraPDF-Pfad
- **Autostart**: Bei Aktivierung startet das Tool beim Windows-Start im Hintergrund (ohne Fenster)

## Autostart ohne Fenster

1. „Autostart bei Windows-Start (im Hintergrund)“ aktivieren
2. **Speichern** klicken
3. Es wird ein Windows-Task (Task Scheduler) angelegt – mit vollem Python-Pfad, damit es auch beim Start (ohne PATH) funktioniert
4. Beim nächsten Anmelden startet das Tool automatisch im Hintergrund

**Falls Autostart nicht funktioniert:** Task Scheduler öffnen (`taskschd.msc`), nach „E-Mail-Druck-Tool“ suchen. Der Task muss den vollen Pfad zu `pythonw.exe` verwenden.

## Manueller Start

- `start.bat` – startet die Überwachung im Hintergrund und öffnet die Einstellungs-GUI
- `start_hidden.bat` – startet nur die Überwachung (ohne Fenster)
