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
3. Das Tool wird in den Windows-Startup-Ordner eingetragen und startet beim nächsten Neustart automatisch – ohne sichtbares Fenster

## Manueller Start im Hintergrund

`start_hidden.bat` startet das Tool ohne Fenster (ohne Autostart).
