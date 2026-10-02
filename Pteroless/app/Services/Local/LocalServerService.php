<?php

namespace Pterodactyl\Services\Local;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Egg;

class LocalServerService
{
    public const STARTUP_PRESETS = [
        'nodejs' => 'if [ -f package.json ]; then npm install && npm start; else exec node index.js; fi',
        'nextjs' => 'if [ -f package.json ]; then npm install && npm run build && npm run start; else echo "package.json not found"; exit 1; fi',
        'python' => 'if [ -f requirements.txt ]; then python3 -m venv .venv && .venv/bin/pip install -r requirements.txt && exec .venv/bin/python main.py; else exec python3 main.py; fi',
        'java' => 'if [ -f server.jar ]; then exec java -jar server.jar; else echo "server.jar not found"; exit 1; fi',
        'php' => 'if [ -f composer.json ]; then composer install --no-interaction --prefer-dist || exit 1; fi; if [ -f artisan ]; then exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"; elif [ -f index.php ]; then if [ -d public ]; then exec php -S 0.0.0.0:"${PORT:-8000}" -t public; else exec php -S 0.0.0.0:"${PORT:-8000}"; fi; else echo "artisan or index.php not found"; exit 1; fi',
        'go' => 'if [ -f go.mod ]; then go mod download && exec go run .; elif [ -f main.go ]; then exec go run main.go; else echo "go.mod or main.go not found"; exit 1; fi',
        'custom' => '',
    ];

    public function ensureNode(): Node
    {
        $location = Location::query()->firstOrCreate(
            ['short' => 'local'],
            ['long' => 'Local']
        );

        $node = Node::query()->where('name', 'Local')->first();
        if ($node) {
            return $node;
        }

        $node = new Node();
        $node->forceFill([
            'uuid' => (string) Str::uuid(),
            'public' => true,
            'name' => 'Local',
            'location_id' => $location->id,
            'description' => 'Built-in local runner. Wings is not used.',
            'fqdn' => '127.0.0.1',
            'scheme' => 'http',
            'behind_proxy' => false,
            'memory' => 1048576,
            'memory_overallocate' => 0,
            'disk' => 1048576,
            'disk_overallocate' => 0,
            'upload_size' => 1024,
            'daemon_token_id' => Str::random(Node::DAEMON_TOKEN_ID_LENGTH),
            'daemon_token' => Crypt::encrypt(Str::random(Node::DAEMON_TOKEN_LENGTH)),
            'daemonListen' => 1,
            'daemonSFTP' => 1,
            'daemonBase' => '/var/lib/pterodactyl/volumes',
            'maintenance_mode' => false,
        ])->skipValidation();
        $node->save();
        return $node;
    }

    /**
     * Create the hidden compatibility Nest/Egg records required by the
     * original Pterodactyl Server schema. They are not used to execute
     * anything; the local runner uses Server::$startup directly.
     */
    private function ensureTemplate(string $runtime): array
    {
        $runtimes = [
            'nodejs' => ['name' => 'Node.js', 'description' => 'Node.js application running with the local process runner.'],
            'nextjs' => ['name' => 'Next.js', 'description' => 'Next.js application built and started with the local process runner.'],
            'python' => ['name' => 'Python', 'description' => 'Python application running with the local process runner.'],
            'java' => ['name' => 'Java/JAR', 'description' => 'Java application launched from a local JAR file.'],
            'php' => ['name' => 'PHP', 'description' => 'PHP application running with the local process runner.'],
            'go' => ['name' => 'Go', 'description' => 'Go application running with the local process runner.'],
            'custom' => ['name' => 'Custom', 'description' => 'Custom command running with the local process runner.'],
        ];
        if (!isset($runtimes[$runtime])) {
            throw new \InvalidArgumentException('Unsupported local runtime.');
        }

        $nest = Nest::query()->where('name', 'Pteroless Local')->first();
        if (!$nest) {
            $nest = new Nest();
            $nest->forceFill([
                'uuid' => (string) Str::uuid(),
                'author' => 'local@pteroless.invalid',
                'name' => 'Pteroless Local',
                'description' => 'Internal compatibility record for the local process runner.',
            ])->skipValidation();
            $nest->save();
        }

        $template = $runtimes[$runtime];
        $egg = Egg::query()->where('nest_id', $nest->id)->where('name', $template['name'])->first();
        if (!$egg) {
            $egg = new Egg();
            $egg->forceFill([
                'uuid' => (string) Str::uuid(),
                'nest_id' => $nest->id,
                'author' => 'local@pteroless.invalid',
                'name' => $template['name'],
                'description' => $template['description'],
                'features' => [],
                'docker_images' => ['local' => 'local'],
                'update_url' => null,
                'force_outgoing_ip' => false,
                'file_denylist' => [],
                'config_files' => null,
                'config_startup' => null,
                'config_logs' => null,
                'config_stop' => null,
                'config_from' => null,
                'startup' => null,
                'script_is_privileged' => false,
                'script_install' => null,
                'script_entry' => 'bash',
                'script_container' => 'local',
                'copy_script_from' => null,
            ])->skipValidation();
            $egg->save();
        }

        return [$nest, $egg];
    }

    public function create(array $data): Server
    {
        $node = $this->ensureNode();
        [$nest, $egg] = $this->ensureTemplate($data['runtime'] ?? 'nodejs');

        $allocation = Allocation::query()
            ->where('node_id', $node->id)
            ->whereNull('server_id')
            ->first();

        if (!$allocation) {
            $port = 25565;
            while (Allocation::query()->where('ip', '127.0.0.1')->where('port', $port)->exists()) {
                $port++;
            }
            $allocation = Allocation::query()->create([
                'node_id' => $node->id,
                'ip' => '127.0.0.1',
                'port' => $port,
                'ip_alias' => null,
            ]);
        }

        $uuid = (string) Str::uuid();
        $server = Server::query()->create([
            'external_id' => null,
            'uuid' => $uuid,
            'uuidShort' => substr(str_replace('-', '', $uuid), 0, 8),
            'node_id' => $node->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'status' => null,
            'skip_scripts' => true,
            'owner_id' => (int) $data['owner_id'],
            'memory' => (int) $data['memory'],
            'swap' => 0,
            'disk' => (int) ($data['disk'] ?? 0),
            'io' => 500,
            'cpu' => (int) ($data['cpu'] ?? 0),
            'threads' => $data['threads'] ?? null,
            'oom_disabled' => true,
            'allocation_id' => $allocation->id,
            'nest_id' => $nest->id,
            'egg_id' => $egg->id,
            'startup' => trim($data['startup']),
            'image' => 'local',
            'database_limit' => 0,
            'allocation_limit' => 0,
            'backup_limit' => 0,
            'installed_at' => now(),
        ]);

        $allocation->update(['server_id' => $server->id]);
        $this->ensureDirectory($server);

        return $server->fresh();
    }

    public static function isLocalRunner(Server $server): bool
    {
        return $server->image === 'local' && $server->egg?->author === 'local@pteroless.invalid';
    }

    public function path(Server $server): string
    {
        return storage_path('app/servers/' . $server->uuid);
    }

    public function metaPath(Server $server): string
    {
        return $this->path($server) . '/.local';
    }

    public function ensureDirectory(Server $server): void
    {
        $root = $this->path($server);
        foreach ([$root, $this->metaPath($server), $this->metaPath($server) . '/logs'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException("Unable to create local server directory: {$dir}");
            }
        }
    }
}
