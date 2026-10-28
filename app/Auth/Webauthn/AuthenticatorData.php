<?php

namespace App\Auth\Webauthn;

use App\Support\Cbor;
use App\Support\CborMap;
use InvalidArgumentException;

/**
 * The authenticator data (authenticator_data_parser): the RP id hash, the
 * flags, the signature counter, the attested credential data when the AT
 * flag is set, and the signed extensions when the ED flag is set. Bytes
 * after them are ignored.
 */
final readonly class AuthenticatorData
{
    /**
     * @param  array{credProtect: ?int, hmacSecret: ?bool}  $extensions  The registration extensions webauthn-rs reads
     */
    private function __construct(
        public string $raw,
        public string $rpIdHash,
        public bool $userPresent,
        public bool $userVerified,
        public bool $backupEligible,
        public bool $backupState,
        public int $counter,
        public string $aaguid,
        public ?string $credentialId,
        public mixed $credentialKey,
        public array $extensions,
    ) {}

    /**
     * @throws WebauthnError
     */
    public static function parse(string $bytes, bool $registration): self
    {
        if (strlen($bytes) < 37) {
            throw new WebauthnError('ParseNOMFailure');
        }
        $flags = ord($bytes[32]);
        $counter = (ord($bytes[33]) << 24) | (ord($bytes[34]) << 16) | (ord($bytes[35]) << 8) | ord($bytes[36]);
        $rest = substr($bytes, 37);

        [$aaguid, $credentialId, $credentialKey] = ['', null, null];
        if (($flags & 0x40) !== 0) {
            if (strlen($rest) < 18) {
                throw new WebauthnError('ParseNOMFailure');
            }
            $aaguid = substr($rest, 0, 16);
            $idLength = (ord($rest[16]) << 8) | ord($rest[17]);
            if (strlen($rest) < 18 + $idLength) {
                throw new WebauthnError('ParseNOMFailure');
            }
            $credentialId = substr($rest, 18, $idLength);
            [$credentialKey, $used] = self::cbor(substr($rest, 18 + $idLength));
            $rest = substr($rest, 18 + $idLength + $used);
        }

        $extensions = ['credProtect' => null, 'hmacSecret' => null];
        if (($flags & 0x80) !== 0) {
            [$signed] = self::cbor($rest);
            $extensions = self::extensions($signed, $registration);
        }

        return new self($bytes, substr($bytes, 0, 32), ($flags & 0x01) !== 0, ($flags & 0x04) !== 0, ($flags & 0x08) !== 0, ($flags & 0x10) !== 0,
            $counter, $aaguid, $credentialId, $credentialKey, $extensions);
    }

    /**
     * @return array{mixed, int}
     *
     * @throws WebauthnError
     */
    private static function cbor(string $bytes): array
    {
        try {
            return Cbor::decodeFirst($bytes);
        } catch (InvalidArgumentException) {
            throw new WebauthnError('ParseNOMFailure');
        }
    }

    /**
     * RegistrationSignedExtensions: credProtect (1 to 3) and hmac-secret,
     * other keys kept but not used. Authentication keeps only a map.
     *
     * @return array{credProtect: ?int, hmacSecret: ?bool}
     *
     * @throws WebauthnError
     */
    private static function extensions(mixed $signed, bool $registration): array
    {
        if (! $signed instanceof CborMap) {
            throw new WebauthnError('ParseNOMFailure');
        }
        foreach ($signed->entries() as [$key]) {
            if (! is_string($key)) {
                throw new WebauthnError('ParseNOMFailure');
            }
        }
        if (! $registration) {
            return ['credProtect' => null, 'hmacSecret' => null];
        }

        $protect = $signed->get('credProtect');
        $hmac = $signed->get('hmac-secret');
        if (($protect !== null && (! is_int($protect) || $protect < 1 || $protect > 3)) || ($hmac !== null && ! is_bool($hmac))) {
            throw new WebauthnError('ParseNOMFailure');
        }

        return ['credProtect' => $protect, 'hmacSecret' => $hmac];
    }
}
