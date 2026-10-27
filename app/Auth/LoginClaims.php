<?php

namespace App\Auth;

use App\Models\Device;
use App\Models\User;
use App\Support\Dates;

/**
 * The claims of an access token (auth::LoginJwtClaims).
 */
final readonly class LoginClaims
{
    /**
     * Access tokens are valid for two hours.
     */
    public const int DEFAULT_VALIDITY = 7_200;

    /**
     * @param  list<string>  $scope
     * @param  list<string>  $amr
     */
    public function __construct(
        public int $nbf,
        public int $exp,
        public string $iss,
        public string $sub,
        public bool $premium,
        public string $name,
        public string $email,
        public bool $email_verified,
        public string $sstamp,
        public string $device,
        public string $devicetype,
        public string $client_id,
        public array $scope,
        public array $amr,
    ) {}

    /**
     * LoginJwtClaims::new
     *
     * @param  list<string>  $scope
     */
    public static function new(Device $device, User $user, int $nbf, int $exp, array $scope, ?string $clientId, Jwt $jwt): self
    {
        if ($exp <= Dates::now()->getTimestamp() + 300) {
            logger()->warning('Raise access_token lifetime to more than 5min.');
        }

        return new self(
            nbf: $nbf,
            exp: $exp,
            iss: $jwt->issuer(JwtIssuer::Login),
            sub: $user->uuid,
            premium: true,
            name: $user->name,
            email: $user->email,
            email_verified: $user->verified(),
            sstamp: $user->security_stamp,
            device: $device->uuid,
            devicetype: $device->type()->displayName(),
            client_id: $clientId ?? 'undefined',
            scope: $scope,
            amr: ['Application'],
        );
    }

    /**
     * LoginJwtClaims::default: valid from now for two hours.
     */
    public static function default(Device $device, User $user, AuthMethod $method, ?string $clientId, Jwt $jwt): self
    {
        $now = Dates::now()->getTimestamp();

        return self::new($device, $user, $now, $now + self::DEFAULT_VALIDITY, $method->scopes(), $clientId, $jwt);
    }

    /**
     * Like deserializing the Rust struct: every field must be present with its type.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromArray(array $claims): ?self
    {
        $strings = ['iss', 'sub', 'name', 'email', 'sstamp', 'device', 'devicetype', 'client_id'];
        foreach ($strings as $field) {
            if (! is_string($claims[$field] ?? null)) {
                return null;
            }
        }
        foreach (['nbf', 'exp'] as $field) {
            if (! is_int($claims[$field] ?? null)) {
                return null;
            }
        }
        foreach (['premium', 'email_verified'] as $field) {
            if (! is_bool($claims[$field] ?? null)) {
                return null;
            }
        }
        foreach (['scope', 'amr'] as $field) {
            $list = $claims[$field] ?? null;
            if (! is_array($list) || ! array_is_list($list) || array_filter($list, is_string(...)) !== $list) {
                return null;
            }
        }

        /** @var array{nbf: int, exp: int, iss: string, sub: string, premium: bool, name: string, email: string, email_verified: bool, sstamp: string, device: string, devicetype: string, client_id: string, scope: list<string>, amr: list<string>} $claims */
        return new self(...array_intersect_key($claims, array_flip([...$strings, 'nbf', 'exp', 'premium', 'email_verified', 'scope', 'amr'])));
    }

    /**
     * In the field order of the Rust struct.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nbf' => $this->nbf,
            'exp' => $this->exp,
            'iss' => $this->iss,
            'sub' => $this->sub,
            'premium' => $this->premium,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified,
            'sstamp' => $this->sstamp,
            'device' => $this->device,
            'devicetype' => $this->devicetype,
            'client_id' => $this->client_id,
            'scope' => $this->scope,
            'amr' => $this->amr,
        ];
    }

    public function token(Jwt $jwt): string
    {
        return $jwt->encode($this->toArray());
    }

    public function expiresIn(): int
    {
        return $this->exp - Dates::now()->getTimestamp();
    }
}
