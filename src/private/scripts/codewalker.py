#!/usr/bin/env python3
"""
CodeWalker — cron‑safe AI code & log walker for /web on .152

• Scans configurable directories for {php,py,sh,log}
• Randomly chooses summarize or rewrite (configurable %)
• NEVER overwrites originals — summaries/rewrites stored in SQLite
• Unified diffs captured for rewrites to review/apply later
• Designed to prefer local LLMs (LM Studio or Ollama), with OpenAI‑compatible fallback
• Uses lockfile to avoid overlapping cron runs

Quick start (suggested):
  1) Save config to /var/www/html/admin/php_mc/src/private/codewalker.json (see CONFIG_TEMPLATE below)
  2) mkdir -p /var/www/html/admin/php_mc/src/private/db /var/www/html/admin/php_mc/src/private/logs /var/www/html/admin/php_mc/src/private/scripts
  3) cp this file to /var/www/html/admin/php_mc/src/private/scripts/codewalker.py && chmod +x /var/www/html/admin/php_mc/src/private/scripts/codewalker.py
  4) Cron:  */20 * * * * /usr/bin/python3 /web/AI/bin/codewalker.py --config /web/private/codewalker.json --limit 30 >> /var/www/html/admin/php_mc/src/private/logs/codewalker.cron.log 2>&1

Safety defaults:
  • Max file read ≈ 512 KB (code) / tail 1200 lines (logs) — configurable
  • Skips common vendor/cache/uploads/.git dirs (extend via exclude_dirs)
  • Records file hash to skip unchanged content on later runs

Test queries (SQLite):
  SELECT path, action, created_at, status FROM vw_last_actions ORDER BY created_at DESC LIMIT 25;
  SELECT rewrite, diff FROM rewrites WHERE action_id = ?;

Requires: Python 3.9+, requests
Optional: python-dotenv (auto fallback to simple .env loader)
"""
from __future__ import annotations
import argparse
import datetime as dt
import difflib
import fnmatch
import hashlib
import json
import logging
import os
import random
import re
import shlex
import signal
import socket
import sqlite3
import sys
import time
from pathlib import Path

# Third‑party
try:
    import requests
except ImportError:
    print("[CodeWalker] Missing dependency: requests\n  pip install requests", file=sys.stderr)
    sys.exit(2)

# Optional .env support
try:
    from dotenv import load_dotenv  # type: ignore
    _HAS_DOTENV = True
except Exception:
    _HAS_DOTENV = False

APP_NAME = "CodeWalker"
VERSION = "1.0.0"


# ---------------------- Config ----------------------
CONFIG_TEMPLATE = {
    "name": "CodeWalker",
    "mode": "cron",
    "scan_path": "/var/www/html/admin/php_mc",
    "file_types": ["php", "py", "sh", "log"],
    "actions": ["summarize", "rewrite"],
    "rewrite_prompt": "Make this code more readable and modular.",
    # Optional external prompt file (JSON) to extend/replace internal pools.
    # Example structure (prompt.json):
    # {
    #   "rewrite_prompts": { "A": ["..."], "B": ["..."], "EXTRA": ["..."] },
    #   "notes": "free form"
    # }
    # or simply: { "rewrite_prompts": ["prompt1", "prompt2"] }
    "prompt_file": "/var/www/html/admin/php_mc/src/private/prompt.json",
    "db_path": "/var/www/html/admin/php_mc/src/private/db/codewalker.db",
    "log_path": "/var/www/html/admin/php_mc/src/private/logs/codewalker.log",
    "exclude_dirs": [
        ".git", "vendor", "node_modules", "storage", "cache", "tmp", "uploads" 
        
    ],
    "exclude_files": [
        "codewalker.py"
    ],
    "max_filesize_kb": 512,
    "log_tail_lines": 1200,
    "backend": "auto",           # auto|lmstudio|ollama|openai_compat|custom
    "base_url": None,   # if backend==custom or openai_compat
    "api_key": None,              # if your endpoint needs a key
    "model": "gemma3:4b",
    "percent_rewrite": 50,        # % chance a chosen action is rewrite (vs summarize)
    "limit_per_run": 5,
    "lockfile": "/tmp/codewalker.lock",
    "respect_gitignore": True,
}

## Legacy prompt pools removed; prompts now sourced solely from prompt.json.

# ---------------------- Utils ----------------------
def load_prompt_list(cfg: dict) -> list[str]:
    """Return a flat list of prompt texts from prompt.json (new schema)."""
    path = cfg.get("prompt_file")
    if not path or not os.path.isfile(path):
        # fallback to single rewrite_prompt if json missing
        rp = (cfg.get("rewrite_prompt") or "Make this code more readable and modular.").strip()
        return [rp]
    try:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        out: list[str] = []
        if isinstance(data, dict) and isinstance(data.get("prompts"), list):
            for obj in data.get("prompts", []):
                if isinstance(obj, dict):
                    txt = str(obj.get("text") or "").strip()
                    if txt:
                        out.append(txt)
        # Backward compatibility (legacy formats)
        elif isinstance(data, dict) and data.get("rewrite_prompts"):
            rp = data.get("rewrite_prompts")
            if isinstance(rp, list):
                out.extend([str(x).strip() for x in rp if str(x).strip()])
            elif isinstance(rp, dict):
                for _, arr in rp.items():
                    if isinstance(arr, list):
                        out.extend([str(x).strip() for x in arr if str(x).strip()])
        # Deduplicate preserving order
        seen = set(); final = []
        for p in out:
            if p and p not in seen:
                seen.add(p); final.append(p)
        #logging.info("Loaded %d prompts from %s", len(final), path)        
        return final or [ (cfg.get("rewrite_prompt") or "Make this code more readable and modular.").strip() ]
    except Exception as e:
        logging.warning(f"Failed to load prompts {path}: {e}")
        return [ (cfg.get("rewrite_prompt") or "Make this code more readable and modular.").strip() ]

def load_env():
    """Load .env from /var/www/html/admin/php_mc/src/private/.env if present, without failing if missing."""
    env_candidates = [
        "/var/www/html/admin/php_mc/src/private/.env",
        "/var/www/html/admin/php_mc/src/.env",
        str(Path.home() / ".env"),
    ]
    if _HAS_DOTENV:
        for p in env_candidates:
            if os.path.isfile(p):
                load_dotenv(p, override=False)
    else:
        # Minimal loader
        for p in env_candidates:
            if not os.path.isfile(p):
                continue
            try:
                with open(p, "r", encoding="utf-8", errors="ignore") as fh:
                    for line in fh:
                        line = line.strip()
                        if not line or line.startswith("#") or "=" not in line:
                            continue
                        k, v = line.split("=", 1)
                        k = k.strip(); v = v.strip()
                        os.environ.setdefault(k, v)
            except Exception:
                pass


def load_config(path: str | None) -> dict:
    cfg = json.loads(json.dumps(CONFIG_TEMPLATE))  # deep copy
    if path and os.path.isfile(path):
        with open(path, "r", encoding="utf-8") as f:
            user = json.load(f)
        cfg.update(user or {})
    # Env overrides (optional)
    cfg.setdefault("base_url", os.getenv("LLM_BASE_URL"))
    cfg.setdefault("api_key", os.getenv("LLM_API_KEY"))
    return cfg


def setup_logging(log_path: str):
    os.makedirs(os.path.dirname(log_path), exist_ok=True)
    logging.basicConfig(
        level=logging.INFO,
        format=f"%(asctime)s | {APP_NAME} %(levelname)s | %(message)s",
        handlers=[
            logging.FileHandler(log_path),
            logging.StreamHandler(sys.stdout),
        ],
    )


def human_ts(ts: float | None = None) -> str:
    return dt.datetime.fromtimestamp(ts or time.time()).isoformat(timespec="seconds")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def tail_lines(path: str, n: int) -> str:
    """Tail last n lines efficiently for large log files."""
    try:
        # Fast tail: read from end in chunks
        avg_line_len = 120
        to_read = n * avg_line_len
        size = os.path.getsize(path)
        with open(path, "rb") as f:
            if size > to_read:
                f.seek(-to_read, os.SEEK_END)
            data = f.read()
        text = data.decode("utf-8", errors="ignore")
        return "\n".join(text.splitlines()[-n:])
    except Exception as e:
        logging.warning(f"tail_lines failed for {path}: {e}")
        return ""


def unified_diff(a_text: str, b_text: str, a_name: str, b_name: str) -> str:
    a = a_text.splitlines()
    b = b_text.splitlines()
    return "\n".join(difflib.unified_diff(a, b, fromfile=a_name, tofile=b_name, lineterm=""))

# ---------------------- SQLite ----------------------

DDL = r"""
PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS files (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  path TEXT UNIQUE,
  ext TEXT,
  first_seen TEXT,
  last_seen TEXT,
  last_hash TEXT
);
CREATE TABLE IF NOT EXISTS runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  started_at TEXT,
  finished_at TEXT,
  host TEXT,
  pid INTEGER,
  config_json TEXT
);
CREATE TABLE IF NOT EXISTS actions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  run_id INTEGER,
  file_id INTEGER,
  action TEXT,
  model TEXT,
  backend TEXT,
  prompt TEXT,
  file_hash TEXT,
  tokens_in INTEGER,
  tokens_out INTEGER,
  status TEXT,
  error TEXT,
  created_at TEXT
);
CREATE TABLE IF NOT EXISTS summaries (
  action_id INTEGER PRIMARY KEY,
  summary TEXT
);
CREATE TABLE IF NOT EXISTS rewrites (
  action_id INTEGER PRIMARY KEY,
  rewrite TEXT,
  diff TEXT
);
CREATE VIEW IF NOT EXISTS vw_last_actions AS
  SELECT a.*, f.path FROM actions a
  JOIN files f ON f.id = a.file_id
  WHERE a.id IN (
    SELECT MAX(id) FROM actions GROUP BY file_id
  );
CREATE TABLE IF NOT EXISTS queued_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    path TEXT UNIQUE,
    requested_at TEXT,
    requested_by TEXT,
    notes TEXT,
    status TEXT DEFAULT 'pending'
);
"""


def db_connect(db_path: str) -> sqlite3.Connection:
    os.makedirs(os.path.dirname(db_path), exist_ok=True)
    conn = sqlite3.connect(db_path)
    conn.execute("PRAGMA foreign_keys=ON")
    for stmt in DDL.strip().split(";\n"):
        s = stmt.strip()
        if s:
            conn.execute(s)
    return conn


def db_get_or_create_file(conn: sqlite3.Connection, path: str, ext: str, hsh: str) -> int:
    now = human_ts()
    cur = conn.cursor()
    cur.execute("SELECT id, last_hash FROM files WHERE path=?", (path,))
    row = cur.fetchone()
    if row:
        fid, last_hash = row
        cur.execute("UPDATE files SET last_seen=?, ext=?, last_hash=? WHERE id=?", (now, ext, hsh, fid))
        conn.commit()
        return fid
    cur.execute(
        "INSERT INTO files(path,ext,first_seen,last_seen,last_hash) VALUES(?,?,?,?,?)",
        (path, ext, now, now, hsh),
    )
    conn.commit()
    return cur.lastrowid


def db_get_pending_queue_paths(conn: sqlite3.Connection) -> list[str]:
    """Return pending queued file paths (deduped, order by id)."""
    try:
        rows = conn.execute("SELECT path FROM queued_files WHERE status='pending' ORDER BY id ASC").fetchall()
        paths = []
        seen = set()
        for row in rows:
            p = row[0]
            if not isinstance(p, str):
                continue
            ap = os.path.abspath(p)
            if ap in seen:
                continue
            seen.add(ap)
            if os.path.isfile(p):
                paths.append(p)
        return paths
    except Exception:
        return []


def db_insert_action(conn: sqlite3.Connection, run_id: int, file_id: int, action: str, model: str, backend: str, prompt: str, file_hash: str, status: str, error: str | None, tokens_in: int | None, tokens_out: int | None) -> int:
    cur = conn.cursor()
    cur.execute(
        """
        INSERT INTO actions(run_id,file_id,action,model,backend,prompt,file_hash,tokens_in,tokens_out,status,error,created_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
        """,
        (run_id, file_id, action, model, backend, prompt, file_hash, tokens_in, tokens_out, status, error or "", human_ts()),
    )
    conn.commit()
    return cur.lastrowid

# ---------------------- LLM backends ----------------------

class LLMError(Exception):
    pass


def llm_chat(cfg: dict, messages: list[dict], model: str | None = None) -> tuple[str, dict]:
    """Try backends based on cfg['backend'] with graceful fallback.
    Returns (text, meta) where meta can include usage/token counts.
    """
    backend_pref = (cfg.get("backend") or "auto").lower()
    tried = []
    model = model or cfg.get("model") or "gemma3:4b"

    def _try_lmstudio():
        url = (cfg.get("base_url") or os.getenv("LLM_BASE_URL") or "") + "/v1/chat/completions"        
        print(f"LM Studio URL: {url} (model: {model})")
        payload = {"model": model, "messages": messages, "temperature": 0.2}
        headers = {"Content-Type": "application/json"}
        if cfg.get("api_key") or os.getenv("LLM_API_KEY"):
            headers["Authorization"] = f"Bearer {cfg.get('api_key') or os.getenv('LLM_API_KEY')}"
        r = requests.post(url, json=payload, headers=headers, timeout=900)
        if r.status_code >= 400:
            raise LLMError(f"LM Studio {r.status_code}: {r.text[:200]}")
        j = r.json()
        text = j["choices"][0]["message"]["content"]
        return text, {"backend": "lmstudio", "raw": j, "usage": j.get("usage")}

    def _try_ollama():
        url = (cfg.get("base_url") or "") + "/api/chat"
        print(f"Ollama URL: {url}")
        # Ollama uses gemma3:4b
        model = "gemma3:4b"
        payload = {"model": model, "messages": messages, "options": {"temperature": 0.2}}
        r = requests.post(url, json=payload, timeout=180)
        if r.status_code >= 400:
            raise LLMError(f"Ollama {r.status_code}: {r.text[:200]}")
        # Ollama may stream by default; ensure we get full JSON by using non-stream endpoint
        j = r.json()
        text = j.get("message", {}).get("content") or j.get("content")
        if not text:
            # Non-streaming /chat returns aggregated messages under 'message'
            msgs = j.get("messages") or []
            if msgs:
                text = msgs[-1].get("content", "")
        if not text:
            raise LLMError("Ollama: empty content")
        return text, {"backend": "ollama", "raw": j}

    def _try_openai_compat():
        base = cfg.get("base_url") or os.getenv("LLM_BASE_URL")
        if not base:
            raise LLMError("openai_compat requires base_url (LLM_BASE_URL)")
        url = base.rstrip("/") + "/v1/chat/completions"
        payload = {"model": model, "messages": messages, "temperature": 0.2}
        headers = {"Content-Type": "application/json"}
        if cfg.get("api_key") or os.getenv("LLM_API_KEY"):
            headers["Authorization"] = f"Bearer {cfg.get('api_key') or os.getenv('LLM_API_KEY')}"
        r = requests.post(url, json=payload, headers=headers, timeout=180)
        if r.status_code >= 400:
            raise LLMError(f"OpenAI‑compat {r.status_code}: {r.text[:200]}")
        j = r.json()
        text = j["choices"][0]["message"]["content"]
        return text, {"backend": "openai_compat", "raw": j, "usage": j.get("usage")}

    sequence = []
    if backend_pref == "auto":
        sequence = [_try_lmstudio, _try_ollama, _try_openai_compat]
    elif backend_pref == "lmstudio":
        sequence = [_try_lmstudio]
    elif backend_pref == "ollama":
        sequence = [_try_ollama]
    elif backend_pref in ("openai", "openai_compat", "custom"):
        sequence = [_try_openai_compat]
    else:
        sequence = [_try_lmstudio, _try_ollama, _try_openai_compat]

    last_exc = None
    for fn in sequence:
        name = fn.__name__.replace("_try_", "")
        try:
            text, meta = fn()
            meta["backend"] = meta.get("backend") or name
            return text, meta
        except Exception as e:
            tried.append(name)
            last_exc = e
            continue
    raise LLMError(f"All backends failed (tried: {tried}): {last_exc}")

# ---------------------- Walker ----------------------

CODE_LIKE_EXT = {"php", "py", "sh"}


def should_skip_dir(rel_dir: str, exclude_dirs: list[str]) -> bool:
    # Normalize and check any component contains excluded token
    parts = [p for p in Path(rel_dir).parts if p not in ("", ".")]
    for ex in exclude_dirs:
        ex = ex.strip().strip("/")
        if not ex:
            continue
        if ex in parts:
            return True
    return False


def should_skip_file(rel_file: str, name: str, exclude_files: list[str]) -> bool:
    """Return True if the file should be excluded based on patterns.
    Patterns can match either the basename (name) or the relative path (rel_file).
    Supports fnmatch-style wildcards like '*.log', '*/cache/*', 'codewalker.py'.
    """
    if not exclude_files:
        return False
    for pat in exclude_files:
        if not pat:
            continue
        pat = pat.strip()
        if not pat:
            continue
        # Match against basename and relative path
        if fnmatch.fnmatch(name, pat) or fnmatch.fnmatch(rel_file.replace("\\", "/"), pat):
            return True
    return False


def gather_candidates(cfg: dict) -> list[str]:
    base = Path(cfg["scan_path"]).resolve()
    ex = cfg.get("exclude_dirs") or []    
    ex = [e.strip("/") for e in ex]
    ex = list(dict.fromkeys(ex))  # dedupe
    ex_files = cfg.get("exclude_files") or []

    file_types = set([e.lstrip(".").lower() for e in cfg.get("file_types", [])])
    max_bytes = int(cfg.get("max_filesize_kb", 512)) * 1024
    respect_gitignore = bool(cfg.get("respect_gitignore", True))
    git_ignores: set[str] = set()

    if respect_gitignore:
        gi_path = base / ".gitignore"
        if gi_path.exists():
            try:
                for line in gi_path.read_text(encoding="utf-8", errors="ignore").splitlines():
                    s = line.strip()
                    if not s or s.startswith("#"):
                        continue
                    git_ignores.add(s)
            except Exception:
                pass

    candidates: list[str] = []
    for root, dirs, files in os.walk(base, followlinks=False):
        rel = os.path.relpath(root, base)
        if rel == ".":
            rel = ""
        if should_skip_dir(rel, ex):
            # Prune traversal
            dirs[:] = []
            continue
        # prune by .gitignore patterns (basic)
        if respect_gitignore and git_ignores:
            dirs[:] = [d for d in dirs if not any(fnmatch.fnmatch(d, pat) for pat in git_ignores)]
        for fn in files:
            ext = fn.split(".")[-1].lower() if "." in fn else ""
            if ext not in file_types:
                continue
            full = str(Path(root) / fn)
            # Skip files by name/path patterns
            rel_file = os.path.relpath(full, base)
            if should_skip_file(rel_file, fn, ex_files):
                continue
            try:
                if os.path.getsize(full) > max_bytes and ext in CODE_LIKE_EXT:
                    # too big for code; skip (logs handled later)
                    continue
            except OSError:
                continue
            candidates.append(full)
    random.shuffle(candidates)
    return candidates


def read_payload_for_model(path: str, cfg: dict) -> tuple[str, str]:
    """Return (content_text, ext) for the model, respecting size caps.
    For logs: return tail N lines. For code: full text up to cap.
    """
    ext = path.split(".")[-1].lower()
    if ext == "log":
        n = int(cfg.get("log_tail_lines", 1200))
        payload = tail_lines(path, n)
        return payload, ext
    # code‑like
    max_bytes = int(cfg.get("max_filesize_kb", 512)) * 1024
    try:
        with open(path, "rb") as f:
            data = f.read(max_bytes)
        text = data.decode("utf-8", errors="ignore")
        return text, ext
    except Exception as e:
        logging.warning(f"read_payload_for_model failed {path}: {e}")
        return "", ext


SUMMARIZE_INSTR = (
    "You are CodeWalker, an expert static analyzer. Read the file content and produce a compact, actionable JSON summary. "
    "Focus on purpose, key functions, inputs/outputs, dependencies, side effects, security or performance risks, and immediate TODOs. "
    "If it is a LOG, extract patterns, error types, and anomalies from the given tail. Return *valid JSON only* with keys: "
    "{file_purpose, key_functions, inputs_outputs, dependencies, side_effects, risks, todos, test_ideas}."
)

REWRITE_INSTR_PREFIX = (
    "You are CodeWalker, a careful refactoring assistant. Rewrite the file for clarity and modularity while preserving behavior. "
    "Target language must remain the same. Keep comments helpful. Output only one fenced code block with the full rewritten file."
)


CODE_BLOCK_RE = re.compile(r"```([a-zA-Z0-9_+-]*)\n([\s\S]*?)```", re.MULTILINE)


def extract_first_codeblock(text: str) -> tuple[str, str] | None:
    m = CODE_BLOCK_RE.search(text)
    if not m:
        return None
    lang = (m.group(1) or "").strip()
    body = m.group(2)
    return lang, body


# ---------------------- External Prompt Loading ----------------------

## Legacy load_external_prompts removed in favor of simple load_prompt_list.


# ---------------------- Main run ----------------------

def run_once(cfg: dict) -> None:
    # Locking
    lockfile = cfg.get("lockfile") or "/tmp/codewalker.lock"
    lock_fd = None
    try:
        lock_fd = os.open(lockfile, os.O_CREAT | os.O_RDWR)
        try:
            import fcntl
            fcntl.lockf(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except Exception:
            logging.info("Another CodeWalker run is active; exiting.")
            return
    except Exception as e:
        logging.warning(f"Could not establish lock: {e}")

    conn = db_connect(cfg["db_path"])
    cur = conn.cursor()
    cur.execute(
        "INSERT INTO runs(started_at,host,pid,config_json) VALUES(?,?,?,?)",
        (human_ts(), socket.gethostname(), os.getpid(), json.dumps(cfg, ensure_ascii=False)),
    )
    conn.commit()
    run_id = cur.lastrowid

    try:
        limit = int(cfg.get("limit_per_run") or 50)
        mode = str(cfg.get("mode") or "cron").strip().lower()
        if mode in ("que", "queue", "queue-only", "queued"):
            # Process only queued files
            candidates = db_get_pending_queue_paths(conn)
        else:
            # Scan directories as usual, but prioritize queued first
            candidates = gather_candidates(cfg)
            queued_paths = db_get_pending_queue_paths(conn)
            if queued_paths:
                seen = set(os.path.abspath(p) for p in queued_paths)
                prioritized = queued_paths[:]
                for p in candidates:
                    ap = os.path.abspath(p)
                    if ap not in seen:
                        prioritized.append(p)
                candidates = prioritized
        processed = 0
        logging.info(f"Found {len(candidates)} candidate files")

        for path in candidates:
            if processed >= limit:
                break
            try:
                payload, ext = read_payload_for_model(path, cfg)
                if not payload.strip():
                    continue
                # hash full file (not only payload) to detect change
                with open(path, "rb") as f:
                    full_bytes = f.read()
                hsh = sha256_bytes(full_bytes)
                file_id = db_get_or_create_file(conn, path, ext, hsh)

                # Skip unchanged if last action already saw this hash recently (optional)
                # (We always log action to see cadence; you can add a dedup check if desired.)

                # Decide action
                do_rewrite = random.randint(1, 100) <= int(cfg.get("percent_rewrite") or 25)
                action = "rewrite" if (do_rewrite and ext in CODE_LIKE_EXT) else "summarize"

                # Build prompts
                file_meta = f"File: {path}\nExt: {ext}\nSize: {len(full_bytes)} bytes\nLastModified: {human_ts(os.path.getmtime(path))}\n"

                if action == "summarize":
                    messages = [
                        {"role": "system", "content": SUMMARIZE_INSTR},
                        {"role": "user", "content": file_meta + "\nCONTENT:\n```" + ext + "\n" + payload + "\n```"},
                    ]
                    prompt_used = SUMMARIZE_INSTR
                else:
                    prompts = load_prompt_list(cfg)
                    chosen = random.choice(prompts) if prompts else (cfg.get("rewrite_prompt") or "Make this code more readable and modular.")
                    logging.info("Using rewrite prompt: %s", chosen)
                    prompt_used = f"{REWRITE_INSTR_PREFIX} {chosen}".strip()
                    #logging.info("Using rewrite prompt: %s", prompt_used)
                    safe_payload = payload.replace("```", "``\\`")
                    messages = [
                        {"role": "system", "content": prompt_used},
                        {"role": "user", "content": f"{file_meta}\nRewrite the entire file below.\n```{ext}\n{safe_payload}\n```"},
                    ]


                try:
                    text, meta = llm_chat(cfg, messages, model=cfg.get("model"))
                    backend = meta.get("backend", cfg.get("backend"))
                    usage = meta.get("usage") or {}
                    tokens_in = usage.get("prompt_tokens")
                    tokens_out = usage.get("completion_tokens")
                    status = "ok"
                    err = None
                except Exception as e:
                    text = ""
                    backend = cfg.get("backend")
                    tokens_in = tokens_out = None
                    status = "error"
                    err = str(e)

                action_id = db_insert_action(
                    conn, run_id, file_id, action, cfg.get("model"), backend, prompt_used, hsh, status, err, tokens_in, tokens_out
                )

                if status == "ok":
                    if action == "summarize":
                        # Expect valid JSON; if invalid, store raw text
                        summary_text = text.strip()
                        try:
                            # minimal validation
                            json.loads(summary_text)
                        except Exception:
                            # Wrap as JSON
                            summary_text = json.dumps({"raw": text}, ensure_ascii=False)
                        conn.execute("INSERT OR REPLACE INTO summaries(action_id,summary) VALUES(?,?)", (action_id, summary_text))
                    else:
                        # rewrite: try to extract code block; fallback to full text
                        body = text
                        blk = extract_first_codeblock(text)
                        if blk:
                            body = blk[1]
                        new_text = body
                        old_text = full_bytes.decode("utf-8", errors="ignore")
                        diff = unified_diff(old_text, new_text, path, path + ".rewritten")
                        conn.execute(
                            "INSERT OR REPLACE INTO rewrites(action_id,rewrite,diff) VALUES(?,?,?)",
                            (action_id, new_text, diff),
                        )
                else:
                    logging.warning(f"Action failed for {path}: {err}")

                conn.commit()
                # Mark queued entry as done if present
                try:
                    conn.execute("UPDATE queued_files SET status='done' WHERE path=? AND status='pending'", (path,))
                    conn.commit()
                except Exception:
                    pass
                processed += 1

            except Exception as e:
                logging.exception(f"Unhandled error processing {path}: {e}")
                continue

        logging.info(f"Processed {processed} files (limit {limit})")

    finally:
        cur.execute("UPDATE runs SET finished_at=? WHERE id=?", (human_ts(), run_id))
        conn.commit()
        conn.close()
        # release lock
        try:
            import fcntl
            if lock_fd is not None:
                fcntl.lockf(lock_fd, fcntl.LOCK_UN)
        except Exception:
            pass
        if lock_fd is not None:
            try:
                os.close(lock_fd)
            except Exception:
                pass


# ---------------------- CLI ----------------------

def main():
    parser = argparse.ArgumentParser(description=f"{APP_NAME} v{VERSION}")
    parser.add_argument("--config", default="/var/www/html/admin/php_mc/src/private/codewalker.json", help="Path to JSON config file")
    parser.add_argument("--limit", type=int, default=None, help="Override per‑run file limit")
    parser.add_argument("--percent-rewrite", type=int, default=None, help="Override rewrite percentage (0‑100)")
    parser.add_argument("--once", action="store_true", help="Run one pass immediately (default)")
    args = parser.parse_args()

    load_env()
    cfg = load_config(args.config)

    # CLI overrides
    if args.limit is not None:
        cfg["limit_per_run"] = args.limit
        limit = args.limit # useless
    if args.percent_rewrite is not None:
        cfg["percent_rewrite"] = max(0, min(100, args.percent_rewrite))

    setup_logging(cfg["log_path"])
    logging.info(f"Starting {APP_NAME} v{VERSION} | backend={cfg.get('backend')} model={cfg.get('model')}")
    logging.info(f"Scan: {cfg.get('scan_path')}  types={cfg.get('file_types')}  exclude={cfg.get('exclude_dirs')} exclude_files={cfg.get('exclude_files')}")

    run_once(cfg)


if __name__ == "__main__":
    main()
