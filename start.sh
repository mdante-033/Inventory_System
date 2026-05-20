#!/bin/bash
# docker/start.sh
# Render injects $PORT at container startup (usually 10000).
# Apache needs this exact port BEFORE it starts.
# This script patches the port then hands off to Apache.

set -e

# Use Render's PORT or fall back to 10000
PORT="${PORT:-10000}"

echo "==> Render PORT = ${PORT}"
echo "==> Patching Apache config to listen on port ${PORT}"

# 1. Update Apache ports.conf to listen on Render's port
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf

# 2. Patch the __PORT__ placeholder in our VirtualHost config
sed -i "s/__PORT__/${PORT}/" /etc/apache2/sites-available/000-default.conf

echo "==> Starting Apache in foreground on port ${PORT}"
exec apache2-foreground
