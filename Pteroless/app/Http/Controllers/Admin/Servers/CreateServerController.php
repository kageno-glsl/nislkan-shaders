<?php

namespace Pterodactyl\Http\Controllers\Admin\Servers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\ServerFormRequest;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Local\LocalServerService;
use Prologue\Alerts\AlertsMessageBag;

class CreateServerController extends Controller
{
    public function __construct(private AlertsMessageBag $alert, private LocalServerService $servers) {}

    public function index(): View
    {
        return view('admin.servers.new', ['users' => User::query()->orderBy('email')->get(['id', 'email', 'username'])]);
    }

    public function store(ServerFormRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $server = $this->servers->create([
            'owner_id' => $data['owner_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'runtime' => $data['runtime'],
            'startup' => $data['startup'],
            'memory' => $data['memory'],
            'cpu' => $data['cpu'] ?? 0,
            'disk' => $data['disk'] ?? 0,
            'threads' => $data['threads'] ?? null,
        ]);

        $this->alert->success('Server created using the local process runner. Wings, Nodes, and Eggs require no setup.')->flash();
        return new RedirectResponse('/admin/servers/view/' . $server->id);
    }
}
