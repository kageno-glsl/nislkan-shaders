<?php

namespace Pterodactyl\Http\Requests\Admin;

class ServerFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'min:1', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'runtime' => ['required', 'in:nodejs,nextjs,python,java,php,go,custom'],
            'startup' => ['required', 'string', 'max:2000'],
            'memory' => ['required', 'integer', 'min:0'],
            'cpu' => ['nullable', 'integer', 'min:0'],
            'disk' => ['nullable', 'integer', 'min:0'],
            'threads' => ['nullable', 'regex:/^[0-9,-]+$/'],
        ];
    }
}
