<?php

namespace App\Auth;

use App\Config\Config;
use App\Http\Json;
use App\Models\OrgPolicy;
use App\Models\User;

/**
 * The master password policy a user must follow (api::master_password_policy):
 * all of their organizations' policies merged into the strictest one.
 */
final class MasterPasswordPolicy
{
    private const array FLAGS = ['requireLower', 'requireUpper', 'requireNumbers', 'requireSpecial', 'enforceOnLogin'];

    /**
     * The policy with camelCase keys, as the API shows it.
     *
     * @return array<string, mixed>
     */
    public static function forUser(User $user, Config $config): array
    {
        $policies = [];
        foreach (OrgPolicy::findAcceptedAndConfirmedByUserAndActivePolicy($user->uuid, OrgPolicy::TYPE_MASTER_PASSWORD) as $policy) {
            $parsed = self::parse($policy->data);
            if ($parsed !== null) {
                $policies[] = $parsed;
            }
        }

        if ($policies !== []) {
            $merged = array_shift($policies);
            foreach ($policies as $policy) {
                foreach (['minComplexity', 'minLength'] as $key) {
                    // Option::max: None is below any number.
                    $merged[$key] = $merged[$key] === null ? $policy[$key] : ($policy[$key] === null ? $merged[$key] : max($merged[$key], $policy[$key]));
                }
                foreach (self::FLAGS as $key) {
                    $merged[$key] = $merged[$key] || $policy[$key];
                }
            }
            $result = $merged;
        } elseif ($config->bool('sso_enabled')) {
            $sso = json_decode((string) $config->optionalString('sso_master_password_policy'), true);
            $result = is_array($sso) ? $sso : [];
        } else {
            $result = [];
        }

        // Upstream keeps PascalCase for this one key.
        $result['Object'] = 'masterPasswordPolicy';

        /** @var array<string, mixed> */
        return Json::sorted($result);
    }

    /**
     * The identity endpoints write every key with an upper-case first letter.
     *
     * @return array<string, mixed>
     */
    public static function forIdentity(User $user, Config $config): array
    {
        $policy = [];
        foreach (self::forUser($user, $config) as $key => $value) {
            $policy[ucfirst($key)] = $value;
        }

        return Json::sorted($policy);
    }

    /**
     * Deserializing MasterPasswordPolicy; policies that do not fit are skipped.
     *
     * @return array{minComplexity: ?int, minLength: ?int, requireLower: bool, requireUpper: bool, requireNumbers: bool, requireSpecial: bool, enforceOnLogin: bool}|null
     */
    private static function parse(string $data): ?array
    {
        $decoded = json_decode($data, true);
        if (! is_array($decoded)) {
            return null;
        }

        $minComplexity = $decoded['minComplexity'] ?? null;
        $minLength = $decoded['minLength'] ?? null;
        if (($minComplexity !== null && (! is_int($minComplexity) || $minComplexity < 0 || $minComplexity > 255))
            || ($minLength !== null && (! is_int($minLength) || $minLength < 0 || $minLength > 4_294_967_295))) {
            return null;
        }

        $flags = [];
        foreach (self::FLAGS as $key) {
            if (! is_bool($decoded[$key] ?? null)) {
                return null;
            }
            $flags[$key] = $decoded[$key];
        }

        return ['minComplexity' => $minComplexity, 'minLength' => $minLength, ...$flags];
    }
}
