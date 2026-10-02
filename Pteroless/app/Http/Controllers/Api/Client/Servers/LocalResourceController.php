<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Server;
use Pterodactyl\Services\Local\LocalProcessManager;
use Pterodactyl\Transformers\Api\Client\StatsTransformer;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\GetServerRequest;

class LocalResourceController extends ClientApiController
{
    public function __construct(private LocalProcessManager $processes) { parent::__construct(); }
    public function __invoke(GetServerRequest $request, Server $server): array
    {
        return $this->fractal->item($this->processes->stats($server))
            ->transformWith(StatsTransformer::class)->toArray();
    }
}
