# Pteroless — run guide

This is based on the original Pterodactyl Panel source. The original React UI is kept; local server power/console/resources/files are routed to the built-in local runner instead of Wings.

## VPS

On a clean Debian/Ubuntu VPS:

```bash
chmod +x install.sh start
sudo ./install.sh
./start
```

The installer requires PHP 8.2/8.3 and Node.js 22+ for the frontend build. Node is only a build dependency; project processes can be Node, Python, Bash, npm, Bun, etc.

Default listener:

```text
http://0.0.0.0:8080
```

For a private test, use `HOST=127.0.0.1`. For a Codespace, use `HOST=0.0.0.0` and forward port 8080.

## Important

The local runner executes project commands as the OS account running the panel. This is intentionally not a Docker/Wings isolation boundary. Do not expose arbitrary-user project execution to untrusted users until a dedicated Unix-user/cgroup sandbox is added.
