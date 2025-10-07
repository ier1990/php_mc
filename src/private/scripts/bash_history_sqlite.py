#!/usr/bin/env python3
"""Store bash history entries in a SQLite database.

This script mirrors the behaviour of the original `bash_history_sqlite.sh`
Bash script. It keeps track of how many lines from the history file have been
processed for a given host/path pair, appending only newly added commands into
`bash_history` and updating `history_state` accordingly.
"""
from __future__ import annotations

import os
import socket
import sqlite3
from pathlib import Path
from typing import Tuple

DB_PATH = Path("/var/www/html/admin/php_mc/src/private/db/bash_history.db")
DEFAULT_HISTORY = Path("~/.bash_history").expanduser()


def ensure_tables(connection: sqlite3.Connection) -> None:
    connection.executescript(
        """
        CREATE TABLE IF NOT EXISTS bash_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            host TEXT,
            timestamp TEXT,
            command TEXT
        );
        CREATE TABLE IF NOT EXISTS history_state (
            host TEXT NOT NULL,
            path TEXT NOT NULL,
            inode TEXT,
            last_line INTEGER DEFAULT 0,
            updated_at TEXT,
            PRIMARY KEY (host, path)
        );
        """
    )


def resolve_history_file() -> Path:
    histfile = os.environ.get("HISTFILE")
    if not histfile:
        return DEFAULT_HISTORY
    return Path(histfile)


def load_state(
    connection: sqlite3.Connection, host: str, histfile: Path
) -> Tuple[str, int]:
    cursor = connection.execute(
        """
        SELECT COALESCE(inode, ""), COALESCE(last_line, 0)
        FROM history_state
        WHERE host = ? AND path = ?
        LIMIT 1;
        """,
        (host, str(histfile)),
    )
    row = cursor.fetchone()
    if not row:
        return "", 0
    inode, last_line = row
    return str(inode) if inode is not None else "", int(last_line or 0)


def update_state(
    connection: sqlite3.Connection,
    host: str,
    histfile: Path,
    inode: str,
    last_line: int,
) -> None:
    connection.execute(
        """
        INSERT INTO history_state(host, path, inode, last_line, updated_at)
        VALUES(?, ?, ?, ?, datetime('now'))
        ON CONFLICT(host, path) DO UPDATE SET
            inode = excluded.inode,
            last_line = excluded.last_line,
            updated_at = excluded.updated_at;
        """,
        (host, str(histfile), inode, last_line),
    )


def process_history(connection: sqlite3.Connection) -> None:
    host = socket.gethostname()
    histfile = resolve_history_file()

    if not histfile.exists():
        return

    stats = histfile.stat()
    inode = str(getattr(stats, "st_ino", ""))

    old_inode, last_line = load_state(connection, host, histfile)

    with histfile.open("r", encoding="utf-8", errors="replace") as handle:
        lines = [line.rstrip("\n") for line in handle]

    line_count = len(lines)

    if old_inode and old_inode == inode and line_count >= last_line:
        start_index = max(last_line, 0)
    else:
        start_index = 0

    if start_index >= line_count:
        new_commands: list[str] = []
    else:
        new_commands = lines[start_index:]

    if new_commands:
        connection.executemany(
            """
            INSERT INTO bash_history (host, timestamp, command)
            VALUES (?, datetime('now'), ?);
            """,
            ((host, command) for command in new_commands),
        )

    update_state(connection, host, histfile, inode, line_count)


def main() -> None:
    histfile = resolve_history_file()
    if not histfile.exists():
        return

    DB_PATH.parent.mkdir(parents=True, exist_ok=True)

    with sqlite3.connect(DB_PATH) as connection:
        ensure_tables(connection)
        process_history(connection)
        connection.commit()


if __name__ == "__main__":
    main()
