<?php

namespace Pterodactyl\Services\Local;

use Pterodactyl\Models\Server;
use RuntimeException;

class LocalProcessManager
{
    public function __construct(private LocalServerService $local)
    {
    }

    private function pidFile(Server $server): string { return $this->local->metaPath($server) . '/pid'; }
    private function logFile(Server $server): string { return $this->local->metaPath($server) . '/logs/output.log'; }
    private function fifo(Server $server): string { return $this->local->metaPath($server) . '/stdin.fifo'; }

    public function pid(Server $server): int
    {
        $this->local->ensureDirectory($server);
        if (!is_file($this->pidFile($server))) return 0;
        $pid = (int) trim((string) file_get_contents($this->pidFile($server)));
        if ($pid < 1 || !$this->alive($pid)) {
            @unlink($this->pidFile($server));
            return 0;
        }
        return $pid;
    }

    private function alive(int $pid): bool
    {
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/' . $pid);
    }

    public function isRunning(Server $server): bool { return $this->pid($server) > 0; }

    public function start(Server $server): int
    {
        $this->local->ensureDirectory($server);
        if ($this->isRunning($server)) return $this->pid($server);

        $command = trim($server->startup);
        if ($command === '') throw new RuntimeException('Startup command is empty.');

        $fifo = $this->fifo($server);
        if (file_exists($fifo)) @unlink($fifo);
        if (!function_exists('posix_mkfifo') || !@posix_mkfifo($fifo, 0600)) {
            throw new RuntimeException('Unable to create the local command pipe.');
        }

        $root = escapeshellarg($this->local->path($server));
        $log = escapeshellarg($this->logFile($server));
        $pipe = escapeshellarg($fifo);
        $memory = (int) $server->memory;
        $port = (int) ($server->allocation?->port ?? 0);
        $portEnvironment = $port > 0 ? 'export PORT=' . $port . '; export SERVER_PORT=' . $port . '; ' : '';
        $cpuSet = trim((string) $server->threads);
        $limits = '';
        $usesNode = preg_match('/\b(?:node|nodejs|npm|npx|yarn|pnpm)\b/i', $command) === 1;
        $usesJava = preg_match('/\bjava\b/i', $command) === 1;
        $usesGo = preg_match('/\bgo\b/i', $command) === 1;
        if ($memory > 0 && $usesNode) {
            $heapLimit = max(64, min(4096, (int) floor($memory * 0.7)));
            $nodeOptions = trim((string) getenv('NODE_OPTIONS') . ' --max-old-space-size=' . $heapLimit);
            $limits .= 'export NODE_OPTIONS=' . escapeshellarg($nodeOptions) . '; ';
        } elseif ($memory > 0 && $usesJava) {
            $heapLimit = max(64, min(4096, (int) floor($memory * 0.7)));
            $javaOptions = trim((string) getenv('JAVA_TOOL_OPTIONS') . ' -Xmx' . $heapLimit . 'm');
            $limits .= 'export JAVA_TOOL_OPTIONS=' . escapeshellarg($javaOptions) . '; ';
        } elseif ($memory > 0 && $usesGo) {
            $memoryLimit = max(64, (int) floor($memory * 0.7));
            $limits .= 'export GOMEMLIMIT=' . escapeshellarg($memoryLimit . 'MiB') . '; ';
        } elseif ($memory > 0) {
            $limits .= 'ulimit -v ' . ($memory * 1024) . '; ';
        }
        $limits .= 'ulimit -n 4096; ';
        $limits .= 'ulimit -u 256; ';
        $taskset = $cpuSet !== '' && preg_match('/^[0-9,-]+$/', $cpuSet) ? 'taskset -c ' . escapeshellarg($cpuSet) . ' ' : '';
        $quoted = escapeshellarg($command);
        $home = escapeshellarg($this->local->metaPath($server) . '/home');

        $shell = "cd {$root}; mkdir -p {$home}; export HOME={$home}; {$portEnvironment}{$limits} exec {$taskset}bash -lc {$quoted} <>{$pipe} >>{$log} 2>&1";
        $launch = 'nohup setsid bash -lc ' . escapeshellarg($shell) . ' </dev/null >/dev/null 2>&1 & echo $!';
        $output = [];
        $exit = 0;
        exec($launch, $output, $exit);
        $pid = isset($output[0]) ? (int) trim($output[0]) : 0;
        if ($exit !== 0 || $pid < 1) {
            @unlink($fifo);
            throw new RuntimeException('Unable to start the local process.');
        }

        file_put_contents($this->pidFile($server), (string) $pid, LOCK_EX);
        file_put_contents($this->logFile($server), "\n===== START " . date(DATE_ATOM) . " =====\n", FILE_APPEND | LOCK_EX);
        return $pid;
    }

    public function stop(Server $server, bool $force = false): void
    {
        $pid = $this->pid($server);
        if ($pid < 1) return;
        $signal = $force ? 'KILL' : 'TERM';
        @exec('kill -' . $signal . ' -- -' . $pid . ' 2>/dev/null');
        usleep(300000);
        if (!$force && $this->alive($pid)) @exec('kill -KILL -- -' . $pid . ' 2>/dev/null');
        @unlink($this->pidFile($server));
        @unlink($this->fifo($server));
    }

    public function restart(Server $server): int
    {
        $this->stop($server);
        return $this->start($server);
    }

    public function command(Server $server, string $command): void
    {
        if (!$this->isRunning($server)) throw new RuntimeException('Server must be running.');
        $fifo = $this->fifo($server);
        if (!file_exists($fifo) || @filetype($fifo) !== 'fifo') throw new RuntimeException('Command pipe is unavailable.');
        $handle = @fopen($fifo, 'wb');
        if (!$handle) throw new RuntimeException('Unable to send command to the process.');
        fwrite($handle, $command . PHP_EOL);
        fclose($handle);
    }

    public function logs(Server $server, int $bytes = 50000): string
    {
        $this->local->ensureDirectory($server);
        $file = $this->logFile($server);
        if (!is_file($file)) return '';
        $size = filesize($file);
        if ($size === false || $size <= $bytes) return (string) file_get_contents($file);
        $handle = fopen($file, 'rb');
        if (!$handle) return '';
        fseek($handle, -$bytes, SEEK_END);
        $data = stream_get_contents($handle);
        fclose($handle);
        return $data === false ? '' : $data;
    }

    public function stats(Server $server): array
    {
        $pid = $this->pid($server);
        $running = $pid > 0;
        $memory = 0;
        $cpu = 0.0;
        $uptime = 0;
        if ($running && is_file("/proc/{$pid}/status")) {
            $status = file_get_contents("/proc/{$pid}/status") ?: '';
            if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $m)) $memory = (int) $m[1] * 1024;
            if (preg_match('/^State:\s+(\w)/m', $status, $m) && $m[1] === 'Z') $running = false;
        }
        if ($running && is_file("/proc/{$pid}/stat")) {
            $stat = file_get_contents("/proc/{$pid}/stat") ?: '';
            $parts = preg_split('/\s+/', trim($stat));
            if (isset($parts[21], $parts[13], $parts[14])) {
                $hz = (int) shell_exec('getconf CLK_TCK 2>/dev/null') ?: 100;
                $start = (int) $parts[21] / $hz;
                $now = microtime(true);
                $boot = (float) @file_get_contents('/proc/uptime');
                $elapsed = max(0.001, $boot - $start);
                $cpu = (((int)$parts[13] + (int)$parts[14]) / $hz) / $elapsed * 100;
                $uptime = (int) ($elapsed * 1000);
            }
        }
        return [
            'state' => $running ? 'running' : 'offline',
            'is_suspended' => false,
            'utilization' => [
                'memory_bytes' => $memory,
                'cpu_absolute' => round($cpu, 2),
                'disk_bytes' => $this->diskUsage($this->local->path($server)),
                'network' => ['rx_bytes' => 0, 'tx_bytes' => 0],
                'uptime' => $uptime,
            ],
        ];
    }

    private function diskUsage(string $path): int
    {
        $out = [];
        exec('du -sb ' . escapeshellarg($path) . ' 2>/dev/null', $out);
        return isset($out[0]) && preg_match('/^(\d+)/', $out[0], $m) ? (int) $m[1] : 0;
    }
}
