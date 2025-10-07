#!/usr/bin/env bash
set -euo pipefail
echo "Docker version: $(docker --version || echo 'not found')"
echo "Docker active:  $(systemctl is-active docker 2>/dev/null || echo 'unknown')"
echo "Docker enabled: $(systemctl is-enabled docker 2>/dev/null || echo 'unknown')"
echo "Running hello-world…"
docker run --rm hello-world >/dev/null && echo "OK: hello-world ran"
echo "Ensuring PHP images exist…"
docker pull php:7.4-cli
docker pull php:8.2-cli
echo "Done."
