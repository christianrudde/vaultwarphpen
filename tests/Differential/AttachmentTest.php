<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * Attachments of src/api/core/ciphers.rs and their download (src/api/web.rs) on both servers.
 */
class AttachmentTest extends DifferentialTestCase
{
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment/v2')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment/{attachment_id}')]
    #[Parity('route:GET /api/ciphers/{cipher_id}/attachment/{attachment_id}')]
    #[Parity('route:GET /attachments/{cipher_id}/{file_id}')]
    public function test_v2_upload_and_download(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = CipherTest::session($server, 'attachments-v2@example.com');
            $cipherId = CipherTest::note($server, $auth, $userId, '2.note');
            $unknown = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
            $steps = [];

            $steps['create'] = $created = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.key', 'fileName' => '2.name', 'fileSize' => '11'], $auth);
            $attachmentId = $created->jsonString('attachmentId');
            $steps['create for the admin view'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.key', 'fileName' => '2.other', 'fileSize' => 3, 'adminRequest' => true], $auth);
            $steps['negative size'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.k', 'fileName' => '2.n', 'fileSize' => -1], $auth);
            $steps['too large'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.k', 'fileName' => '2.n', 'fileSize' => 550_502_401], $auth);
            $steps['size not a number'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.k', 'fileName' => '2.n', 'fileSize' => '1.5'], $auth);
            $steps['size a float'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.k', 'fileName' => '2.n', 'fileSize' => 1.5], $auth);
            $steps['no key'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['fileName' => '2.n', 'fileSize' => 1], $auth);
            $steps['unknown cipher'] = $server->json('POST', "/api/ciphers/{$unknown}/attachment/v2", ['key' => '2.k', 'fileName' => '2.n', 'fileSize' => 1], $auth);

            $steps['upload'] = $server->multipart("/api/ciphers/{$cipherId}/attachment/{$attachmentId}", [['data', 'hello world', 'ignored']], $auth);
            $steps['upload as json'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/{$attachmentId}", ['data' => 'x'], $auth);
            $steps['upload unknown'] = $server->multipart("/api/ciphers/{$cipherId}/attachment/0123456789abcdef0123", [['data', 'x', 'n']], $auth);
            $steps['upload without data'] = $server->multipart("/api/ciphers/{$cipherId}/attachment/{$attachmentId}", [['key', '2.k']], $auth);
            $steps['upload unauthenticated'] = $server->multipart("/api/ciphers/{$cipherId}/attachment/{$attachmentId}", [['data', 'x', 'n']], ['Accept' => 'application/json']);

            $steps['get'] = $attachment = $server->send('GET', "/api/ciphers/{$cipherId}/attachment/{$attachmentId}", $auth);
            $steps['get upper case'] = $server->send('GET', "/api/ciphers/{$cipherId}/attachment/".strtoupper($attachmentId), $auth);
            $steps['get unknown'] = $server->send('GET', "/api/ciphers/{$cipherId}/attachment/0123456789abcdef0123", $auth);
            $steps['get of another cipher'] = $server->send('GET', "/api/ciphers/{$unknown}/attachment/{$attachmentId}", $auth);
            $steps['get invalid id'] = $server->send('GET', "/api/ciphers/{$cipherId}/attachment/not_an_id", $auth);
            $steps['cipher'] = $server->send('GET', "/api/ciphers/{$cipherId}", $auth);

            $url = (string) parse_url($attachment->jsonString('url'), PHP_URL_PATH);
            $token = (string) parse_url($attachment->jsonString('url'), PHP_URL_QUERY);
            $steps['download'] = $server->send('GET', "{$url}?{$token}");
            $steps['download without token'] = $server->send('GET', $url);
            $steps['download with a bad token'] = $server->send('GET', "{$url}?token=nope");
            $steps['download with two tokens'] = $server->send('GET', "{$url}?{$token}&{$token}");
            $steps['download with the good token first'] = $server->send('GET', "{$url}?{$token}&token=nope");
            $steps['download with the good token second'] = $server->send('GET', "{$url}?token=nope&{$token}");
            $steps['download another file'] = $server->send('GET', "/attachments/{$cipherId}/0123456789abcdef0123?{$token}");

            $second = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.key', 'fileName' => '2.name', 'fileSize' => 3 * 1024 * 1024], $auth)->jsonString('attachmentId');
            $steps['size mismatch'] = $server->multipart("/api/ciphers/{$cipherId}/attachment/{$second}", [['data', 'tiny', 'n']], $auth);
            $steps['after the mismatch'] = $server->send('GET', "/api/ciphers/{$cipherId}/attachment/{$second}", $auth);

            return $steps;
        });
    }

    #[Parity('config:USER_ATTACHMENT_LIMIT')]
    public function test_v2_reservation_within_the_storage_limit(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = CipherTest::session($server, 'attachments-limit@example.com');
            $cipherId = CipherTest::note($server, $auth, $userId, '2.note');
            $session = AdminTest::login($server);
            $steps = [];
            try {
                $steps['limit of 1 KiB'] = $server->json('POST', '/admin/config', ['user_attachment_limit' => 1, 'admin_token' => AdminTest::TOKEN], $session);
                $steps['reserve too much'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.k', 'fileName' => '2.n', 'fileSize' => 1025], $auth);
                $steps['reserve all'] = $reserved = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.k', 'fileName' => '2.n', 'fileSize' => 1024], $auth);
                $steps['reserve more'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment/v2", ['key' => '2.k', 'fileName' => '2.n', 'fileSize' => 1], $auth);
                $steps['upload the reserved file'] = $server->multipart("/api/ciphers/{$cipherId}/attachment/{$reserved->jsonString('attachmentId')}",
                    [['data', str_repeat('x', 1024), '2.n']], $auth);
                $steps['cipher'] = $server->send('GET', "/api/ciphers/{$cipherId}", $auth);
            } finally {
                $steps['reset'] = $server->send('POST', '/admin/config/delete', ['Content-Type' => 'application/json', ...$session]);
            }

            return $steps;
        });
    }

    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment-admin')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment/{attachment_id}/share')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment/{attachment_id}/delete')]
    #[Parity('route:POST /api/ciphers/{cipher_id}/attachment/{attachment_id}/delete-admin')]
    #[Parity('route:DELETE /api/ciphers/{cipher_id}/attachment/{attachment_id}')]
    #[Parity('route:DELETE /api/ciphers/{cipher_id}/attachment/{attachment_id}/admin')]
    public function test_legacy_upload_share_and_delete(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            [$auth, $userId] = CipherTest::session($server, 'attachments-legacy@example.com');
            $cipherId = CipherTest::note($server, $auth, $userId, '2.note');
            $unknown = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
            $steps = [];

            // Encrypted names hold slashes and other base64 characters.
            $steps['upload'] = $uploaded = $server->multipart("/api/ciphers/{$cipherId}/attachment", [['key', '2.key'], ['data', 'secret', '2.iv/x|ct+y/z==']], $auth);
            $first = $uploaded->jsonString('attachments.0.id');
            $steps['upload without key'] = $server->multipart("/api/ciphers/{$cipherId}/attachment", [['data', 'x', '2.n']], $auth);
            $steps['upload a value'] = $server->multipart("/api/ciphers/{$cipherId}/attachment", [['key', '2.k'], ['data', 'plain']], $auth);
            $steps['upload nothing'] = $server->multipart("/api/ciphers/{$cipherId}/attachment", [['key', '2.k']], $auth);
            $steps['upload as json'] = $server->json('POST', "/api/ciphers/{$cipherId}/attachment", ['key' => '2.k'], $auth);
            $steps['upload to unknown'] = $server->multipart("/api/ciphers/{$unknown}/attachment", [['key', '2.k'], ['data', 'x', '2.n']], $auth);

            $steps['share'] = $shared = $server->multipart("/api/ciphers/{$cipherId}/attachment/{$first}/share", [['key', '2.key2'], ['data', 'new data', '2.other']], $auth);
            $second = $shared->jsonString('attachments.0.id');
            $steps['admin upload'] = $admin = $server->multipart("/api/ciphers/{$cipherId}/attachment-admin", [['key', '2.key3'], ['data', 'third', '2.third']], $auth);
            $third = $admin->json('attachments.1.id') === $second ? $admin->jsonString('attachments.0.id') : $admin->jsonString('attachments.1.id');

            $steps['delete gone'] = $server->send('DELETE', "/api/ciphers/{$cipherId}/attachment/{$first}", $auth);
            $steps['delete from another cipher'] = $server->send('DELETE', "/api/ciphers/{$unknown}/attachment/{$second}", $auth);
            $steps['delete invalid id'] = $server->send('DELETE', "/api/ciphers/{$cipherId}/attachment/not_an_id", $auth);
            $steps['delete'] = $server->send('POST', "/api/ciphers/{$cipherId}/attachment/{$second}/delete", $auth);
            $steps['delete admin'] = $server->send('DELETE', "/api/ciphers/{$cipherId}/attachment/{$third}/admin", $auth);
            $steps['cipher'] = $server->send('GET', "/api/ciphers/{$cipherId}", $auth);

            $fourth = $server->multipart("/api/ciphers/{$cipherId}/attachment", [['key', '2.k'], ['data', 'x', '2.n']], $auth)->jsonString('attachments.0.id');
            $both = $server->multipart("/api/ciphers/{$cipherId}/attachment", [['key', '2.k'], ['data', 'y', '2.n']], $auth);
            $fifth = $both->jsonString('attachments.0.id') === $fourth ? $both->jsonString('attachments.1.id') : $both->jsonString('attachments.0.id');
            $steps['delete post admin'] = $server->send('POST', "/api/ciphers/{$cipherId}/attachment/{$fourth}/delete-admin", $auth);
            $steps['delete plain'] = $server->send('DELETE', "/api/ciphers/{$cipherId}/attachment/{$fifth}", $auth);

            return $steps;
        });
    }
}
