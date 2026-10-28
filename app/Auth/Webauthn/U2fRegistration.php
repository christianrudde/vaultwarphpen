<?php

namespace App\Auth\Webauthn;

use App\Support\JsonValue;
use stdClass;

/**
 * A U2F registration of before WebAuthn (U2FRegistration with its
 * Registration), kept so that migrated keys can be removed from it as
 * well. Byte fields are JSON lists of numbers, as serde writes Vec<u8>.
 */
final readonly class U2fRegistration
{
    /**
     * @param  list<int>  $pubKey
     * @param  list<int>|null  $attestationCert
     */
    private function __construct(
        public int $id,
        public string $name,
        public string $keyHandle,
        public array $pubKey,
        public ?array $attestationCert,
        public ?string $deviceName,
        public int $counter,
        public bool $compromised,
        public ?bool $migrated,
    ) {}

    /**
     * Vec<U2FRegistration>; null when it does not parse.
     *
     * @return list<self>|null
     */
    public static function parseList(string $json): ?array
    {
        [$ok, $value] = JsonValue::decode($json);
        if (! $ok || ! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $registrations = [];
        foreach ($value as $item) {
            $registration = self::fromJson($item);
            if ($registration === null) {
                return null;
            }
            $registrations[] = $registration;
        }

        return $registrations;
    }

    public static function fromJson(mixed $value): ?self
    {
        $reg = $value instanceof stdClass ? ($value->reg ?? null) : null;
        if (! $value instanceof stdClass || ! $reg instanceof stdClass) {
            return null;
        }
        $keyHandle = self::bytes($reg->keyHandle ?? null);
        $pubKey = self::bytes($reg->pubKey ?? null);
        $certificate = ($reg->attestationCert ?? null) === null ? null : self::bytes($reg->attestationCert);
        $deviceName = $reg->deviceName ?? null;
        $migrated = $value->migrated ?? null;
        if (! is_int($value->id ?? null) || ! is_string($value->name ?? null) || $keyHandle === null || $pubKey === null
            || (($reg->attestationCert ?? null) !== null && $certificate === null) || ($deviceName !== null && ! is_string($deviceName))
            || ! is_int($value->counter ?? null) || $value->counter < 0 || ! is_bool($value->compromised ?? null) || ($migrated !== null && ! is_bool($migrated))) {
            return null;
        }

        return new self($value->id, $value->name, self::binary($keyHandle), $pubKey, $certificate, $deviceName,
            $value->counter, $value->compromised, $migrated);
    }

    /**
     * The uncompressed public key: 0x04, x and y.
     */
    public function publicKey(): string
    {
        return self::binary($this->pubKey);
    }

    /**
     * @param  list<int>  $bytes
     */
    private static function binary(array $bytes): string
    {
        return implode('', array_map(static fn (int $byte): string => chr($byte & 0xFF), $bytes));
    }

    /**
     * @return array<string, mixed>
     */
    public function toJson(?bool $migrated = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'reg' => [
                'keyHandle' => array_map(ord(...), str_split($this->keyHandle)),
                'pubKey' => $this->pubKey,
                'attestationCert' => $this->attestationCert,
                'deviceName' => $this->deviceName,
            ],
            'counter' => $this->counter,
            'compromised' => $this->compromised,
            'migrated' => $migrated ?? $this->migrated,
        ];
    }

    /**
     * @return list<int<0, 255>>|null
     */
    private static function bytes(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $bytes = [];
        foreach ($value as $byte) {
            if (! is_int($byte) || $byte < 0 || $byte > 255) {
                return null;
            }
            $bytes[] = $byte;
        }

        return $bytes;
    }
}
