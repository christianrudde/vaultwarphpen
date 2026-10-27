<?php

namespace App\Models;

use App\Http\Json;
use App\Models\Casts\Binary;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use App\Support\JsonValue;
use App\Support\Uuid;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use stdClass;

/**
 * A Send (src/db/models/send.rs); so far only what the sync shows.
 *
 * @property string $uuid
 * @property ?string $user_uuid
 * @property ?string $organization_uuid
 * @property string $name
 * @property ?string $notes
 * @property int $atype
 * @property string $data
 * @property string $akey
 * @property ?string $password_hash
 * @property ?string $password_salt
 * @property ?int $password_iter
 * @property ?int $max_access_count
 * @property int $access_count
 * @property DateTimeImmutable $creation_date
 * @property DateTimeImmutable $revision_date
 * @property ?DateTimeImmutable $expiration_date
 * @property DateTimeImmutable $deletion_date
 * @property bool $disabled
 * @property ?bool $hide_email
 */
final class Send extends Model
{
    public const int TYPE_TEXT = 0;

    public const int TYPE_FILE = 1;

    /**
     * SendAuthType: Vaultwarden knows no email authentication (0) yet.
     */
    private const int AUTH_PASSWORD = 1;

    private const int AUTH_NONE = 2;

    protected $table = 'sends';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'atype' => 'integer',
            'password_hash' => Binary::class,
            'password_salt' => Binary::class,
            'password_iter' => 'integer',
            'max_access_count' => 'integer',
            'access_count' => 'integer',
            'creation_date' => NaiveDateTime::class,
            'revision_date' => NaiveDateTime::class,
            'expiration_date' => NaiveDateTime::class,
            'deletion_date' => NaiveDateTime::class,
            'disabled' => 'boolean',
            'hide_email' => 'boolean',
        ];
    }

    protected function binaryColumns(): array
    {
        return ['password_hash', 'password_salt'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        [$ok, $decoded] = JsonValue::decode($this->data);
        // LowerCase<Value> reads objects only; anything else is null here.
        $data = $ok && $decoded instanceof stdClass ? JsonValue::lowercaseKeys($decoded) : null;

        // Mobile clients expect the size as a string.
        if (is_array($data) && is_int($data['size'] ?? null)) {
            $data['size'] = (string) $data['size'];
        }

        return Json::sorted([
            'id' => $this->uuid,
            'accessId' => $this->accessId(),
            'type' => $this->atype,
            'name' => $this->name,
            'notes' => $this->notes,
            'text' => $this->atype === self::TYPE_TEXT ? $data : null,
            'file' => $this->atype === self::TYPE_FILE ? $data : null,
            'key' => $this->akey,
            'maxAccessCount' => $this->max_access_count,
            'accessCount' => $this->access_count,
            'password' => $this->password_hash === null ? null : rtrim(strtr(base64_encode($this->password_hash), '+/', '-_'), '='),
            'authType' => $this->password_hash === null ? self::AUTH_NONE : self::AUTH_PASSWORD,
            'disabled' => $this->disabled,
            'hideEmail' => $this->hide_email ?? false,
            'revisionDate' => Dates::format($this->revision_date),
            'expirationDate' => $this->expiration_date === null ? null : Dates::format($this->expiration_date),
            'deletionDate' => Dates::format($this->deletion_date),
            'object' => 'send',
        ]);
    }

    /**
     * The UUID's 16 bytes in unpadded base64url; the nil UUID when it is none.
     */
    public function accessId(): string
    {
        $hex = Uuid::isValid($this->uuid)
            ? (string) preg_replace('/[^0-9a-fA-F]/', '', (string) preg_replace('/^urn:uuid:/', '', $this->uuid))
            : str_repeat('0', 32);

        return rtrim(strtr(base64_encode((string) hex2bin($hex)), '+/', '-_'), '=');
    }

    /**
     * @return Collection<int, self>
     */
    public static function findByUser(string $userUuid): Collection
    {
        return self::query()->where('user_uuid', $userUuid)->get();
    }
}
