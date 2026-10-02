<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Local\LocalServerService;
use ZipArchive;

class LocalFileController extends ClientApiController
{
    public function __construct(private LocalServerService $local) { parent::__construct(); }

    private function root(Server $server): string { $this->local->ensureDirectory($server); return realpath($this->local->path($server)); }

    private function safe(Server $server, string $path = '/'): string
    {
        $root = $this->root($server);
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $candidate = $root . $path;
        if (file_exists($candidate) || is_link($candidate)) {
            $real = realpath($candidate);
            abort_unless($real !== false && ($real === $root || str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)), 422, 'Unsafe path.');
            abort_if(is_link($candidate), 422, 'Symbolic links are disabled.');
            return $real;
        }
        $parent = realpath(dirname($candidate));
        abort_unless($parent !== false && ($parent === $root || str_starts_with($parent . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)), 422, 'Unsafe path.');
        return $candidate;
    }

    private function object(string $path, string $name): array
    {
        $stat = @stat($path);
        return [
            'name' => $name,
            'mode' => $stat ? decoct($stat['mode'] & 0777) : '0644',
            'mode_bits' => $stat ? ($stat['mode'] & 0777) : 0644,
            'size' => is_file($path) ? (int) filesize($path) : 0,
            'is_file' => !is_dir($path),
            'is_symlink' => is_link($path),
            'mimetype' => is_file($path) ? (mime_content_type($path) ?: 'application/octet-stream') : 'inode/directory',
            'created_at' => $stat ? date(DATE_ATOM, $stat['ctime']) : date(DATE_ATOM),
            'modified_at' => $stat ? date(DATE_ATOM, $stat['mtime']) : date(DATE_ATOM),
        ];
    }

    public function directory(Request $request, Server $server): array
    {
        $dir = $this->safe($server, (string) $request->get('directory', '/'));
        abort_unless(is_dir($dir), 404);
        $items = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $dir . '/' . $name;
            if (is_link($full)) continue;
            $items[] = $this->object($full, $name);
        }
        usort($items, fn ($a, $b) => [$b['is_file'] ? 1 : 0, strtolower($a['name'])] <=> [$a['is_file'] ? 1 : 0, strtolower($b['name'])]);
        return ['object' => 'list', 'data' => array_map(fn ($x) => ['object' => 'file_object', 'attributes' => $x], $items)];
    }

    public function contents(Request $request, Server $server): Response
    {
        $file = $this->safe($server, (string) $request->get('file'));
        abort_unless(is_file($file), 404);
        abort_if(filesize($file) > (int) config('pterodactyl.files.max_edit_size', 102400), 413, 'File is too large to edit.');
        return response((string) file_get_contents($file), 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function download(Request $request, Server $server): JsonResponse|Response
    {
        $file = (string) $request->get('file');
        $path = $this->safe($server, $file);
        abort_unless(is_file($path), 404);
        if ($request->boolean('direct')) return response()->download($path, basename($path));
        return response()->json(['object' => 'signed_url', 'attributes' => ['url' => url('/api/client/servers/' . $server->uuid . '/files/download?file=' . rawurlencode($file) . '&direct=1')]]);
    }

    public function write(Request $request, Server $server): JsonResponse
    {
        $file = $this->safe($server, (string) $request->get('file'));
        $dir = dirname($file);
        abort_unless(is_dir($dir), 404);
        file_put_contents($file, $request->getContent(), LOCK_EX);
        return response()->json([], 204);
    }

    public function create(Request $request, Server $server): JsonResponse
    {
        $name = (string) $request->input('name');
        abort_if($name === '' || basename($name) !== $name, 422, 'Invalid directory name.');
        $path = $this->safe($server, (string) $request->input('root', '/') . '/' . $name);
        abort_if(file_exists($path), 409, 'Path already exists.');
        mkdir($path, 0750, true);
        return response()->json([], 204);
    }

    public function rename(Request $request, Server $server): JsonResponse
    {
        $root = (string) $request->input('root', '/');
        foreach ((array) $request->input('files', []) as $item) {
            $from = $this->safe($server, $root . '/' . ($item['from'] ?? ''));
            $to = $this->safe($server, $root . '/' . ($item['to'] ?? ''));
            abort_if(file_exists($to), 409, 'Destination exists.');
            rename($from, $to);
        }
        return response()->json([], 204);
    }

    public function copy(Request $request, Server $server): JsonResponse
    {
        $source = $this->safe($server, (string) $request->input('location'));
        $dest = $this->safe($server, (string) $request->input('location') . '.copy');
        abort_unless(is_file($source), 422, 'Only file copy is supported.');
        copy($source, $dest);
        return response()->json([], 204);
    }

    public function compress(Request $request, Server $server): array
    {
        $root = (string) $request->input('root', '/');
        $name = 'archive-' . date('Ymd-His') . '.zip';
        $target = $this->safe($server, $root . '/' . $name);
        $zip = new ZipArchive();
        abort_unless($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);
        foreach ((array) $request->input('files', []) as $file) {
            $rel = (string) $file;
            $path = $this->safe($server, $root . '/' . $rel);
            if (is_file($path)) $zip->addFile($path, $rel);
        }
        $zip->close();
        return ['object' => 'file_object', 'attributes' => $this->object($target, $name)];
    }

    public function decompress(Request $request, Server $server): JsonResponse
    {
        $file = $this->safe($server, (string) $request->input('root', '/') . '/' . (string) $request->input('file'));
        $zip = new ZipArchive();
        abort_unless($zip->open($file) === true, 422, 'Invalid archive.');
        $root = dirname($file);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            abort_if($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name), 422, 'Unsafe archive.');
        }
        $zip->extractTo($root);
        $zip->close();
        return response()->json([], 204);
    }

    public function delete(Request $request, Server $server): JsonResponse
    {
        $root = (string) $request->input('root', '/');
        foreach ((array) $request->input('files', []) as $name) {
            $path = $this->safe($server, $root . '/' . $name);
            abort_if($path === $this->root($server), 422, 'Cannot delete server root.');
            $this->remove($path);
        }
        return response()->json([], 204);
    }

    public function pull(Request $request, Server $server): JsonResponse
    {
        abort(501, 'Remote URL pulls are disabled in local mode. Upload the file through the panel instead.');
    }

    public function chmod(Request $request, Server $server): JsonResponse
    {
        $root = (string) $request->input('root', '/');
        foreach ((array) $request->input('files', []) as $file) {
            $path = $this->safe($server, $root . '/' . ($file['file'] ?? ''));
            chmod($path, octdec((string) ($file['mode'] ?? '0644')));
        }
        return response()->json([], 204);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $this->remove($path . '/' . $name);
            @rmdir($path);
        } else @unlink($path);
    }
}
