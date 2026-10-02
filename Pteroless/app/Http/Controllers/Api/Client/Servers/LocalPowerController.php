<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Local\LocalProcessManager;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\SendPowerRequest;

class LocalPowerController extends ClientApiController
{
    public function __construct(private LocalProcessManager $processes) { parent::__construct(); }
    public function index(SendPowerRequest $request, Server $server): Response
    {
        match ($request->input('signal')) {
            'start' => $this->processes->start($server),
            'stop' => $this->processes->stop($server),
            'restart' => $this->processes->restart($server),
            'kill' => $this->processes->stop($server, true),
            default => null,
        };
        Activity::event(strtolower("server:power.{$request->input('signal')}"))->log();
        return $this->returnNoContent();
    }
}
