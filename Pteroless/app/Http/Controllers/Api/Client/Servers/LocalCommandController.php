<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Local\LocalProcessManager;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\SendCommandRequest;

class LocalCommandController extends ClientApiController
{
    public function __construct(private LocalProcessManager $processes) { parent::__construct(); }
    public function index(SendCommandRequest $request, Server $server): Response
    {
        $this->processes->command($server, $request->input('command'));
        Activity::event('server:console.command')->property('command', $request->input('command'))->log();
        return $this->returnNoContent();
    }
}
