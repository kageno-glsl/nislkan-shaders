# Pteroless — SQLite / VPS quick start

This build keeps the original Pterodactyl panel frontend and uses SQLite for the panel database.
The panel runner does not require Wings or a systemd service.

## Ubuntu / Debian VPS

```bash
unzip Pteroless-final-sqlite.zip
cd Pteroless
chmod +x install.sh start
sudo ./install.sh
sudo ./start
```

Default bind address:

```text
0.0.0.0:8080
```

Open `http://SERVER_IP:8080` or put Nginx/Cloudflare in front of it.

## Required runtime packages

The installer handles these automatically on supported Debian/Ubuntu systems:

- PHP 8.2–8.4
- PHP SQLite/PDO SQLite
- PHP CLI/FPM extensions: mbstring, XML, curl, zip, bcmath, GD, intl, opcache
- Composer
- Node.js 22 + npm (used to build the original frontend)
- Git, curl, unzip, openssl

MariaDB/MySQL is **not required** for the panel database in this build.
Wings and systemd are also not required by the local panel runner.

## SQLite database

The installer creates:

```text
database/database.sqlite
```

and writes:

```env
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/Pteroless/database/database.sqlite
```

## PHP 8.4 note

PHP 8.4 is accepted by this build. The installer uses the PHP version already installed when it is 8.2, 8.3, or 8.4.

## Important

The panel still contains the original Pterodactyl frontend and its existing MySQL/MariaDB connection definitions for compatibility. SQLite is simply the default connection used by this build.
