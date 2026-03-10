#!/usr/bin/env python3
"""
GUI für das E-Mail Druck Tool.
Alle Einstellungen werden beim Speichern vollständig in config.json geschrieben.
"""
import json
import tkinter as tk
from tkinter import ttk, messagebox, filedialog
from pathlib import Path

# Import aus dem Hauptmodul
CONFIG_PATH = Path(__file__).parent / "config.json"
from email_druck_tool import load_config, save_config, DEFAULT_CONFIG


def ensure_nested(d, *keys):
    """Stellt sicher, dass verschachtelte Dicts existieren."""
    for k in keys:
        if k not in d:
            d[k] = {}
        d = d[k]
    return d


class EmailDruckGUI:
    def __init__(self):
        self.root = tk.Tk()
        self.root.title("E-Mail Druck Tool")
        self.root.geometry("550x520")
        self.root.resizable(True, True)

        self.config = load_config()
        self._build_ui()

    def _build_ui(self):
        main = ttk.Frame(self.root, padding=10)
        main.pack(fill=tk.BOTH, expand=True)

        # === IMAP / Postfach ===
        ttk.Label(main, text="Postfach (IMAP) – Gmail: App-Passwort verwenden", font=("", 10, "bold")).pack(anchor=tk.W, pady=(0, 5))

        f1 = ttk.Frame(main)
        f1.pack(fill=tk.X, pady=2)
        ttk.Label(f1, text="Host:", width=14, anchor=tk.W).pack(side=tk.LEFT, padx=(0, 5))
        self.imap_host = ttk.Entry(f1, width=40)
        self.imap_host.pack(side=tk.LEFT, fill=tk.X, expand=True)
        self.imap_host.insert(0, self.config.get("imap", {}).get("host", ""))

        f2 = ttk.Frame(main)
        f2.pack(fill=tk.X, pady=2)
        ttk.Label(f2, text="Port:", width=14, anchor=tk.W).pack(side=tk.LEFT, padx=(0, 5))
        self.imap_port = ttk.Entry(f2, width=10)
        self.imap_port.pack(side=tk.LEFT)
        self.imap_port.insert(0, str(self.config.get("imap", {}).get("port", 993)))

        f3 = ttk.Frame(main)
        f3.pack(fill=tk.X, pady=2)
        ttk.Label(f3, text="Benutzername:", width=14, anchor=tk.W).pack(side=tk.LEFT, padx=(0, 5))
        self.imap_username = ttk.Entry(f3, width=40)
        self.imap_username.pack(side=tk.LEFT, fill=tk.X, expand=True)
        self.imap_username.insert(0, self.config.get("imap", {}).get("username", ""))

        f4 = ttk.Frame(main)
        f4.pack(fill=tk.X, pady=2)
        ttk.Label(f4, text="App-Passwort:", width=14, anchor=tk.W).pack(side=tk.LEFT, padx=(0, 5))
        self.imap_password = ttk.Entry(f4, width=40, show="*")
        self.imap_password.pack(side=tk.LEFT, fill=tk.X, expand=True)
        self.imap_password.insert(0, self.config.get("imap", {}).get("password", ""))
        ttk.Label(f4, text="(wird immer gespeichert)", font=("", 8), foreground="gray").pack(side=tk.LEFT, padx=5)

        f5 = ttk.Frame(main)
        f5.pack(fill=tk.X, pady=2)
        self.imap_ssl = tk.BooleanVar(value=self.config.get("imap", {}).get("use_ssl", True))
        ttk.Checkbutton(f5, text="SSL/TLS verwenden", variable=self.imap_ssl).pack(anchor=tk.W)

        f6 = ttk.Frame(main)
        f6.pack(fill=tk.X, pady=2)
        ttk.Label(f6, text="Ordner:", width=14, anchor=tk.W).pack(side=tk.LEFT, padx=(0, 5))
        self.imap_folder = ttk.Entry(f6, width=20)
        self.imap_folder.pack(side=tk.LEFT)
        self.imap_folder.insert(0, self.config.get("imap", {}).get("folder", "INBOX"))

        # === Filter ===
        ttk.Separator(main, orient=tk.HORIZONTAL).pack(fill=tk.X, pady=15)
        ttk.Label(main, text="Betreff-Filter", font=("", 10, "bold")).pack(anchor=tk.W, pady=(0, 5))

        f7 = ttk.Frame(main)
        f7.pack(fill=tk.X, pady=2)
        ttk.Label(f7, text="Betreff enthält:", width=14, anchor=tk.W).pack(side=tk.LEFT, padx=(0, 5))
        self.filter_subject = ttk.Entry(f7, width=30)
        self.filter_subject.pack(side=tk.LEFT, fill=tk.X, expand=True)
        self.filter_subject.insert(0, self.config.get("filter", {}).get("subject_contains", "DRUCK"))

        # === Drucker ===
        ttk.Separator(main, orient=tk.HORIZONTAL).pack(fill=tk.X, pady=15)
        ttk.Label(main, text="Drucker", font=("", 10, "bold")).pack(anchor=tk.W, pady=(0, 5))

        f8 = ttk.Frame(main)
        f8.pack(fill=tk.X, pady=2)
        ttk.Label(f8, text="Druckername:", width=14, anchor=tk.W).pack(side=tk.LEFT, padx=(0, 5))
        self.printer_name = ttk.Entry(f8, width=35)
        self.printer_name.pack(side=tk.LEFT, fill=tk.X, expand=True)
        self.printer_name.insert(0, self.config.get("printer", {}).get("name", ""))

        f9 = ttk.Frame(main)
        f9.pack(fill=tk.X, pady=2)
        ttk.Label(f9, text="SumatraPDF-Pfad:", width=14, anchor=tk.W).pack(side=tk.LEFT, padx=(0, 5))
        self.sumatra_path = ttk.Entry(f9, width=30)
        self.sumatra_path.pack(side=tk.LEFT, fill=tk.X, expand=True)
        self.sumatra_path.insert(0, self.config.get("printer", {}).get("sumatra_pdf_path", ""))
        ttk.Button(f9, text="Durchsuchen", command=self._browse_sumatra).pack(side=tk.LEFT, padx=5)

        # === Intervall ===
        f10 = ttk.Frame(main)
        f10.pack(fill=tk.X, pady=10)
        ttk.Label(f10, text="Prüfintervall (Sek.):", width=14, anchor=tk.W).pack(side=tk.LEFT, padx=(0, 5))
        self.check_interval = ttk.Entry(f10, width=8)
        self.check_interval.pack(side=tk.LEFT)
        self.check_interval.insert(0, str(self.config.get("check_interval_seconds", 60)))

        # === Autostart ===
        self.autostart_var = tk.BooleanVar(value=self.config.get("autostart_enabled", False))
        ttk.Checkbutton(main, text="Autostart bei Windows-Start (im Hintergrund)", variable=self.autostart_var).pack(anchor=tk.W, pady=5)

        # === Buttons ===
        ttk.Separator(main, orient=tk.HORIZONTAL).pack(fill=tk.X, pady=15)
        btn_frame = ttk.Frame(main)
        btn_frame.pack(fill=tk.X, pady=5)
        ttk.Button(btn_frame, text="Speichern", command=self._save).pack(side=tk.LEFT, padx=(0, 10))
        ttk.Button(btn_frame, text="Schließen", command=self.root.quit).pack(side=tk.LEFT)

    def _browse_sumatra(self):
        path = filedialog.askopenfilename(
            title="SumatraPDF auswählen",
            filetypes=[("SumatraPDF", "SumatraPDF.exe"), ("Alle", "*.*")]
        )
        if path:
            self.sumatra_path.delete(0, tk.END)
            self.sumatra_path.insert(0, path)

    def _get_values(self):
        """Sammelt alle Werte aus der UI – inkl. Passwort (immer mitschreiben)."""
        return {
            "imap": {
                "host": self.imap_host.get().strip(),
                "port": int(self.imap_port.get() or 993),
                "use_ssl": self.imap_ssl.get(),
                "username": self.imap_username.get().strip(),
                "password": self.imap_password.get(),  # Immer speichern – kein "nur wenn geändert"
                "folder": self.imap_folder.get().strip() or "INBOX"
            },
            "filter": {
                "subject_contains": self.filter_subject.get().strip() or "DRUCK"
            },
            "printer": {
                "name": self.printer_name.get().strip(),
                "sumatra_pdf_path": self.sumatra_path.get().strip()
            },
            "check_interval_seconds": max(30, int(self.check_interval.get() or 60)),
            "autostart_enabled": self.autostart_var.get()
        }

    def _save(self):
        config = self._get_values()
        if save_config(config):
            messagebox.showinfo("Gespeichert", "Alle Einstellungen wurden in config.json gespeichert.")
            self.config = config
            # Autostart einrichten oder entfernen
            self._setup_autostart(config.get("autostart_enabled"))
        else:
            messagebox.showerror("Fehler", "Konfiguration konnte nicht gespeichert werden.")

    def _setup_autostart(self, enabled):
        """Richtet Windows-Autostart ein oder entfernt ihn – ohne sichtbares Fenster."""
        import os
        startup = Path(os.environ.get("APPDATA", "")) / "Microsoft" / "Windows" / "Start Menu" / "Programs" / "Startup"
        link = startup / "E-Mail-Druck-Tool.vbs"
        if not enabled:
            try:
                if link.exists():
                    link.unlink()
                    messagebox.showinfo("Autostart", "Autostart wurde deaktiviert.")
            except Exception as e:
                messagebox.showwarning("Autostart", f"Autostart konnte nicht entfernt werden: {e}")
            return
        script_dir = Path(__file__).parent.resolve()
        py_script = script_dir / "email_druck_tool.py"
        vbs_content = f'''Set WshShell = CreateObject("WScript.Shell")
WshShell.CurrentDirectory = "{script_dir}"
WshShell.Run "pythonw ""{py_script}"" --headless", 0, False
'''
        if startup.exists():
            try:
                with open(link, "w", encoding="utf-8") as f:
                    f.write(vbs_content)
                messagebox.showinfo("Autostart", "Autostart wurde eingerichtet. Das Tool startet beim Windows-Start im Hintergrund (ohne Fenster).")
            except Exception as e:
                messagebox.showwarning("Autostart", f"Autostart konnte nicht eingerichtet werden: {e}")
        else:
            messagebox.showinfo("Autostart", "Startup-Ordner nicht gefunden. Bitte start_hidden.vbs manuell in den Windows-Startup-Ordner kopieren.")

    def run(self):
        self.root.mainloop()


def main():
    app = EmailDruckGUI()
    app.run()
