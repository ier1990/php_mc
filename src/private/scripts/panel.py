#!/usr/bin/env python3
"""
panel.py – A simple terminal‑based control panel for the T5600 AI system.

The original implementation used Tkinter to create a GUI, but that
requires a graphical environment which is not available on many
headless servers.  This rewrite uses the standard `curses` library,
so it can run in any terminal session (including SSH).

Features
--------
* A scrollable menu of actions.
* Each action runs an external command via subprocess.Popen.
* The interface updates immediately after a command finishes.

Author: CodeWalker
"""

import curses
import subprocess
from typing import List, Tuple

# --------------------------------------------------------------------------- #
# Configuration – commands to run for each menu item
# --------------------------------------------------------------------------- #

Command = Tuple[str, str]  # (display text, shell command)

#detect if apache or nginx is installed
# if apache is installed use systemctl restart apache2
# if nginx is installed use systemctl restart nginx

if subprocess.run("which apache2", shell=True, capture_output=True).returncode == 0:
    RESTART_COMMAND = "systemctl restart apache2"
elif subprocess.run("which nginx", shell=True, capture_output=True).returncode == 0:
    RESTART_COMMAND = "systemctl restart nginx"
else:
    RESTART_COMMAND = "echo 'No web server found'"



COMMANDS: List[Command] = [
    # --- Web / PHP-FPM ---
    (" Test Nginx Config",          'sudo nginx -t'),
    (" Reload Nginx",               'sudo systemctl reload nginx'),
    (" Restart PHP-FPM",            'sudo systemctl restart php8.2-fpm'),
    (" Restart Web Server",         f'sudo {RESTART_COMMAND}'),
    (" Nginx Version",              'nginx -v 2>&1'),
    (" PHP Version",                'php -v | head -1'),

    # --- Logs & Timers (non-blocking snapshots) ---
    (" Tail Nginx Access (200)",    'tail -n 200 /var/log/nginx/access.log'),
    (" Tail Nginx Error (200)",     'tail -n 200 /var/log/nginx/error.log'),
    (" Tail App Log (200)",         'tail -n 200 /var/www/html/admin/php_mc/src/private/logs/codewalker.log'),
    (" List systemd Timers",        'systemctl list-timers --all | sed -n "1,40p"'),

    # --- Codewalker service (systemd) ---
    (" Run Codewalker Now",         'sudo systemctl start codewalker.service'),
    (" Codewalker Journal (100)",   'sudo journalctl -u codewalker.service -n 100 --no-pager'),

    # --- Repo helpers ---
    (" Pull master (deploy)",       '/var/www/html/admin/php_mc/scripts/pull_master.sh'),
    (" Push master (quick)",        '/var/www/html/admin/php_mc/scripts/push_master.sh'),

    # --- Lints / Health ---
    (" PHP Lint repo (7.4)",        "docker run --rm -v \"$PWD\":/app -w /app php:7.4-cli bash -lc 'git ls-files -z \"*.php\" \":(exclude)vendor/**\" \":(exclude)old/**\" | xargs -0 -n1 php -l'"),
    (" Python Compile *.py",        "git ls-files -z '*.py' ':(exclude)old/**' | xargs -0 -n1 python3 -m py_compile"),

    # --- System snapshots ---
    (" Open Ports",                 'ss -tulpen | head -n 40'),
    (" Disk Usage",                 'df -hT | sort -k6'),
    (" Top Processes (1-shot)",     'ps aux --sort=-%cpu | head -n 15'),

    # --- Ollama / LM Studio quick checks ---
    (" Ping Ollama",                'curl -s http://127.0.0.1:11434/api/tags | head'),
    (" Ping LM Studio",             'curl -s http://127.0.0.1:1234/v1/models | head'),

    # --- Maintenance ---
    (" Fix Dir Perms",              '/var/www/html/admin/php_mc/scripts/dirperm-hourly.sh'),
    (" Backup notes.db",            "ts=$(date +%Y%m%d-%H%M%S); cp -a src/private/db/notes.db src/private/db/notes.db.$ts.bak && echo Backed up as notes.db.$ts.bak"),
]
# --------------------------------------------------------------------------- #
# Helper functions
# --------------------------------------------------------------------------- #

def run_command(cmd: str) -> None:
    """
    Execute a shell command asynchronously.

    Parameters
    ----------
    cmd : str
        The command to execute.  It is passed directly to the shell.
    """
    subprocess.Popen(cmd, shell=True)


def draw_menu(stdscr, selected_idx: int) -> None:
    """
    Render the menu on the screen.

    Parameters
    ----------
    stdscr : curses.window
        The main window object.
    selected_idx : int
        Index of the currently highlighted item.
    """
    stdscr.clear()
    height, width = stdscr.getmaxyx()

    title = "🛠️ T5600 AI Control Panel"
    stdscr.attron(curses.color_pair(2))
    stdscr.addstr(1, (width - len(title)) // 2, title)
    stdscr.attroff(curses.color_pair(2))

    for idx, (label, _) in enumerate(COMMANDS):
        y = 3 + idx
        if y >= height - 1:
            break  # Don't write past the bottom of the screen

        if idx == selected_idx:
            stdscr.attron(curses.A_REVERSE)

        stdscr.addstr(y, 4, label)
        stdscr.attroff(curses.A_REVERSE)

    stdscr.refresh()


def main(stdscr: curses.window) -> None:
    """
    Main event loop for the panel.

    Parameters
    ----------
    stdscr : curses.window
        The main window object.
    """
    # Initialise colour pairs (foreground, background)
    curses.start_color()
    curses.init_pair(1, curses.COLOR_WHITE, curses.COLOR_BLACK)  # default
    curses.init_pair(2, curses.COLOR_GREEN, curses.COLOR_BLACK)  # title

    selected = 0
    draw_menu(stdscr, selected)

    while True:
        key = stdscr.getch()

        if key in (curses.KEY_UP, ord("k")) and selected > 0:
            selected -= 1
        elif key in (curses.KEY_DOWN, ord("j")) and selected < len(COMMANDS) - 1:
            selected += 1
        elif key in (ord("\n"), curses.KEY_ENTER):
            # Execute the chosen command
            _, cmd = COMMANDS[selected]
            run_command(cmd)
            # Brief pause so the user sees the menu again
            stdscr.addstr(
                len(COMMANDS) + 5,
                4,
                f"Started: {cmd}",
                curses.color_pair(1),
            )
            stdscr.refresh()
        elif key in (ord("q"), 27):  # 'q' or ESC to quit
            break

        draw_menu(stdscr, selected)


# --------------------------------------------------------------------------- #
# Entry point
# --------------------------------------------------------------------------- #

if __name__ == "__main__":
    curses.wrapper(main)
