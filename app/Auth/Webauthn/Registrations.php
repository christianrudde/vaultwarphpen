<?php

namespace App\Auth\Webauthn;

use App\Http\Json;
use App\Support\JsonValue;
use stdClass;

/**
 * Vaultwarden's Vec<WebauthnRegistration>: {id, name, migrated, credential},
 * where credential is a Passkey {"cred": CredentialV5}.
 */
final class Registrations
{
    /**
     * Vec<WebauthnRegistration> as serde reads it.
     *
     * @return list<array{id: int, name: string, migrated: bool, credential: Credential}>|null
     */
    public static function parse(string $json): ?array
    {
        [$ok, $value] = JsonValue::decode($json);
        if (! $ok || ! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $registrations = [];
        foreach ($value as $item) {
            $id = $item instanceof stdClass ? ($item->id ?? null) : null;
            $passkey = $item instanceof stdClass ? ($item->credential ?? null) : null;
            $credential = $passkey instanceof stdClass ? Credential::fromJson($passkey->cred ?? null) : null;
            if (! is_int($id) || $id < -2147483648 || $id > 2147483647 || ! is_string($item->name ?? null) || ! is_bool($item->migrated ?? null) || $credential === null) {
                return null;
            }
            $registrations[] = ['id' => $id, 'name' => $item->name, 'migrated' => $item->migrated, 'credential' => $credential];
        }

        return $registrations;
    }

    /**
     * @param  list<array{id: int, name: string, migrated: bool, credential: Credential}>  $registrations
     */
    public static function encode(array $registrations): string
    {
        return Json::encode(array_map(static fn (array $registration): array => ['id' => $registration['id'], 'name' => $registration['name'],
            'migrated' => $registration['migrated'], 'credential' => ['cred' => $registration['credential']->toJson()]], $registrations));
    }

    /**
     * Vec<WebauthnRegistrationV3>, of webauthn-rs 0.3, converted like
     * From<CredentialV3> for Credential; null when the JSON is not of that form.
     *
     * @return list<array{id: int, name: string, migrated: bool, credential: Credential}>|null
     */
    public static function parseV3(string $json): ?array
    {
        [$ok, $value] = JsonValue::decode($json);
        if (! $ok || ! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $registrations = [];
        foreach ($value as $item) {
            $old = $item instanceof stdClass ? ($item->credential ?? null) : null;
            $id = $item instanceof stdClass ? ($item->id ?? null) : null;
            $credId = $old instanceof stdClass && is_array($old->cred_id ?? null) ? Base64UrlSafe::decode($old->cred_id) : null;
            $key = $old instanceof stdClass ? CoseKey::fromJson($old->cred ?? null) : null;
            $counter = $old instanceof stdClass ? ($old->counter ?? null) : null;
            $policy = $old instanceof stdClass ? ($old->registration_policy ?? null) : null;
            if (! is_int($id) || ! is_string($item->name ?? null) || ! is_bool($item->migrated ?? null) || $credId === null || $key === null
                || ! is_int($counter) || $counter < 0 || $counter > 0xFFFFFFFF || ! is_bool($old->verified ?? null)
                || ! in_array($policy, ['required', 'preferred', 'discouraged'], true)) {
                return null;
            }
            $registrations[] = ['id' => $id, 'name' => $item->name, 'migrated' => $item->migrated,
                'credential' => Credential::legacy($credId, $key, $counter, $old->verified, $policy)];
        }

        return $registrations;
    }
}
