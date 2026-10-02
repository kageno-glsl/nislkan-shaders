<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server\Startup;

use Illuminate\Http\Response;
use Pterodactyl\Models\Permission;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class UpdateStartupCommandTest extends ClientApiIntegrationTestCase
{
    public function testLocalStartupCommandCanBeUpdated(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_STARTUP_UPDATE]);
        $server->egg->forceFill(['author' => 'local@pteroless.invalid'])->save();
        $server->forceFill(['image' => 'local', 'startup' => 'npm install && npm start'])->save();

        $response = $this->actingAs($user)->putJson($this->link($server) . '/startup/command', [
            'startup' => 'node test.js',
        ]);

        $response->assertOk();
        $response->assertJsonPath('startup_command', 'node test.js');
        $response->assertJsonPath('raw_startup_command', 'node test.js');
        $this->assertSame('node test.js', $server->refresh()->startup);
    }

    public function testNonLocalStartupCommandCannotBeUpdated(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_STARTUP_UPDATE]);

        $response = $this->actingAs($user)->putJson($this->link($server) . '/startup/command', [
            'startup' => 'node test.js',
        ]);

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertNotSame('node test.js', $server->refresh()->startup);
    }

    public function testStartupCommandCannotBeUpdatedWithoutPermission(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_WEBSOCKET_CONNECT]);
        $server->egg->forceFill(['author' => 'local@pteroless.invalid'])->save();
        $server->forceFill(['image' => 'local'])->save();

        $this->actingAs($user)
            ->putJson($this->link($server) . '/startup/command', ['startup' => 'node test.js'])
            ->assertForbidden();
    }
}