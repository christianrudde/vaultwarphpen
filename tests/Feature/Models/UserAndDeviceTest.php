<?php

namespace Tests\Feature\Models;

use App\Http\ApiError;
use App\Models\Casts\NaiveDateTime;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Attributes\Parity;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * src/db/models/user.rs and device.rs, against every database type.
 */
#[Parity('table:users')]
#[Parity('table:devices')]
class UserAndDeviceTest extends TestCase
{
    use UsesVaultwardenDatabase;

    #[DataProvider('drivers')]
    public function test_a_new_user_is_stored_like_vaultwarden_stores_it(string $driver): void
    {
        $connection = $this->useDatabase($driver);

        $user = User::new('Alice@Example.COM');
        $user->setPassword('client-side-hash', 'encrypted-user-key', resetSecurityStamp: false, allowNextRoute: null);
        $user->save();

        $stored = User::findByMail('alice@example.com');
        $this->assertNotNull($stored);
        $this->assertSame('alice@example.com', $stored->email);
        $this->assertSame('alice@example.com', $stored->name);
        $this->assertSame(64, strlen($stored->salt));
        $this->assertSame(32, strlen($stored->password_hash));
        $this->assertTrue($stored->checkValidPassword('client-side-hash'));
        $this->assertFalse($stored->checkValidPassword('wrong-hash'));
        $this->assertSame('encrypted-user-key', $stored->akey);
        $this->assertSame(600_000, $stored->password_iterations);
        $this->assertSame(User::CLIENT_KDF_ITER_DEFAULT, $stored->client_kdf_iter);
        $this->assertTrue($stored->enabled);
        $this->assertSame('[]', $stored->equivalent_domains);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $stored->uuid);
        $this->assertSame(0, $stored->status());

        if ($driver === 'sqlite') {
            // Diesel stores bytes as BLOB and timestamps as text without zone.
            $row = (array) $connection->selectOne('SELECT typeof(salt) AS salt_type, created_at FROM users');
            $this->assertSame('blob', $row['salt_type']);
            $this->assertIsString($row['created_at']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{3}|\.\d{6})?$/', $row['created_at']);
        }
    }

    #[DataProvider('drivers')]
    public function test_saving_again_updates_the_row(string $driver): void
    {
        $this->useDatabase($driver);

        $user = User::new('bob@example.com', 'Bob');
        $user->save();
        $user->name = 'Robert';
        $user->save();

        $this->assertSame(1, User::query()->count());
        $this->assertSame('Robert', User::findByUuid($user->uuid)?->name);
    }

    #[DataProvider('drivers')]
    public function test_devices(string $driver): void
    {
        $this->useDatabase($driver);
        $user = User::new('carol@example.com');
        $user->save();

        $device = Device::new('9f1d6d5a-1111-4222-8333-444455556666', $user->uuid, 'firefox', DeviceType::FirefoxBrowser->value);
        $this->assertTrue($device->isNew());
        $device->save();

        $stored = Device::findByUuidAndUser($device->uuid, $user->uuid);
        $this->assertNotNull($stored);
        $this->assertSame($device->refresh_token, $stored->refresh_token);
        $this->assertSame('Firefox', $stored->type()->displayName());
        $this->assertFalse($stored->isMobile());
        $this->assertSame(['creationDate', 'id', 'identifier', 'isTrusted', 'name', 'object', 'type'], array_keys($stored->toApi()));

        $before = $stored->updated_at;
        $stored->twofactor_remember = 'remember-me';
        $stored->save(['update_time' => false]);
        $user->resetSecurityStamp();

        $rotated = Device::findByUuidAndUser($device->uuid, $user->uuid);
        $this->assertNotNull($rotated);
        $this->assertNotSame($device->refresh_token, $rotated->refresh_token);
        $this->assertNull($rotated->twofactor_remember);
        $this->assertEquals($before, $rotated->updated_at);
        $this->assertSame($user->uuid, Device::findLatestActiveByUser($user->uuid)?->user_uuid);
    }

    public function test_refresh_tokens_are_base64url_with_padding(): void
    {
        $token = Device::generateRefreshToken();

        $this->assertSame(88, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{86}==$/', $token);
    }

    public function test_invalid_emails_are_not_saved(): void
    {
        $this->useDatabase('sqlite');

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('User email not-an-email is not a valid email address');

        User::new('not-an-email')->save();
    }

    public function test_stamp_exceptions(): void
    {
        $user = User::new('dave@example.com');
        $user->setStampException(['post_password', 'post_kdf']);

        $exception = json_decode((string) $user->stamp_exception, true);
        $this->assertIsArray($exception);
        $this->assertSame(['routes', 'security_stamp', 'expire'], array_keys($exception));
        $this->assertSame($user->security_stamp, $exception['security_stamp']);

        $user->resetStampException();
        $this->assertNull($user->stamp_exception);
    }

    public function test_timestamps_with_nanoseconds_from_diesel_are_read(): void
    {
        $date = NaiveDateTime::parse('2026-10-27 20:15:04.123456789');

        $this->assertSame('2026-10-27 20:15:04.123456', $date->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-10-27 20:15:04.123456', NaiveDateTime::format($date));
        $this->assertSame('2026-10-27 20:15:04.120', NaiveDateTime::format(NaiveDateTime::parse('2026-10-27 20:15:04.12')));
        $this->assertSame('2026-10-27 20:15:04', NaiveDateTime::format(NaiveDateTime::parse('2026-10-27T20:15:04')));
    }
}
