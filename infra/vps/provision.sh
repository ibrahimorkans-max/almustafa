#!/usr/bin/env bash
# One-time (idempotent) server setup for Rice Mill ERP on Ubuntu 24.04 (Hostinger VPS).
# Run as root from a checkout of this repository:
#   DOMAIN=erp.example.in ADMIN_EMAIL=you@example.in DEPLOY_KEY="ssh-ed25519 AAAA..." bash infra/vps/provision.sh
# Re-running is safe; it only fills in what is missing. Generated secrets go to /etc/ricemill only.
set -euo pipefail

: "${DOMAIN:?set DOMAIN (e.g. erp.example.in)}"
: "${ADMIN_EMAIL:?set ADMIN_EMAIL (for the TLS certificate)}"
: "${DEPLOY_KEY:?set DEPLOY_KEY (public SSH key used by the deploy workflow)}"
APP_USER=ricemill
APP_ROOT=/srv/ricemill
ENV_DIR=/etc/ricemill
PHP=8.3
HERE="$(cd "$(dirname "$0")" && pwd)"

[[ $EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
export DEBIAN_FRONTEND=noninteractive

echo "== packages"
apt-get update -q
apt-get install -yq software-properties-common ca-certificates curl gnupg unzip git acl openssl
if ! apt-cache policy "php${PHP}-fpm" | grep -q 'Candidate: [0-9]'; then
  add-apt-repository -y ppa:ondrej/php
  apt-get update -q
fi
apt-get install -yq nginx certbot python3-certbot-nginx postgresql postgresql-contrib supervisor ufw fail2ban \
  unattended-upgrades logrotate \
  "php${PHP}-fpm" "php${PHP}-cli" "php${PHP}-pgsql" "php${PHP}-intl" "php${PHP}-bcmath" "php${PHP}-mbstring" \
  "php${PHP}-xml" "php${PHP}-zip" "php${PHP}-gd" "php${PHP}-curl" "php${PHP}-opcache"
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

echo "== automatic security updates"
dpkg-reconfigure -f noninteractive unattended-upgrades

echo "== swap (2 GB) for composer and pg_dump on small plans"
if ! swapon --show | grep -q /swapfile; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q /swapfile /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "== application user (SSH keys only)"
id "$APP_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "Rice Mill ERP" "$APP_USER"
install -d -m 700 -o "$APP_USER" -g "$APP_USER" "/home/$APP_USER/.ssh"
touch "/home/$APP_USER/.ssh/authorized_keys"
grep -qF "$DEPLOY_KEY" "/home/$APP_USER/.ssh/authorized_keys" || echo "$DEPLOY_KEY" >> "/home/$APP_USER/.ssh/authorized_keys"
chown "$APP_USER:$APP_USER" "/home/$APP_USER/.ssh/authorized_keys"
chmod 600 "/home/$APP_USER/.ssh/authorized_keys"
# The deploy user may reload PHP-FPM and restart the queue worker, nothing else.
printf '%s ALL=(root) NOPASSWD: /usr/bin/systemctl reload php%s-fpm, /usr/bin/supervisorctl restart ricemill-worker\\:*\n' \
  "$APP_USER" "$PHP" > /etc/sudoers.d/ricemill
chmod 440 /etc/sudoers.d/ricemill
visudo -cf /etc/sudoers.d/ricemill

echo "== SSH: keys only"
printf '%s\n' 'PasswordAuthentication no' 'KbdInteractiveAuthentication no' 'PermitRootLogin prohibit-password' \
  > /etc/ssh/sshd_config.d/10-ricemill.conf
systemctl reload ssh 2>/dev/null || systemctl reload sshd

echo "== firewall + fail2ban"
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
cp "$HERE/fail2ban-ricemill.local" /etc/fail2ban/jail.d/ricemill.local
systemctl restart fail2ban

echo "== directories"
install -d -m 755 -o "$APP_USER" -g "$APP_USER" "$APP_ROOT" "$APP_ROOT/releases" "$APP_ROOT/shared" "$APP_ROOT/shared/storage"
for d in app/private/files app/public framework/cache/data framework/sessions framework/views logs backups; do
  install -d -m 750 -o "$APP_USER" -g "$APP_USER" "$APP_ROOT/shared/storage/$d"
done
install -d -m 750 -o root -g "$APP_USER" "$ENV_DIR"

if [[ ! -f "$ENV_DIR/ricemill.env" ]]; then
  echo "== PostgreSQL: application role WITHOUT superuser / BYPASSRLS (superusers ignore row-level security)"
  DB_PASS="$(openssl rand -hex 24)"
  sudo -u postgres psql -v ON_ERROR_STOP=1 -q -v pass="$DB_PASS" <<'SQL'
SELECT format('CREATE ROLE ricemill LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB PASSWORD %L', :'pass')
  WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'ricemill') \gexec
SELECT format('ALTER ROLE ricemill PASSWORD %L', :'pass') \gexec
SQL
  for db in ricemill ricemill_drill; do
    sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$db'" | grep -q 1 || sudo -u postgres createdb -O ricemill "$db"
    sudo -u postgres psql -q -d "$db" -c "CREATE EXTENSION IF NOT EXISTS pgcrypto; CREATE EXTENSION IF NOT EXISTS citext;"
  done

  echo "== secrets file $ENV_DIR/ricemill.env (root:$APP_USER 0640, never in Git)"
  umask 027
  sed -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
      -e "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" \
      -e "s|^RICEMILL_BACKUP_KEY=.*|RICEMILL_BACKUP_KEY=$(openssl rand -base64 32)|" \
      -e "s|^RICEMILL_BLIND_INDEX_KEY=.*|RICEMILL_BLIND_INDEX_KEY=$(openssl rand -base64 32)|" \
      "$HERE/ricemill.env.example" > "$ENV_DIR/ricemill.env"
  chown root:"$APP_USER" "$ENV_DIR/ricemill.env"
  chmod 640 "$ENV_DIR/ricemill.env"
  umask 022
  echo "!! Copy RICEMILL_BACKUP_KEY from $ENV_DIR/ricemill.env to a safe place OFF this server now."
  echo "!! Without it the backups cannot be restored."
fi

echo "== PHP-FPM pool + OPcache"
sed "s/{{PHP}}/${PHP}/g" "$HERE/php-fpm-ricemill.conf" > "/etc/php/${PHP}/fpm/pool.d/ricemill.conf"
rm -f "/etc/php/${PHP}/fpm/pool.d/www.conf"
cp "$HERE/php-ricemill.ini" "/etc/php/${PHP}/fpm/conf.d/90-ricemill.ini"
cp "$HERE/php-ricemill.ini" "/etc/php/${PHP}/cli/conf.d/90-ricemill.ini"
systemctl restart "php${PHP}-fpm"

echo "== TLS certificate + nginx"
rm -f /etc/nginx/sites-enabled/default
if [[ ! -d "/etc/letsencrypt/live/${DOMAIN}" ]]; then
  systemctl stop nginx
  certbot certonly --standalone -n --agree-tos -m "$ADMIN_EMAIL" -d "$DOMAIN"
fi
sed -e "s/{{DOMAIN}}/${DOMAIN}/g" -e "s/{{PHP}}/${PHP}/g" "$HERE/nginx-ricemill.conf" > /etc/nginx/sites-available/ricemill
ln -sf /etc/nginx/sites-available/ricemill /etc/nginx/sites-enabled/ricemill
nginx -t
systemctl restart nginx
# Renewals use the running nginx (webroot under /var/www/html for the ACME challenge).
sed -i 's/^authenticator = standalone/authenticator = webroot\nwebroot_path = \/var\/www\/html/' "/etc/letsencrypt/renewal/${DOMAIN}.conf" || true
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx

echo "== queue worker, scheduler, log rotation"
cp "$HERE/supervisor-ricemill.conf" /etc/supervisor/conf.d/ricemill.conf
supervisorctl reread
supervisorctl update
cp "$HERE/cron-ricemill" /etc/cron.d/ricemill
chmod 644 /etc/cron.d/ricemill
cp "$HERE/logrotate-ricemill" /etc/logrotate.d/ricemill

echo
echo "Done. Next:"
echo "  1. Edit $ENV_DIR/ricemill.env: mail, off-site backup disk, anchor e-mail."
echo "  2. Run the GitHub 'Deploy' workflow (or as $APP_USER: REPO=<git url> bash deploy.sh <git-ref>)."
echo "  3. php artisan ricemill:preflight must show no FAIL lines."
