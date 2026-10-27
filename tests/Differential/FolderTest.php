<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * src/api/core/folders.rs on both servers.
 */
class FolderTest extends DifferentialTestCase
{
    #[Parity('route:GET /api/folders')]
    #[Parity('route:GET /api/folders/{folder_id}')]
    #[Parity('route:POST /api/folders')]
    #[Parity('route:POST /api/folders/{folder_id}')]
    #[Parity('route:PUT /api/folders/{folder_id}')]
    #[Parity('route:POST /api/folders/{folder_id}/delete')]
    #[Parity('route:DELETE /api/folders/{folder_id}')]
    public function test_folders(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            $auth = self::session($server, 'folders@example.com');
            $steps = [];

            $steps['empty list'] = $server->send('GET', '/api/folders', $auth);
            $steps['create'] = $server->json('POST', '/api/folders', ['name' => '2.encrypted|folder|name', 'id' => ''], $auth);
            $id = $steps['create']->jsonString('id');
            $steps['create second'] = $server->json('POST', '/api/folders', ['name' => '2.second'], $auth);
            $second = $steps['create second']->jsonString('id');
            $steps['list'] = $server->send('GET', '/api/folders', $auth);
            $steps['get'] = $server->send('GET', "/api/folders/{$id}", $auth);
            $steps['rename'] = $server->json('PUT', "/api/folders/{$id}", ['name' => '2.renamed'], $auth);
            $steps['rename by post'] = $server->json('POST', "/api/folders/{$id}", ['name' => '2.renamed.again'], $auth);
            $steps['unknown'] = $server->send('GET', '/api/folders/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $auth);
            $steps['invalid id'] = $server->send('GET', '/api/folders/not-a-uuid', $auth);
            $steps['rename invalid id'] = $server->json('PUT', '/api/folders/not-a-uuid', ['name' => 'x'], $auth);
            $steps['no name'] = $server->json('POST', '/api/folders', ['id' => $id], $auth);
            $steps['broken json'] = $server->send('POST', '/api/folders', [...$auth, 'Content-Type' => 'application/json'], '{"name":');
            $steps['delete by post'] = $server->send('POST', "/api/folders/{$id}/delete", $auth);
            $steps['delete'] = $server->send('DELETE', "/api/folders/{$second}", $auth);
            $steps['delete again'] = $server->send('DELETE', "/api/folders/{$second}", $auth);
            $steps['list afterwards'] = $server->send('GET', '/api/folders', $auth);

            return $steps;
        });
    }

    /**
     * A registered and logged-in user; the headers for its requests.
     *
     * @return array<string, string>
     */
    public static function session(Server $server, string $email): array
    {
        LoginTest::register($server, $email);
        $login = $server->form('/identity/connect/token', LoginTest::loginFields($email));

        return ['Authorization' => 'Bearer '.$login->jsonString('access_token'), 'Accept' => 'application/json'];
    }
}
