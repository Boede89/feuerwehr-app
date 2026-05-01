#!/usr/bin/env python3
"""
Importiert Alarmdepeschen (PDF-Anhaenge) aus einem IMAP-Postfach.

Beispiel:
python3 tools/import-alarmdepeschen-imap.py \
  --host imap.example.com --port 993 --user fax@example.com --password SECRET \
  --folder INBOX --einheit-id 1
"""

from __future__ import annotations

import argparse
import email
import imaplib
import hashlib
import os
import re
import ssl
import sys
from datetime import datetime, timezone
from email.header import decode_header
from email.utils import parsedate_to_datetime
from pathlib import Path

try:
    import pymysql
except Exception:
    print("PyMySQL fehlt. Bitte im Container installieren: pip install pymysql", file=sys.stderr)
    sys.exit(1)


SCRIPT_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = SCRIPT_DIR.parent
UPLOAD_DIR = PROJECT_ROOT / "uploads" / "alarmdepeschen"


def decode_mime_header(raw: str) -> str:
    out = []
    for part, charset in decode_header(raw or ""):
        if isinstance(part, bytes):
            enc = charset or "utf-8"
            try:
                out.append(part.decode(enc, errors="replace"))
            except Exception:
                out.append(part.decode("utf-8", errors="replace"))
        else:
            out.append(part)
    return "".join(out).strip()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="IMAP Import fuer Alarmdepeschen")
    parser.add_argument("--host", default=os.getenv("FAX_IMAP_HOST", ""))
    parser.add_argument("--port", default=int(os.getenv("FAX_IMAP_PORT", "993")), type=int)
    parser.add_argument("--user", default=os.getenv("FAX_IMAP_USER", ""))
    parser.add_argument("--password", default=os.getenv("FAX_IMAP_PASS", ""))
    parser.add_argument("--folder", default=os.getenv("FAX_IMAP_FOLDER", "INBOX"))
    parser.add_argument("--einheit-id", default=int(os.getenv("FAX_EINHEIT_ID", "0")), type=int)

    parser.add_argument("--db-host", default=os.getenv("DB_HOST", "mysql"))
    parser.add_argument("--db-port", default=int(os.getenv("DB_PORT", "3306")), type=int)
    parser.add_argument("--db-name", default=os.getenv("DB_NAME", "feuerwehr_app"))
    parser.add_argument("--db-user", default=os.getenv("DB_USER", "feuerwehr_user"))
    parser.add_argument("--db-password", default=os.getenv("DB_PASSWORD", "feuerwehr_password"))
    return parser.parse_args()


def db_connect(args: argparse.Namespace):
    return pymysql.connect(
        host=args.db_host,
        port=args.db_port,
        user=args.db_user,
        password=args.db_password,
        database=args.db_name,
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )


def ensure_table(conn) -> None:
    sql = """
    CREATE TABLE IF NOT EXISTS alarmdepesche_inbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        einheit_id INT NOT NULL DEFAULT 0,
        message_uid VARCHAR(191) NULL,
        subject VARCHAR(255) NOT NULL DEFAULT '',
        sender VARCHAR(255) NOT NULL DEFAULT '',
        received_at_utc DATETIME NOT NULL,
        filename_original VARCHAR(255) NOT NULL,
        storage_path VARCHAR(512) NOT NULL,
        sha256 VARCHAR(64) NULL,
        file_size_bytes BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_uid (message_uid),
        KEY idx_einheit_received (einheit_id, received_at_utc),
        KEY idx_received (received_at_utc)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """
    with conn.cursor() as cur:
        cur.execute(sql)
    conn.commit()


def message_uid_exists(conn, uid: str) -> bool:
    with conn.cursor() as cur:
        cur.execute("SELECT id FROM alarmdepesche_inbox WHERE message_uid = %s LIMIT 1", (uid,))
        row = cur.fetchone()
    return row is not None


def insert_pdf(
    conn,
    einheit_id: int,
    uid: str,
    subject: str,
    sender: str,
    received_at_utc: datetime,
    filename_original: str,
    storage_path: str,
    sha256: str,
    file_size_bytes: int,
) -> None:
    with conn.cursor() as cur:
        cur.execute(
            """
            INSERT INTO alarmdepesche_inbox
            (einheit_id, message_uid, subject, sender, received_at_utc, filename_original, storage_path, sha256, file_size_bytes)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                einheit_id,
                uid,
                subject,
                sender,
                received_at_utc.strftime("%Y-%m-%d %H:%M:%S"),
                filename_original,
                storage_path,
                sha256,
                file_size_bytes,
            ),
        )


def is_pdf_part(part: email.message.Message) -> bool:
    ctype = (part.get_content_type() or "").lower()
    filename = decode_mime_header(part.get_filename() or "")
    ext = Path(filename).suffix.lower()
    return ctype == "application/pdf" or ext == ".pdf"


def sanitize_filename(name: str) -> str:
    base = Path(name or "alarmdepesche.pdf").name
    safe = re.sub(r"[^a-zA-Z0-9._-]+", "_", base)
    return safe[:220] or "alarmdepesche.pdf"


def ensure_upload_dir() -> None:
    UPLOAD_DIR.mkdir(parents=True, exist_ok=True)


def save_pdf_bytes(data: bytes, received_at: datetime, original_name: str) -> tuple[str, str, int]:
    sha = hashlib.sha256(data).hexdigest()
    ts = received_at.strftime("%Y%m%d_%H%M%S")
    filename = f"{ts}_{sha[:10]}_{sanitize_filename(original_name)}"
    abs_path = UPLOAD_DIR / filename
    abs_path.write_bytes(data)
    return str(abs_path), sha, len(data)


def run_import(args: argparse.Namespace) -> int:
    if not args.host or not args.user or not args.password:
        print("Fehlende IMAP Parameter: --host --user --password", file=sys.stderr)
        return 1

    ensure_upload_dir()
    conn = db_connect(args)
    ensure_table(conn)

    imap = imaplib.IMAP4_SSL(args.host, args.port, ssl_context=ssl.create_default_context())
    imap.login(args.user, args.password)
    imap.select(args.folder)

    status, ids = imap.search(None, "UNSEEN")
    if status != "OK":
        print("IMAP Suche fehlgeschlagen.", file=sys.stderr)
        return 1

    msg_ids = ids[0].split()
    if not msg_ids:
        print("Keine neuen Mails.")
        return 0

    inserted = 0
    for msg_id in msg_ids:
        status, data = imap.fetch(msg_id, "(RFC822 UID)")
        if status != "OK" or not data:
            continue

        raw_email = None
        uid = ""
        for item in data:
            if not isinstance(item, tuple):
                continue
            header_blob = item[0].decode("utf-8", errors="ignore")
            m = re.search(r"UID\s+(\d+)", header_blob)
            if m:
                uid = m.group(1)
            raw_email = item[1]
            break
        if raw_email is None:
            continue

        if uid and message_uid_exists(conn, uid):
            imap.store(msg_id, "+FLAGS", "\\Seen")
            continue

        msg = email.message_from_bytes(raw_email)
        subject = decode_mime_header(msg.get("Subject", ""))
        sender = decode_mime_header(msg.get("From", ""))
        date_raw = msg.get("Date", "")
        try:
            received_dt = parsedate_to_datetime(date_raw)
            if received_dt.tzinfo is None:
                received_dt = received_dt.replace(tzinfo=timezone.utc)
            received_utc = received_dt.astimezone(timezone.utc)
        except Exception:
            received_utc = datetime.now(tz=timezone.utc)

        has_pdf = False
        for part in msg.walk():
            if part.is_multipart():
                continue
            if not is_pdf_part(part):
                continue
            payload = part.get_payload(decode=True) or b""
            if not payload.startswith(b"%PDF"):
                continue
            original_name = decode_mime_header(part.get_filename() or "alarmdepesche.pdf")
            abs_path, sha, size = save_pdf_bytes(payload, received_utc, original_name)
            insert_pdf(
                conn=conn,
                einheit_id=args.einheit_id,
                uid=uid,
                subject=subject,
                sender=sender,
                received_at_utc=received_utc,
                filename_original=original_name or "alarmdepesche.pdf",
                storage_path=abs_path,
                sha256=sha,
                file_size_bytes=size,
            )
            has_pdf = True
            inserted += 1
        conn.commit()
        if has_pdf:
            imap.store(msg_id, "+FLAGS", "\\Seen")

    imap.close()
    imap.logout()
    conn.close()

    print(f"Import abgeschlossen. Neue PDFs: {inserted}")
    return 0


if __name__ == "__main__":
    sys.exit(run_import(parse_args()))

