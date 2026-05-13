#!/usr/bin/env bash
# =====================================================
# Hi-Service Chatbot — deploy.sh
#
# Pushes the local code/ directory to hiservice.store via
# SSH using `expect` for password-based auth (rs53.cphost
# servers use this pattern — same as N24x4).
#
# Prereqs:
#   1. Fill in SSH_USER, SSH_PORT, SSH_PASS, REMOTE_PATH below
#      OR set them in env: source ../.env.local
#   2. cPanel: subdomain hiservice.store exists, points at REMOTE_PATH
#   3. cPanel: DB created (see schema.sql)
#   4. `brew install expect` (Mac) or `apt install expect`
# =====================================================

set -e

SSH_HOST="${SSH_HOST:-rs53.cphost.co.za}"
SSH_PORT="${SSH_PORT:-22000}"           # confirm — usually 22000 for cphost
SSH_USER="${SSH_USER:-hiserviceshopz}"  # guess based on DB prefix
SSH_PASS="${SSH_PASS:-}"                # required
REMOTE_PATH="${REMOTE_PATH:-/home/hiserviceshopz/public_html}"
ENV_REMOTE_PATH="${ENV_REMOTE_PATH:-/home/hiserviceshopz/.env}"

if [[ -z "$SSH_PASS" ]]; then
  echo "❌ SSH_PASS not set. Export it or source .env.local"
  exit 1
fi

LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "🚀 Deploying $LOCAL_DIR → $SSH_USER@$SSH_HOST:$REMOTE_PATH"

# Step 1: rsync code (excludes .env, logs, .git)
expect <<EOF
set timeout 120
spawn rsync -avz --delete \
  --exclude=".env" \
  --exclude=".env.local" \
  --exclude=".env.example" \
  --exclude="logs/*.log" \
  --exclude=".git" \
  --exclude="deploy.sh" \
  -e "ssh -p $SSH_PORT -o StrictHostKeyChecking=no" \
  "$LOCAL_DIR/" "$SSH_USER@$SSH_HOST:$REMOTE_PATH/"
expect "password:" { send "$SSH_PASS\r" }
expect eof
EOF

# Step 2: upload .env (separate, outside docroot)
if [[ -f "$LOCAL_DIR/../.env.local" ]]; then
  echo "🔐 Uploading .env to $ENV_REMOTE_PATH (one level above docroot)"
  expect <<EOF
set timeout 30
spawn scp -P $SSH_PORT -o StrictHostKeyChecking=no \
  "$LOCAL_DIR/../.env.local" "$SSH_USER@$SSH_HOST:$ENV_REMOTE_PATH"
expect "password:" { send "$SSH_PASS\r" }
expect eof
EOF
fi

# Step 3: post-deploy commands (apply schema, set permissions)
echo "🛠  Running post-deploy on remote"
expect <<EOF
set timeout 60
spawn ssh -p $SSH_PORT -o StrictHostKeyChecking=no "$SSH_USER@$SSH_HOST"
expect "password:" { send "$SSH_PASS\r" }
expect "\\\$ "
send "cd $REMOTE_PATH && chmod 644 *.php .htaccess && chmod 755 logs/ && chmod 600 ../.env 2>/dev/null; true\r"
expect "\\\$ "
send "ls -la\r"
expect "\\\$ "
send "exit\r"
expect eof
EOF

echo "✅ Deploy complete. Test: https://hiservice.store"
