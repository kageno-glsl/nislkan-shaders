<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;

class LocalWebsocketController extends ClientApiController
{
    public function __invoke(Server $server): array
    {
        return ['data' => ['socket' => null, 'token' => null, 'local' => true]];
    }
}
