<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Local\LocalProcessManager;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;

class LocalLogsController extends ClientApiController
{
    public function __construct(private LocalProcessManager $processes) { parent::__construct(); }
    public function __invoke(Server $server): JsonResponse
    {
        return response()->json([
            'running' => $this->processes->isRunning($server),
            'logs' => $this->processes->logs($server),
        ]);
    }
}
