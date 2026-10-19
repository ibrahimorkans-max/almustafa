#!/usr/bin/env bash
# One-time (idempotent) server setup for Rice Mill ERP on Ubuntu 24.04.
# Run as root from a checkout of this repository.
#
# Internet server (VPS), certificate from Let's Encrypt:
#   DOMAIN=erp.example.in ADMIN_EMAIL=you@example.in DEPLOY_KEY="ssh-ed25519 AAAA..." bash infra/vps/provision.sh
#
# Mill server (a PC inside the mill; works with NO internet), certificate from the mill's own authority:
#   TLS_MODE=local DOMAIN=ricemill.local bash infra/vps/provision.sh
#   Every PC and phone of the mill opens https://ricemill.local (or https://<fixed IP>); install the mill
#   certificate once on each from http://ricemill.local/mill-ca.crt. See docs/mill-server.md.
# Re-running is safe; it only fills in what is missing. Generated secrets go to /etc/ricemill only.
set -euo pipefail

TLS_MODE="${TLS_MODE:-letsencrypt}"          # letsencrypt (internet server) | local (mill server, no internet needed)
: "${DOMAIN:?set DOMAIN (e.g. erp.example.in, or ricemill.local for a mill server)}"
if [[ "$TLS_MODE" == "letsencrypt" ]]; then
  : "${ADMIN_EMAIL:?set ADMIN_EMAIL (for the TLS certificate)}"
  : "${DEPLOY_KEY:?set DEPLOY_KEY (public SSH key used by the deploy workflow)}"
elif [[ "$TLS_MODE" != "local" ]]; then
  echo "TLS_MODE must be letsencrypt or local." >&2; exit 1
fi
DEPLOY_KEY="${DEPLOY_KEY:-}"
TLS_DIR=/etc/ricemill/tls
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
  unattended-upgrades logrotate poppler-utils avahi-daemon chrony \
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
if [[ -n "$DEPLOY_KEY" ]]; then
  grep -qF "$DEPLOY_KEY" "/home/$APP_USER/.ssh/authorized_keys" || echo "$DEPLOY_KEY" >> "/home/$APP_USER/.ssh/authorized_keys"
fi
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
if [[ "$TLS_MODE" == "letsencrypt" ]]; then
  if [[ ! -d "/etc/letsencrypt/live/${DOMAIN}" ]]; then
    systemctl stop nginx
    certbot certonly --standalone -n --agree-tos -m "$ADMIN_EMAIL" -d "$DOMAIN"
  fi
  CERT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
  KEY="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"
else
  # The mill's own certificate authority (10 years) and a server certificate (2 years, renewed by
  # re-running this script) for the name and the fixed IP. Devices trust it once mill-ca.crt is installed.
  LAN_IP="${LAN_IP:-$(hostname -I | awk '{print $1}')}"
  install -d -m 750 -o root -g root "$TLS_DIR"
  if [[ ! -f "$TLS_DIR/mill-ca.key" ]]; then
    openssl req -x509 -newkey rsa:3072 -sha256 -days 3650 -nodes -keyout "$TLS_DIR/mill-ca.key" -out "$TLS_DIR/mill-ca.crt" \
      -subj "/O=Rice Mill ERP/CN=Rice Mill ERP mill certificate authority" \
      -addext "basicConstraints=critical,CA:TRUE,pathlen:0" -addext "keyUsage=critical,keyCertSign,cRLSign"
    chmod 600 "$TLS_DIR/mill-ca.key"
  fi
  if [[ ! -f "$TLS_DIR/server.crt" ]] || ! openssl x509 -checkend 2592000 -noout -in "$TLS_DIR/server.crt" >/dev/null \
     || ! openssl x509 -noout -text -in "$TLS_DIR/server.crt" | grep -q "IP Address:${LAN_IP}"; then
    openssl req -newkey rsa:2048 -nodes -keyout "$TLS_DIR/server.key" -out "$TLS_DIR/server.csr" -subj "/CN=${DOMAIN}"
    printf 'subjectAltName=DNS:%s,DNS:%s,IP:%s,IP:127.0.0.1\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' \
      "$DOMAIN" "${DOMAIN%%.*}" "$LAN_IP" > "$TLS_DIR/server.ext"
    openssl x509 -req -in "$TLS_DIR/server.csr" -CA "$TLS_DIR/mill-ca.crt" -CAkey "$TLS_DIR/mill-ca.key" -CAcreateserial \
      -days 730 -sha256 -extfile "$TLS_DIR/server.ext" -out "$TLS_DIR/server.crt"
    chmod 600 "$TLS_DIR/server.key"
  fi
  CERT="$TLS_DIR/server.crt"
  KEY="$TLS_DIR/server.key"
  # The CA certificate is public: devices download it over plain HTTP to install it.
  install -m 644 "$TLS_DIR/mill-ca.crt" /var/www/html/mill-ca.crt
  # The server itself trusts it too (the deploy health check calls https://${DOMAIN}).
  install -m 644 "$TLS_DIR/mill-ca.crt" /usr/local/share/ca-certificates/ricemill-mill-ca.crt
  update-ca-certificates >/dev/null
  # "ricemill.local" on the mill network without a DNS server (mDNS): the PC's name is the first label.
  hostnamectl set-hostname "${DOMAIN%%.*}"
  systemctl enable --now avahi-daemon
  # No internet, no time servers: chrony keeps the clock (hardware clock) and uses servers when online.
  systemctl enable --now chrony
fi
sed -e "s/{{DOMAIN}}/${DOMAIN}/g" -e "s/{{PHP}}/${PHP}/g" -e "s#{{CERT}}#${CERT}#g" -e "s#{{KEY}}#${KEY}#g" \
  "$HERE/nginx-ricemill.conf" > /etc/nginx/sites-available/ricemill
ln -sf /etc/nginx/sites-available/ricemill /etc/nginx/sites-enabled/ricemill
nginx -t
systemctl restart nginx
if [[ "$TLS_MODE" == "letsencrypt" ]]; then
  # Renewals use the running nginx (webroot under /var/www/html for the ACME challenge).
  sed -i 's/^authenticator = standalone/authenticator = webroot\nwebroot_path = \/var\/www\/html/' "/etc/letsencrypt/renewal/${DOMAIN}.conf" || true
  mkdir -p /etc/letsencrypt/renewal-hooks/deploy
  printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
  chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
fi

echo "== queue worker, scheduler, log rotation"
cp "$HERE/supervisor-ricemill.conf" /etc/supervisor/conf.d/ricemill.conf
supervisorctl reread
supervisorctl update
cp "$HERE/cron-ricemill" /etc/cron.d/ricemill
chmod 644 /etc/cron.d/ricemill
cp "$HERE/logrotate-ricemill" /etc/logrotate.d/ricemill

if [[ "$TLS_MODE" == "local" ]]; then
  # A mill server: open to the mill network only; nothing has to come in from the internet.
  ufw delete allow 'Nginx Full' >/dev/null 2>&1 || true
  for net in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16; do
    ufw allow from "$net" to any port 80,443 proto tcp >/dev/null
  done
  sed -i -e 's/^RICEMILL_SA_REQUIRE_IP_ALLOWLIST=.*/RICEMILL_SA_REQUIRE_IP_ALLOWLIST=false/' "$ENV_DIR/ricemill.env" || true
  if grep -q '^RICEMILL_MILL_SERVER=' "$ENV_DIR/ricemill.env"; then
    sed -i 's/^RICEMILL_MILL_SERVER=.*/RICEMILL_MILL_SERVER=true/' "$ENV_DIR/ricemill.env"
  else
    echo 'RICEMILL_MILL_SERVER=true' >> "$ENV_DIR/ricemill.env"
  fi
  echo
  echo "Mill server ready at https://${DOMAIN} and https://${LAN_IP}"
  echo "  Install the mill certificate on every PC and phone: http://${DOMAIN}/mill-ca.crt (docs/mill-server.md)."
fi

echo
echo "Done. Next:"
echo "  1. Edit $ENV_DIR/ricemill.env: mail, off-site backup disk, anchor e-mail."
echo "  2. Run the GitHub 'Deploy' workflow (or as $APP_USER: REPO=<git url> bash deploy.sh <git-ref>)."
echo "  3. php artisan ricemill:preflight must show no FAIL lines."
