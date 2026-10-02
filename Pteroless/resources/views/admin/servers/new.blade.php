@extends('layouts.admin')

@section('title')
    New Server
@endsection

@section('content-header')
    <h1>Create Server<small>Run a local process without Wings.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.servers') }}">Servers</a></li>
        <li class="active">Create Server</li>
    </ol>
@endsection

@section('content')
<form action="{{ route('admin.servers.new') }}" method="POST">
    @csrf
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Core Details</h3></div>
                <div class="box-body row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="pName">Server Name</label>
                            <input type="text" class="form-control" id="pName" name="name" value="{{ old('name') }}" placeholder="Server Name">
                        </div>
                        <div class="form-group">
                            <label for="pUserId">Server Owner</label>
                            <select id="pUserId" name="owner_id" class="form-control">
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" @selected(old('owner_id') == $user->id)>{{ $user->email }} ({{ $user->username }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="pDescription">Server Description</label>
                            <textarea id="pDescription" name="description" rows="3" class="form-control">{{ old('description') }}</textarea>
                        </div>
                        <p class="text-muted small">This server runs directly on the VPS host. There is no Wings daemon, Docker container, or Egg install script.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Startup</h3></div>
                <div class="box-body">
                    <div class="form-group">
                        <label for="pRuntime">Runtime</label>
                        <select id="pRuntime" name="runtime" class="form-control">
                            <option value="nodejs" @selected(old('runtime', 'nodejs') === 'nodejs')>Node.js</option>
                            <option value="nextjs" @selected(old('runtime') === 'nextjs')>Next.js</option>
                            <option value="python" @selected(old('runtime') === 'python')>Python</option>
                            <option value="java" @selected(old('runtime') === 'java')>Java/JAR</option>
                            <option value="php" @selected(old('runtime') === 'php')>PHP</option>
                            <option value="go" @selected(old('runtime') === 'go')>Go</option>
                            <option value="custom" @selected(old('runtime') === 'custom')>Custom</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pStartup">Startup Command</label>
                        <input type="text" id="pStartup" name="startup" class="form-control" value="{{ old('startup', \Pterodactyl\Services\Local\LocalServerService::STARTUP_PRESETS['nodejs']) }}">
                        <p class="text-muted small">The preset fills in a startup command. You can edit it here or later from the server's Startup tab.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Resource Management</h3></div>
                <div class="box-body row">
                    <div class="form-group col-sm-4">
                        <label for="pMemory">Memory (MiB)</label>
                        <input type="number" min="0" id="pMemory" name="memory" class="form-control" value="{{ old('memory', 512) }}">
                        <p class="text-muted small">0 = no explicit memory limit.</p>
                    </div>
                    <div class="form-group col-sm-4">
                        <label for="pCPU">CPU Limit (%)</label>
                        <input type="number" min="0" id="pCPU" name="cpu" class="form-control" value="{{ old('cpu', 0) }}">
                        <p class="text-muted small">Stored as the server limit; hard CPU enforcement is added by the local runner when supported.</p>
                    </div>
                    <div class="form-group col-sm-4">
                        <label for="pDisk">Disk Limit (MiB)</label>
                        <input type="number" min="0" id="pDisk" name="disk" class="form-control" value="{{ old('disk', 0) }}">
                        <p class="text-muted small">0 = no panel disk limit.</p>
                    </div>
                    <div class="form-group col-sm-6">
                        <label for="pThreads">CPU Pinning</label>
                        <input type="text" id="pThreads" name="threads" class="form-control" value="{{ old('threads') }}" placeholder="0-3">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row"><div class="col-xs-12"><button type="submit" class="btn btn-primary">Create Server</button></div></div>
</form>
<script>
    document.getElementById('pRuntime').addEventListener('change', function () {
        const presets = @json(\Pterodactyl\Services\Local\LocalServerService::STARTUP_PRESETS);
        document.getElementById('pStartup').value = presets[this.value] || '';
    });
</script>
@endsection
