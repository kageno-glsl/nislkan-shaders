# Pteroless — local runner mode

This fork keeps the original Pterodactyl Panel source tree and frontend. It removes the **runtime dependency on Wings** for local servers: server power, console commands, logs, resource polling, and file operations are handled by the panel's local process runner.

## What remains

- Original Pterodactyl React UI and Laravel application structure.
- Original authentication, permissions, server model, dashboard, and file-manager UI.
- Existing Pterodactyl database migrations and seed data.

## What changes

- `/api/client/servers/*` core power/command/resources/logs/files use the local runner.
- Websocket UI contract is emulated by an HTTP polling adapter, so no WebSocket daemon is required.
- Creating a server from **Admin → Servers → Create** uses a built-in local runner configuration. No node/egg/Wings setup is required from the user.
- Local runtime presets include Node.js, Next.js, Python, Java/JAR, PHP, Go, and Custom. `install.sh` installs the system runtimes needed by these presets.
- Startup command is arbitrary host command such as `node index.js`, `python3 main.py`, `npm run start`, or `bash start.sh`.
- Server files live under `storage/app/servers/<uuid>`.

## Important security model

The local runner executes commands with the operating-system account running the panel. It is **not a container boundary**. Per-server directories are isolated by the panel API, and process groups are isolated for stop/restart, but a public multi-tenant deployment should run each project under a dedicated Unix user and cgroup before being exposed to untrusted users.

The runner applies best-effort `ulimit` memory/process limits and optional CPU pinning. The stored CPU percentage is not a hard cgroup CPU quota yet.

## Start for development

```bash
./start-simple
```

Set `HOST` and `PORT` if needed:

```bash
HOST=0.0.0.0 PORT=8080 ./start-simple
```
