<?php

namespace App\Auth\Webauthn;

use App\Config\Config;
use App\Config\Url;
use App\Support\Cbor;
use App\Support\CborBytes;
use App\Support\CborMap;
use InvalidArgumentException;
use stdClass;

/**
 * webauthn-rs 0.5.5 as Vaultwarden builds it: the relying party is DOMAIN's
 * host, its origin DOMAIN's origin, its name DOMAIN; ceremonies time out
 * after a minute; credentials use ES256 or RS256.
 *
 * Attestation statements are checked for the formats none, packed,
 * fido-u2f and apple. TPM and Android key attestation are not ported and
 * fail like an unsupported format; clients send them only when asked for
 * attestation, which Vaultwarden does not do.
 */
final readonly class Webauthn
{
    private const int TIMEOUT_MILLIS = 60_000;

    private const array ALGORITHMS = ['ES256', 'RS256'];

    public function __construct(
        private Config $config,
    ) {}

    /**
     * start_passkey_registration: the options for the client and the state to keep.
     *
     * @param  list<string>  $exclude  Credential ids
     * @return array{array<string, mixed>, CeremonyState}
     *
     * @throws WebauthnError
     */
    public function startPasskeyRegistration(string $userUuid, string $userName, string $displayName, array $exclude): array
    {
        if ($userUuid === '' || $userName === '' || $displayName === '') {
            throw new WebauthnError('InvalidUsername');
        }
        $challenge = random_bytes(32);
        $extensions = ['credentialProtectionPolicy' => 'userVerificationRequired', 'enforceCredentialProtectionPolicy' => false, 'uvm' => true, 'credProps' => true];

        $options = [
            'rp' => ['name' => $this->config->string('domain'), 'id' => $this->rpId()],
            'user' => ['id' => Base64UrlSafe::encode((string) hex2bin(str_replace('-', '', $userUuid))), 'name' => $userName, 'displayName' => $displayName],
            'challenge' => Base64UrlSafe::encode($challenge),
            'pubKeyCredParams' => array_map(static fn (string $alg): array => ['type' => 'public-key', 'alg' => CoseKey::ALGORITHMS[$alg]], self::ALGORITHMS),
            'timeout' => self::TIMEOUT_MILLIS,
            'excludeCredentials' => array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => Base64UrlSafe::encode($id)], $exclude),
            'authenticatorSelection' => ['residentKey' => 'discouraged', 'requireResidentKey' => false, 'userVerification' => 'required'],
            'attestation' => 'none',
            'extensions' => $extensions,
        ];

        return [$options, new CeremonyState('registration', 'required', $challenge, $exclude, self::ALGORITHMS, extensions: $extensions)];
    }

    /**
     * finish_passkey_registration: the new credential, if the client's
     * response is sound (register_credential_internal).
     *
     * @param  array{attestationObject: string, clientDataJson: string, extensions?: mixed}  $response
     *
     * @throws WebauthnError
     */
    public function finishPasskeyRegistration(array $response, CeremonyState $state): Credential
    {
        $clientData = $this->clientData($response['clientDataJson']);
        try {
            $object = Cbor::decode($response['attestationObject']);
        } catch (InvalidArgumentException) {
            throw new WebauthnError('ParseCBORFailure');
        }
        $fmt = $object instanceof CborMap ? $object->get('fmt') : null;
        $authDataBytes = $object instanceof CborMap ? $object->get('authData') : null;
        if (! is_string($fmt) || ! $authDataBytes instanceof CborBytes || ! $object->has('attStmt')) {
            throw new WebauthnError('ParseCBORFailure');
        }
        $authData = AuthenticatorData::parse($authDataBytes->bytes, registration: true);

        $this->checkClientData($clientData, 'webauthn.create', $state->challenge);
        $clientDataHash = hash('sha256', $response['clientDataJson'], true);
        if ($authData->rpIdHash !== hash('sha256', $this->rpId(), true)) {
            throw new WebauthnError('InvalidRPIDHash');
        }
        if (! $authData->userPresent) {
            throw new WebauthnError('UserNotPresent');
        }
        if ($state->policy === 'required' && ! $authData->userVerified) {
            throw new WebauthnError('UserNotVerified');
        }

        $format = in_array($fmt, ['packed', 'tpm', 'android-key', 'android-safetynet', 'fido-u2f', 'apple', 'none'], true)
            ? $fmt : throw new WebauthnError('AttestationNotSupported');
        if ($authData->credentialId === null || $authData->credentialKey === null) {
            throw new WebauthnError('MissingAttestationCredentialData');
        }

        $statement = $object->get('attStmt');
        [$data, $metadata] = match ($format) {
            'fido-u2f' => [Attestation::fidoU2f($statement, $authData, $clientDataHash), 'None'],
            'packed' => Attestation::packed($statement, $authData, $clientDataHash),
            'apple' => Attestation::apple($statement, $authData, $clientDataHash),
            'none' => ['None', 'None'],
            default => throw new WebauthnError('AttestationNotSupported'),
        };

        $credential = new Credential(
            $authData->credentialId,
            CoseKey::fromCbor($authData->credentialKey),
            $authData->counter,
            null,
            $authData->userVerified,
            $authData->backupEligible,
            $authData->backupState,
            $state->policy,
            self::registeredExtensions($authData->extensions, $state->extensions),
            $data,
            $metadata,
            $format,
        );

        if (! in_array($credential->cred->type, $state->algorithms, true)) {
            throw new WebauthnError('CredentialAlteredAlgFromRequest');
        }
        if (! $state->allowSynchronised && $credential->backupEligible) {
            throw new WebauthnError('CredentialMayNotBeHardwareBound');
        }
        if ($credential->backupState && ! $credential->backupEligible) {
            throw new WebauthnError('CredentialMayNotBeHardwareBound');
        }
        if (in_array($credential->credId, $state->excludeCredentials, true)) {
            throw new WebauthnError('CredentialAlteredAlgFromRequest');
        }

        return $credential;
    }

    /**
     * start_passkey_authentication: the options and the state, for the credentials of the user.
     *
     * @param  list<Credential>  $credentials
     * @return array{array<string, mixed>, CeremonyState}
     */
    public function startPasskeyAuthentication(array $credentials): array
    {
        $challenge = random_bytes(32);
        $options = [
            'challenge' => Base64UrlSafe::encode($challenge),
            'timeout' => self::TIMEOUT_MILLIS,
            'rpId' => $this->rpId(),
            'allowCredentials' => array_map(static fn (Credential $credential): array => array_filter(['type' => 'public-key',
                'id' => Base64UrlSafe::encode($credential->credId), 'transports' => $credential->transports], static fn (mixed $v): bool => $v !== null), $credentials),
            'userVerification' => 'required',
        ];

        return [$options, new CeremonyState('authentication', 'required', $challenge, credentials: $credentials)];
    }

    /**
     * finish_passkey_authentication: the signature of a known credential
     * over this challenge (authenticate_credential and verify_credential_internal).
     *
     * @param  array{rawId: string, authenticatorData: string, clientDataJson: string, signature: string, appid: ?bool}  $response
     * @return array{credId: string, userVerified: bool, backupEligible: bool, backupState: bool, counter: int}
     *
     * @throws WebauthnError
     */
    public function finishPasskeyAuthentication(array $response, CeremonyState $state): array
    {
        $credential = null;
        foreach ($state->credentials as $candidate) {
            if ($candidate->credId === $response['rawId']) {
                $credential = $candidate;
                break;
            }
        }
        if ($credential === null) {
            throw new WebauthnError('CredentialNotFound');
        }

        $authData = AuthenticatorData::parse($response['authenticatorData'], registration: false);
        $clientData = $this->clientData($response['clientDataJson']);
        $this->checkClientData($clientData, 'webauthn.get', $state->challenge);

        $appidHash = ($response['appid'] ?? false) && $state->appid !== null ? hash('sha256', $state->appid, true) : null;
        if ($authData->rpIdHash !== hash('sha256', $this->rpId(), true) && $authData->rpIdHash !== $appidHash) {
            throw new WebauthnError('InvalidRPIDHash');
        }
        if (! $authData->userPresent) {
            throw new WebauthnError('UserNotPresent');
        }
        if (! $authData->userVerified && ($state->policy === 'required' || $credential->registrationPolicy === 'required'
            || ($credential->registrationPolicy === 'preferred' && $credential->userVerified))) {
            throw new WebauthnError('UserNotVerified');
        }
        if ($credential->backupEligible !== $authData->backupEligible
            && ! ($state->allowBackupEligibleUpgrade && ! $credential->backupEligible && $authData->backupEligible)) {
            throw new WebauthnError('CredentialBackupEligibilityInconsistent');
        }
        if ($authData->backupState && ! $credential->backupEligible) {
            throw new WebauthnError('CredentialMayNotBeHardwareBound');
        }

        $signed = $response['authenticatorData'].hash('sha256', $response['clientDataJson'], true);
        if (! $credential->cred->verify($response['signature'], $signed)) {
            throw new WebauthnError('AuthenticationFailure');
        }

        if (($authData->counter > 0 || $credential->counter > 0) && $authData->counter <= $credential->counter) {
            throw new WebauthnError('CredentialPossibleCompromise');
        }

        return ['credId' => $credential->credId, 'userVerified' => $authData->userVerified, 'backupEligible' => $authData->backupEligible,
            'backupState' => $authData->backupState, 'counter' => $authData->counter];
    }

    /**
     * Passkey::update_credential: whether the result changed the credential.
     *
     * @param  array{credId: string, userVerified: bool, backupEligible: bool, backupState: bool, counter: int}  $result
     */
    public static function updateCredential(Credential $credential, array $result): ?bool
    {
        if ($result['credId'] !== $credential->credId) {
            return null;
        }
        $changed = false;
        if ($result['counter'] > $credential->counter) {
            $credential->counter = $result['counter'];
            $changed = true;
        }
        if ($result['backupState'] !== $credential->backupState) {
            $credential->backupState = $result['backupState'];
            $changed = true;
        }
        if ($result['backupEligible'] && ! $credential->backupEligible) {
            $credential->backupEligible = true;
            $changed = true;
        }

        return $changed;
    }

    /**
     * The relying party id: DOMAIN's host name.
     */
    private function rpId(): string
    {
        return Url::domain($this->config->string('domain')) ?? '';
    }

    /**
     * CollectedClientData: type, challenge and origin are required.
     *
     * @return array{type: string, challenge: string, origin: string, crossOrigin: ?bool}
     *
     * @throws WebauthnError
     */
    private function clientData(string $json): array
    {
        $value = json_decode($json, false, 512, JSON_BIGINT_AS_STRING);
        $challenge = $value instanceof stdClass ? Base64UrlSafe::decode($value->challenge ?? null) : null;
        $origin = $value instanceof stdClass ? ($value->origin ?? null) : null;
        $crossOrigin = $value instanceof stdClass ? ($value->crossOrigin ?? null) : null;
        $tokenBinding = $value instanceof stdClass ? ($value->tokenBinding ?? null) : null;
        if (! $value instanceof stdClass || ! is_string($value->type ?? null) || $challenge === null || ! is_string($origin) || ! Url::isValid($origin)
            || ($crossOrigin !== null && ! is_bool($crossOrigin))
            || ($tokenBinding !== null && (! $tokenBinding instanceof stdClass || ! is_string($tokenBinding->status ?? null)))) {
            throw new WebauthnError('ParseJSONFailure');
        }

        return ['type' => $value->type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => $crossOrigin];
    }

    /**
     * @param  array{type: string, challenge: string, origin: string, crossOrigin: ?bool}  $clientData
     *
     * @throws WebauthnError
     */
    private function checkClientData(array $clientData, string $type, string $challenge): void
    {
        if ($clientData['type'] !== $type) {
            throw new WebauthnError('InvalidClientDataType');
        }
        if (! hash_equals($challenge, $clientData['challenge'])) {
            throw new WebauthnError('MismatchedChallenge');
        }
        // origins_match without subdomains or other ports: the same scheme, host and port.
        $allowed = $this->config->string('domain_origin');
        if (Url::origin($clientData['origin']) !== Url::origin($allowed) || Url::origin($allowed) === 'null') {
            throw new WebauthnError('InvalidRPOrigin');
        }
        if ($clientData['crossOrigin'] === true) {
            throw new WebauthnError('CredentialCrossOrigin');
        }
    }

    /**
     * RegisteredExtensions from what the authenticator signed and what was requested.
     *
     * @param  array{credProtect: ?int, hmacSecret: ?bool}  $signed
     * @param  array<string, mixed>  $requested
     * @return array{cred_protect: mixed, hmac_create_secret: mixed, appid: mixed, cred_props: mixed}
     */
    private static function registeredExtensions(array $signed, array $requested): array
    {
        $policies = [1 => 'userVerificationOptional', 2 => 'userVerificationOptionalWithCredentialIDList', 3 => 'userVerificationRequired'];
        $state = static fn (mixed $value, bool $wanted): mixed => match (true) {
            $value !== null => [$wanted ? 'Set' : 'Unsolicited' => $value],
            $wanted => 'Ignored',
            default => 'NotRequested',
        };

        return [
            'cred_protect' => $state($signed['credProtect'] === null ? null : $policies[$signed['credProtect']], isset($requested['credentialProtectionPolicy'])),
            'hmac_create_secret' => $state($signed['hmacSecret'], isset($requested['hmacCreateSecret'])),
            'appid' => 'NotRequested',
            // The client's credProps output is not kept by Vaultwarden.
            'cred_props' => isset($requested['credProps']) ? 'Ignored' : 'NotRequested',
        ];
    }
}
