<?php

namespace App\Http\Controllers\TwoFactor;

use App\Auth\Headers;
use App\Auth\TwoFactorTokens;
use App\Auth\Webauthn\Base64UrlSafe;
use App\Auth\Webauthn\CeremonyState;
use App\Auth\Webauthn\Credential;
use App\Auth\Webauthn\Registrations;
use App\Auth\Webauthn\U2fRegistration;
use App\Auth\Webauthn\Webauthn;
use App\Auth\Webauthn\WebauthnError;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\Fields;
use App\Http\Data\PasswordOrOtpData;
use App\Http\HttpStatus;
use App\Http\Json;
use App\Models\Event;
use App\Models\TwoFactor;
use App\Support\JsonValue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use stdClass;

/**
 * src/api/core/two_factor/webauthn.rs: security keys as a second factor,
 * registered as passkeys but used without user verification. The method
 * stores a list of WebauthnRegistration {id, name, migrated, credential},
 * where credential is a webauthn-rs Passkey.
 */
final readonly class WebauthnController
{
    public function __construct(
        private Config $config,
        private Webauthn $webauthn,
    ) {}

    /**
     * POST /api/two-factor/get-webauthn
     */
    public function getWebauthn(Headers $headers, Request $request): Response
    {
        $data = PasswordOrOtpData::fromFields(Body::json($request));
        if (! $this->config->isWebauthn2faSupported()) {
            throw ApiError::message('Configured `DOMAIN` is not compatible with Webauthn');
        }
        $user = $headers->user;
        $data->validate($user, false, $this->config);

        [$enabled, $registrations] = self::registrations($user->uuid);

        return Json::response(Json::sorted([
            'webAuthn' => ['enabled' => $enabled, 'keys' => array_map(self::keyJson(...), $registrations)],
            'userVerificationToken' => TwoFactorTokens::webauthn($user->uuid, array_column($registrations, 'id'), $enabled),
        ]));
    }

    /**
     * POST /api/two-factor/get-webauthn-challenge: the options to create a
     * credential with; the state waits as a challenge of the user.
     */
    public function generateWebauthnChallenge(Headers $headers, Request $request): Response
    {
        $token = Body::json($request)->string('userVerificationToken');
        $user = $headers->user;

        [$enabled, $registrations] = self::registrations($user->uuid);
        TwoFactorTokens::validateWebauthn($token, $user->uuid, array_column($registrations, 'id'), $enabled);

        try {
            [$options, $state] = $this->webauthn->startPasskeyRegistration($user->uuid, $user->email, $user->displayName(),
                array_map(static fn (array $registration): string => $registration['credential']->credId, $registrations));
        } catch (WebauthnError $error) {
            throw $error->toApiError();
        }

        // Used as a security key: no user verification and no extensions.
        $state->policy = 'discouraged';
        $state->extensions = [];
        TwoFactor::new($user->uuid, TwoFactor::TYPE_WEBAUTHN_REGISTER_CHALLENGE, Json::encode($state->toJson()))->save();

        unset($options['extensions']);
        $options['authenticatorSelection'] = ['residentKey' => 'discouraged', 'requireResidentKey' => false, 'userVerification' => 'discouraged'];

        return Json::response(Json::sorted(['options' => [...$options, 'status' => 'ok', 'errorMessage' => '']]));
    }

    /**
     * POST and PUT /api/two-factor/webauthn: the new credential joins the others.
     */
    public function activateWebauthn(Headers $headers, Request $request): Response
    {
        $body = Body::json($request);
        $id = $body->int('id');
        $name = $body->string('name');
        $device = $body->object('deviceResponse');
        $device->string('id');
        self::binary($device, 'rawId');
        $response = $device->object('response');
        $attestationObject = self::binary($response, 'AttestationObject', 'attestationObject');
        $clientDataJson = self::binary($response, 'clientDataJson', 'clientDataJSON');
        $device->string('type');
        $token = $body->string('userVerificationToken');
        $user = $headers->user;

        [, $registrations] = self::registrations($user->uuid);
        $keys = array_column($registrations, 'id');
        TwoFactorTokens::validateWebauthn($token, $user->uuid, $keys, $keys !== []);

        $challenge = TwoFactor::findByUserAndType($user->uuid, TwoFactor::TYPE_WEBAUTHN_REGISTER_CHALLENGE)
            ?? throw ApiError::message("Can't recover challenge");
        $state = CeremonyState::fromJson('registration', JsonValue::decode($challenge->data)[1]) ?? throw ApiError::message('Serde');
        $challenge->deleteTwoFactor();

        try {
            $credential = $this->webauthn->finishPasskeyRegistration(['attestationObject' => $attestationObject, 'clientDataJson' => $clientDataJson], $state);
        } catch (WebauthnError $error) {
            throw $error->toApiError();
        }

        $registrations[] = ['id' => $id, 'name' => $name, 'migrated' => false, 'credential' => $credential];
        self::save($user->uuid, $registrations);
        TwoFactorController::generateRecoverCode($user);
        Event::logUser(Event::USER_UPDATED_2FA, $user->uuid, $headers->device->atype, $headers->ip);

        return Json::response(Json::sorted(['webAuthn' => ['enabled' => true, 'keys' => array_map(self::keyJson(...), $registrations)]]));
    }

    /**
     * DELETE /api/two-factor/webauthn: one key, by id.
     */
    public function deleteWebauthn(Headers $headers, Request $request): Response
    {
        $body = Body::json($request);
        $id = $body->int('id');

        return $this->deleteKeys($body->string('userVerificationToken'), static fn (array $key): bool => $key['id'] !== $id, $headers);
    }

    /**
     * DELETE /api/two-factor/webauthn/all
     */
    public function deleteWebauthns(Headers $headers, Request $request): Response
    {
        return $this->deleteKeys(Body::json($request)->string('userVerificationToken'), static fn (): bool => false, $headers);
    }

    /**
     * generate_webauthn_login: the options for the login prompt; the state
     * waits as the user's login challenge.
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    public static function generateWebauthnLogin(string $userUuid): array
    {
        $credentials = array_map(static fn (array $registration): Credential => $registration['credential'], self::registrations($userUuid)[1]);
        if ($credentials === []) {
            throw ApiError::message('No Webauthn devices registered');
        }

        [$options, $state] = app(Webauthn::class)->startPasskeyAuthentication($credentials);
        // Only used for U2F compatibility.
        $appid = app(Config::class)->string('domain').'/app-id.json';
        $state->policy = 'discouraged';
        $state->appid = $appid;
        $options['userVerification'] = 'discouraged';
        $options['extensions'] = ['appid' => $appid];

        TwoFactor::new($userUuid, TwoFactor::TYPE_WEBAUTHN_LOGIN_CHALLENGE, Json::encode($state->toJson()))->save();

        return Json::sorted($options);
    }

    /**
     * validate_webauthn_login: the client's assertion for the waiting challenge.
     *
     * @throws ApiError
     */
    public static function validateWebauthnLogin(string $userUuid, string $response): void
    {
        $challenge = TwoFactor::findByUserAndType($userUuid, TwoFactor::TYPE_WEBAUTHN_LOGIN_CHALLENGE)
            ?? throw ApiError::message("Can't recover login challenge", event: Event::USER_FAILED_LOG_IN_2FA);
        $state = CeremonyState::fromJson('authentication', JsonValue::decode($challenge->data)[1]) ?? throw ApiError::message('Serde');
        $challenge->deleteTwoFactor();

        $assertion = self::assertion($response) ?? throw ApiError::message('Serde');
        [, $registrations] = self::registrations($userUuid);
        $backupFlagsUpdated = self::checkAndUpdateBackupEligible($assertion, $registrations, $state);

        try {
            $result = app(Webauthn::class)->finishPasskeyAuthentication($assertion, $state);
        } catch (WebauthnError $error) {
            throw $error->toApiError();
        }

        foreach ($registrations as $registration) {
            if (hash_equals($registration['credential']->credId, $result['credId'])) {
                if (Webauthn::updateCredential($registration['credential'], $result) === true || $backupFlagsUpdated) {
                    self::save($userUuid, $registrations);
                }

                return;
            }
        }

        throw ApiError::message('Credential not present', event: Event::USER_FAILED_LOG_IN_2FA);
    }

    /**
     * inner_delete_webauthns: keys a migrated U2F registration came from go there as well.
     *
     * @param  callable(array{id: int, name: string, migrated: bool, credential: Credential}): bool  $retain
     */
    private function deleteKeys(string $token, callable $retain, Headers $headers): Response
    {
        $user = $headers->user;
        $twoFactor = TwoFactor::findByUserAndType($user->uuid, TwoFactor::TYPE_WEBAUTHN) ?? throw ApiError::message('Webauthn data not found!');
        $keys = Registrations::parse($twoFactor->data) ?? throw ApiError::message('Serde');
        TwoFactorTokens::validateWebauthn($token, $user->uuid, array_column($keys, 'id'), true);

        $removed = [];
        $migrated = false;
        $kept = [];
        foreach ($keys as $key) {
            if ($retain($key)) {
                $kept[] = $key;
            } else {
                $removed[] = $key['credential']->credId;
                $migrated = $migrated || $key['migrated'];
            }
        }
        if ($removed === []) {
            throw ApiError::message('Webauthn entry not found');
        }

        if ($kept === []) {
            $twoFactor->deleteTwoFactor();
            Event::logUser(Event::USER_DISABLED_2FA, $user->uuid, $headers->device->atype, $headers->ip);
        } else {
            $twoFactor->data = Registrations::encode($kept);
            $twoFactor->save();
        }

        $u2f = $migrated ? TwoFactor::findByUserAndType($user->uuid, TwoFactor::TYPE_U2F) : null;
        if ($u2f !== null) {
            $remaining = self::remainingU2f($u2f->data, $removed) ?? throw ApiError::message('Error parsing U2F data');
            if ($remaining === []) {
                $u2f->deleteTwoFactor();
            } else {
                $u2f->data = Json::encode($remaining);
                $u2f->save();
            }
        }

        TwoFactorController::check2faState($user, $headers->device->atype, $headers->ip);

        return Json::response(Json::sorted(['webAuthn' => ['enabled' => $kept !== [], 'keys' => array_map(self::keyJson(...), $kept)]]));
    }

    /**
     * get_webauthn_registrations: the method's state and its keys.
     *
     * @return array{bool, list<array{id: int, name: string, migrated: bool, credential: Credential}>}
     *
     * @throws ApiError
     */
    public static function registrations(string $userUuid): array
    {
        $twoFactor = TwoFactor::findByUserAndType($userUuid, TwoFactor::TYPE_WEBAUTHN);
        if ($twoFactor === null) {
            return [false, []];
        }

        return [$twoFactor->enabled, Registrations::parse($twoFactor->data) ?? throw ApiError::message('Serde')];
    }

    /**
     * @param  list<array{id: int, name: string, migrated: bool, credential: Credential}>  $registrations
     *
     * @throws ApiError
     */
    private static function save(string $userUuid, array $registrations): void
    {
        TwoFactor::new($userUuid, TwoFactor::TYPE_WEBAUTHN, Registrations::encode($registrations))->save();
    }

    /**
     * WebauthnRegistration::to_json
     *
     * @param  array{id: int, name: string, migrated: bool, credential: Credential}  $registration
     * @return array<string, mixed>
     */
    private static function keyJson(array $registration): array
    {
        return ['id' => $registration['id'], 'name' => $registration['name'], 'migrated' => $registration['migrated']];
    }

    /**
     * Base64UrlSafeData in the body; anything else fails like serde (422).
     *
     * @throws HttpStatus
     */
    private static function binary(Fields $fields, string $name, string ...$aliases): string
    {
        return Base64UrlSafe::decode($fields->value($name, ...$aliases)) ?? throw new HttpStatus(422, "Field `{$name}` must be base64.");
    }

    /**
     * PublicKeyCredentialCopy: the assertion the client sends as its 2FA token.
     *
     * @return array{rawId: string, authenticatorData: string, clientDataJson: string, signature: string, appid: ?bool}|null
     */
    private static function assertion(string $json): ?array
    {
        [$ok, $value] = JsonValue::decode($json);
        $response = $ok && $value instanceof stdClass ? ($value->response ?? null) : null;
        $extensions = $ok && $value instanceof stdClass ? ($value->extensions ?? null) : null;
        if (! $value instanceof stdClass || ! $response instanceof stdClass || ! $extensions instanceof stdClass
            || ! is_string($value->id ?? null) || ! is_string($value->type ?? null)
            || (property_exists($response, 'clientDataJson') && property_exists($response, 'clientDataJSON'))) {
            return null;
        }
        $appid = $extensions->appid ?? null;
        $rawId = Base64UrlSafe::decode($value->rawId ?? null);
        $authenticatorData = Base64UrlSafe::decode($response->authenticatorData ?? null);
        $clientDataJson = Base64UrlSafe::decode($response->clientDataJson ?? $response->clientDataJSON ?? null);
        $signature = Base64UrlSafe::decode($response->signature ?? null);
        $userHandle = $response->userHandle ?? null;
        if ($rawId === null || $authenticatorData === null || $clientDataJson === null || $signature === null
            || ($userHandle !== null && Base64UrlSafe::decode($userHandle) === null) || ($appid !== null && ! is_bool($appid))) {
            return null;
        }

        return ['rawId' => $rawId, 'authenticatorData' => $authenticatorData, 'clientDataJson' => $clientDataJson, 'signature' => $signature, 'appid' => $appid];
    }

    /**
     * check_and_update_backup_eligible: keys registered before webauthn-rs
     * knew the backup flags learn them at their next use, in the stored
     * registrations and in the waiting state alike.
     *
     * @param  array{rawId: string, authenticatorData: string, clientDataJson: string, signature: string, appid: ?bool}  $assertion
     * @param  list<array{id: int, name: string, migrated: bool, credential: Credential}>  $registrations
     */
    private static function checkAndUpdateBackupEligible(array $assertion, array $registrations, CeremonyState $state): bool
    {
        $flags = strlen($assertion['authenticatorData']) > 32 ? ord($assertion['authenticatorData'][32]) : 0;
        $eligible = ($flags & 0x08) !== 0;
        $backedUp = ($flags & 0x10) !== 0;
        if (! $eligible) {
            return false;
        }

        foreach ($registrations as $registration) {
            $credential = $registration['credential'];
            if (! hash_equals($credential->credId, $assertion['rawId'])) {
                continue;
            }
            $changed = $credential->backupState !== $backedUp || ! $credential->backupEligible;
            if (! $changed) {
                return false;
            }
            $credential->backupState = $backedUp;
            $credential->backupEligible = true;
            foreach ($state->credentials as $waiting) {
                if (hash_equals($waiting->credId, $assertion['rawId'])) {
                    $waiting->backupEligible = true;
                    $waiting->backupState = $backedUp;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The U2F registrations whose key handle was not removed, as serde writes them again.
     *
     * @param  list<string>  $removed
     * @return list<array<string, mixed>>|null
     */
    private static function remainingU2f(string $json, array $removed): ?array
    {
        [$ok, $value] = JsonValue::decode($json);
        if (! $ok || ! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        $remaining = [];
        foreach ($value as $item) {
            $registration = U2fRegistration::fromJson($item);
            if ($registration === null) {
                return null;
            }
            if (! in_array($registration->keyHandle, $removed, true)) {
                $remaining[] = $registration->toJson();
            }
        }

        return $remaining;
    }
}
