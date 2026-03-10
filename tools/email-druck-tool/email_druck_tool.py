#!/usr/bin/env python3
"""
E-Mail Druck Tool - Überwacht ein IMAP-Postfach und druckt PDF-Anhänge bei passendem Betreff.
Alle Einstellungen werden in config.json gespeichert.
"""
import json
import os
import sys
import time
import imaplib
import email
import subprocess
import tempfile
from pathlib import Path
from email.header import decode_header

CONFIG_PATH = Path(__file__).parent / "config.json"
DEFAULT_CONFIG = {
    "imap": {
        "host": "imap.gmail.com",
        "port": 993,
        "use_ssl": True,
        "username": "",
        "password": "",
        "folder": "INBOX"
    },
    "filter": {
        "subject_contains": "DRUCK"
    },
    "printer": {
        "name": "",
        "sumatra_pdf_path": ""
    },
    "check_interval_seconds": 60,
    "autostart_enabled": False
}


def load_config():
    """Lädt die Konfiguration aus config.json."""
    if CONFIG_PATH.exists():
        try:
            with open(CONFIG_PATH, "r", encoding="utf-8") as f:
                config = json.load(f)
            # Merge mit Defaults für neue Felder
            return _merge_config(DEFAULT_CONFIG, config)
        except (json.JSONDecodeError, IOError) as e:
            print(f"Fehler beim Laden der Konfiguration: {e}")
    return DEFAULT_CONFIG.copy()


def _merge_config(base, override):
    """Rekursives Merge von Konfigurationen."""
    result = base.copy()
    for key, value in override.items():
        if key in result and isinstance(result[key], dict) and isinstance(value, dict):
            result[key] = _merge_config(result[key], value)
        else:
            result[key] = value
    return result


def save_config(config):
    """Speichert die Konfiguration in config.json – inkl. Passwort."""
    try:
        with open(CONFIG_PATH, "w", encoding="utf-8") as f:
            json.dump(config, f, indent=2, ensure_ascii=False)
            f.flush()
            os.fsync(f.fileno())
        return True
    except IOError as e:
        print(f"Fehler beim Speichern: {e}")
        return False


def decode_str(s):
    """Dekodiert E-Mail-Header-Strings."""
    if s is None:
        return ""
    if isinstance(s, str):
        return s
    parts = decode_header(s)
    result = []
    for part, enc in parts:
        if isinstance(part, bytes):
            result.append(part.decode(enc or "utf-8", errors="replace"))
        else:
            result.append(str(part))
    return "".join(result)


def get_pdf_attachments(msg):
    """Extrahiert PDF-Anhänge aus einer E-Mail."""
    pdfs = []
    for part in msg.walk():
        if part.get_content_maintype() == "multipart":
            continue
        filename = part.get_filename()
        if filename:
            filename = decode_str(filename)
            if filename.lower().endswith(".pdf"):
                pdfs.append((filename, part.get_payload(decode=True)))
    return pdfs


def print_pdf(pdf_content, config):
    """Druckt ein PDF mit dem konfigurierten Drucker."""
    printer = config.get("printer", {})
    printer_name = (printer.get("name") or "").strip()
    sumatra_path = (printer.get("sumatra_pdf_path") or "").strip()

    with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as tmp:
        tmp.write(pdf_content)
        tmp_path = tmp.name

    try:
        if sumatra_path and os.path.exists(sumatra_path):
            # SumatraPDF -print-to "Druckername" datei.pdf
            cmd = [sumatra_path, '-print-to', printer_name or 'default', tmp_path]
            subprocess.run(cmd, check=True, capture_output=True)
        else:
            # Windows: Standard-PDF-Druck
            if sys.platform == "win32":
                os.startfile(tmp_path, "print")
            else:
                subprocess.run(["lp", "-d", printer_name or "default", tmp_path], check=True)
    finally:
        try:
            os.unlink(tmp_path)
        except OSError:
            pass


def check_mailbox(config):
    """Prüft das Postfach und druckt passende PDFs."""
    imap_cfg = config.get("imap", {})
    host = imap_cfg.get("host", "").strip()
    port = int(imap_cfg.get("port", 993))
    username = imap_cfg.get("username", "").strip()
    password = imap_cfg.get("password", "").strip()
    folder = imap_cfg.get("folder", "INBOX")
    use_ssl = imap_cfg.get("use_ssl", True)

    filter_subject = (config.get("filter", {}).get("subject_contains") or "").strip()

    if not host or not username or not password:
        return

    try:
        if use_ssl:
            mail = imaplib.IMAP4_SSL(host, port)
        else:
            mail = imaplib.IMAP4(host, port)
        mail.login(username, password)
        mail.select(folder)

        _, data = mail.search(None, "UNSEEN")
        for num in data[0].split():
            if not num:
                continue
            _, msg_data = mail.fetch(num, "(RFC822)")
            for response_part in msg_data:
                if isinstance(response_part, tuple):
                    msg = email.message_from_bytes(response_part[1])
                    subject = decode_str(msg.get("Subject", ""))
                    if filter_subject and filter_subject.lower() not in subject.lower():
                        continue
                    pdfs = get_pdf_attachments(msg)
                    for _fn, content in pdfs:
                        if content and content[:4] == b"%PDF":
                            print_pdf(content, config)
        mail.logout()
    except Exception as e:
        print(f"IMAP-Fehler: {e}")


def run_headless():
    """Hauptschleife ohne GUI."""
    config = load_config()
    interval = max(30, int(config.get("check_interval_seconds", 60)))
    while True:
        try:
            check_mailbox(config)
        except Exception as e:
            print(f"Fehler: {e}")
        time.sleep(interval)


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--headless":
        run_headless()
    else:
        # GUI starten
        from email_druck_gui import main
        main()
