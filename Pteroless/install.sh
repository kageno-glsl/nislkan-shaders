#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

log(){ printf '\033[1;36m[SimplePanel]\033[0m %s\n' "$*"; }
die(){ printf '\033[1;31m[SimplePanel] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || die "Run install.sh as root on a fresh VPS."
command -v apt-get >/dev/null 2>&1 || die "This installer currently supports Debian/Ubuntu (apt)."

export DEBIAN_FRONTEND=noninteractive

log "Installing required system packages..."
apt-get update
apt-get install -y ca-certificates curl unzip git openssl python3 python3-venv default-jre-headless golang-go

PHP_PACKAGES=(cli fpm sqlite3 mysql mbstring xml curl zip bcmath gd intl opcache)
has_php_packages(){
    local version="$1" package
    for package in "${PHP_PACKAGES[@]}"; do
        apt-cache show "php${version}-${package}" 2>/dev/null | grep '^Package:' >/dev/null || return 1
    done
}

PHPV=""
# Prefer the installed PHP version only when all required packages are available.
if command -v php >/dev/null 2>&1; then
    INSTALLED_PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    if [[ "$INSTALLED_PHPV" == "8.2" || "$INSTALLED_PHPV" == "8.3" || "$INSTALLED_PHPV" == "8.4" ]] \
        && has_php_packages "$INSTALLED_PHPV"; then
        PHPV="$INSTALLED_PHPV"
    fi
fi
if [[ -z "$PHPV" ]]; then
    for candidate in 8.4 8.3 8.2; do
        if has_php_packages "$candidate"; then PHPV="$candidate"; break; fi
    done
fi
[[ -n "$PHPV" ]] || die "Debian/Ubuntu repositories do not provide PHP 8.2, 8.3, or 8.4 on this VPS."
apt-get install -y "${PHP_PACKAGES[@]/#/php${PHPV}-}"

# Prefer APT-managed PHP over toolchain PHP installations earlier in PATH.
export PATH="/usr/bin:/bin:$PATH"

php -r 'exit(version_compare(PHP_VERSION, "8.2", ">=") && version_compare(PHP_VERSION, "8.5", "<") ? 0 : 1);' \
    || die "PHP 8.2-8.4 is required. Detected: $(php -r 'echo PHP_VERSION;')"

if ! command -v composer >/dev/null 2>&1; then
    log "Installing Composer..."
    EXPECTED_SIGNATURE="$(curl -fsSL https://composer.github.io/installer.sig)" \
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    [[ "$EXPECTED_SIGNATURE" == "$ACTUAL_SIGNATURE" ]] || { rm -f composer-setup.php; die "Composer installer signature mismatch."; }
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f composer-setup.php
fi

command -v composer >/dev/null 2>&1 || die "Composer installation failed."

# Node.js is only needed to compile the original Pterodactyl frontend.
if ! command -v node >/dev/null 2>&1 || ! node -e 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)'; then
    log "Installing Node.js 22 for the one-time frontend build..."
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
fi
command -v node >/dev/null 2>&1 || die "Node.js installation failed."
command -v npm >/dev/null 2>&1 || die "npm is required with Node.js 22+."

command -v python3 >/dev/null 2>&1 || die "Python 3 installation failed."
python3 -m venv --help >/dev/null 2>&1 || die "python3-venv is required for the Python runtime preset."
command -v java >/dev/null 2>&1 || die "Java installation failed."
java -version >/dev/null 2>&1 || die "Java could not start; check the installed JRE."
command -v go >/dev/null 2>&1 || die "Go installation failed."
go version >/dev/null 2>&1 || die "Go could not start; check the installed toolchain."

log "Preparing SQLite database..."
mkdir -p database
touch database/database.sqlite
chmod 664 database/database.sqlite

if [[ ! -f .env ]]; then cp .env.example .env; fi

APP_URL="${APP_URL:-http://127.0.0.1:8080}"
DB_FILE="$ROOT/database/database.sqlite"
export APP_URL DB_FILE
php -r '
$path=".env"; $s=file_get_contents($path); $s=preg_replace("/\\\\n(?=[A-Z_][A-Z0-9_]*=)/", "\n", $s);
$set=function($k,$v) use (&$s){$line=$k."=".$v; if(preg_match("/^".preg_quote($k,"/")."=.*/m",$s)) $s=preg_replace("/^".preg_quote($k,"/")."=.*/m",$line,$s); else $s.="\n".$line;};
if (!preg_match("/^APP_KEY=.+/m", $s)) $set("APP_KEY", "base64:".base64_encode(random_bytes(32)));
$set("APP_ENV","production"); $set("APP_DEBUG","false"); $set("APP_ENVIRONMENT_ONLY","false"); $set("APP_URL",getenv("APP_URL"));
$set("DB_CONNECTION","sqlite"); $set("DB_DATABASE",getenv("DB_FILE")); $set("DB_HOST",""); $set("DB_PORT",""); $set("DB_USERNAME",""); $set("DB_PASSWORD","");
$set("CACHE_DRIVER","file"); $set("CACHE_STORE","file"); $set("SESSION_DRIVER","file"); $set("QUEUE_CONNECTION","sync");
if (preg_match("/^MAIL_HOST=smtp\\.example\\.com$/m", $s)) $set("MAIL_MAILER", "log");
file_put_contents($path,$s);
'

log "Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

log "Generating application key and migrating database..."
if ! grep -qE "^APP_KEY=.+" .env; then php artisan key:generate --no-interaction; fi
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan route:clear
php artisan view:clear

log "Creating the first administrator account..."
ADMIN_COUNT="$(php -r '$pdo=new PDO("sqlite:".getenv("DB_FILE")); echo $pdo->query("SELECT COUNT(*) FROM users WHERE root_admin = 1")->fetchColumn();')"
if [[ "$ADMIN_COUNT" -gt 0 ]]; then
    log "An administrator account already exists; skipping account creation."
elif [[ -n "${ADMIN_EMAIL:-}" && -n "${ADMIN_USERNAME:-}" && -n "${ADMIN_PASSWORD:-}" ]]; then
    php artisan p:user:make --admin=1 --email="$ADMIN_EMAIL" --username="$ADMIN_USERNAME" \
        --name-first="${ADMIN_FIRST_NAME:-Admin}" --name-last="${ADMIN_LAST_NAME:-User}" \
        --password="$ADMIN_PASSWORD" --no-interaction
else
    php artisan p:user:make --admin=1
fi

log "Installing frontend dependencies and building the original Pterodactyl UI..."
if [[ -f yarn.lock ]]; then
    if ! command -v yarn >/dev/null 2>&1; then npm install --global yarn; fi
    yarn install --frozen-lockfile --non-interactive
    yarn run build:production
else
    npm ci
    npm run build:production
fi

mkdir -p storage/app/servers storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache
chmod -R u+rwX,g+rwX storage bootstrap/cache
# The built-in development server and local runner must never execute as root.
chown -R www-data:www-data "$ROOT"
chmod 755 "$ROOT"

# Keep the generated database credentials available for start-time diagnostics without exposing them in output.
cat > .simplepanel-installed <<EOF
APP_URL=$APP_URL
DB_CONNECTION=sqlite
DB_DATABASE=$DB_FILE
EOF
chmod 600 .simplepanel-installed

log "Creating local runner storage..."
mkdir -p storage/app/servers
chown www-data:www-data storage/app/servers

log "Installation complete."
printf '\nPanel URL: %s\n' "$APP_URL"
printf 'Start with: ./start\n\n'
