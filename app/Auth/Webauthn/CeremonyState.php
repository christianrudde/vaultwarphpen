<?php

namespace App\Auth\Webauthn;

use App\Http\Json;
use stdClass;

/**
 * The server side of a ceremony between its start and its finish:
 * RegistrationState ({"rs": ...} in PasskeyRegistration) or
 * AuthenticationState ({"ast": ...} in PasskeyAuthentication). Vaultwarden
 * stores them through serde_json::to_value, so their keys are sorted.
 */
final class CeremonyState
{
    /**
     * @param  'registration'|'authentication'  $ceremony
     * @param  list<string>  $excludeCredentials  Registration: credential ids not to register again
     * @param  list<string>  $algorithms  Registration: COSEAlgorithm names
     * @param  array<string, mixed>  $extensions  Registration: RequestRegistrationExtensions JSON
     * @param  list<Credential>  $credentials  Authentication: the credentials that may answer
     */
    public function __construct(
        public string $ceremony,
        public string $policy,
        public string $challenge,
        public array $excludeCredentials = [],
        public array $algorithms = [],
        public bool $requireResidentKey = false,
        public ?string $authenticatorAttachment = null,
        public array $extensions = [],
        public bool $allowSynchronised = true,
        public array $credentials = [],
        public ?string $appid = null,
        public bool $allowBackupEligibleUpgrade = true,
    ) {}

    /**
     * PasskeyRegistration or PasskeyAuthentication as serde reads it; null when it does not.
     *
     * @param  'registration'|'authentication'  $ceremony
     */
    public static function fromJson(string $ceremony, mixed $value): ?self
    {
        $state = $value instanceof stdClass ? ($value->{$ceremony === 'registration' ? 'rs' : 'ast'} ?? null) : null;
        if (! $state instanceof stdClass) {
            return null;
        }
        $policy = $state->policy ?? null;
        $challenge = Base64UrlSafe::decode($state->challenge ?? null);
        if (! in_array($policy, ['required', 'preferred', 'discouraged'], true) || $challenge === null) {
            return null;
        }

        if ($ceremony === 'authentication') {
            $credentials = self::credentials($state->credentials ?? null);
            $appid = $state->appid ?? null;
            if ($credentials === null || ($appid !== null && ! is_string($appid)) || ! is_bool($state->allow_backup_eligible_upgrade ?? null)) {
                return null;
            }

            return new self('authentication', $policy, $challenge, credentials: $credentials, appid: $appid,
                allowBackupEligibleUpgrade: $state->allow_backup_eligible_upgrade);
        }

        $exclude = [];
        foreach (is_array($state->exclude_credentials ?? null) ? $state->exclude_credentials : [false] as $id) {
            $exclude[] = Base64UrlSafe::decode($id);
        }
        $algorithms = [];
        foreach (is_array($state->credential_algorithms ?? null) ? $state->credential_algorithms : [false] as $algorithm) {
            $algorithms[] = is_string($algorithm) ? CoseKey::algorithmName($algorithm) : null;
        }
        $attachment = $state->authenticator_attachment ?? null;
        $extensions = self::registrationExtensions($state->extensions ?? null);
        if (in_array(null, $exclude, true) || in_array(null, $algorithms, true) || ! is_bool($state->require_resident_key ?? null)
            || ! in_array($attachment, [null, 'platform', 'cross-platform'], true) || $extensions === null || ! is_bool($state->allow_synchronised_authenticators ?? null)) {
            return null;
        }

        return new self('registration', $policy, $challenge, array_values(array_filter($exclude, is_string(...))), array_values(array_filter($algorithms, is_string(...))),
            $state->require_resident_key, $attachment, $extensions, $state->allow_synchronised_authenticators);
    }

    /**
     * The JSON Vaultwarden stores, with sorted keys.
     *
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        $state = $this->ceremony === 'registration' ? ['rs' => [
            'policy' => $this->policy,
            'exclude_credentials' => array_map(Base64UrlSafe::encode(...), $this->excludeCredentials),
            'challenge' => Base64UrlSafe::encode($this->challenge),
            'credential_algorithms' => $this->algorithms,
            'require_resident_key' => $this->requireResidentKey,
            'authenticator_attachment' => $this->authenticatorAttachment,
            'extensions' => $this->extensions === [] ? new stdClass : $this->extensions,
            'allow_synchronised_authenticators' => $this->allowSynchronised,
        ]] : ['ast' => [
            'credentials' => array_map(static fn (Credential $credential): array => $credential->toJson(), $this->credentials),
            'policy' => $this->policy,
            'challenge' => Base64UrlSafe::encode($this->challenge),
            'appid' => $this->appid,
            'allow_backup_eligible_upgrade' => $this->allowBackupEligibleUpgrade,
        ]];

        return Json::sorted($state);
    }

    /**
     * A list of credentials, or null when one of them does not parse.
     *
     * @return list<Credential>|null
     */
    public static function credentials(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $credentials = [];
        foreach ($value as $item) {
            $credential = Credential::fromJson($item);
            if ($credential === null) {
                return null;
            }
            $credentials[] = $credential;
        }

        return $credentials;
    }

    /**
     * RequestRegistrationExtensions: credProtect flattened into the object.
     *
     * @return array<string, mixed>|null
     */
    private static function registrationExtensions(mixed $value): ?array
    {
        if (! $value instanceof stdClass) {
            return null;
        }
        $extensions = [];
        $policy = $value->credentialProtectionPolicy ?? null;
        if ($policy !== null) {
            $enforce = $value->enforceCredentialProtectionPolicy ?? null;
            if (! in_array($policy, ['userVerificationOptional', 'userVerificationOptionalWithCredentialIDList', 'userVerificationRequired'], true)
                || ($enforce !== null && ! is_bool($enforce))) {
                return null;
            }
            $extensions['credentialProtectionPolicy'] = $policy;
            if ($enforce !== null) {
                $extensions['enforceCredentialProtectionPolicy'] = $enforce;
            }
        }
        foreach (['uvm', 'credProps', 'minPinLength', 'hmacCreateSecret'] as $name) {
            $flag = $value->{$name} ?? null;
            if ($flag !== null && ! is_bool($flag)) {
                return null;
            }
            if ($flag !== null) {
                $extensions[$name] = $flag;
            }
        }

        return $extensions;
    }
}
