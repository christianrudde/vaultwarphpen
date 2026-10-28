<?php

namespace App\Http\Controllers;

use App\Auth\Headers;
use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\AuthenticationData;
use App\Http\Data\Body;
use App\Http\Data\Fields;
use App\Http\Data\NumberOrString;
use App\Http\Data\UnlockData;
use App\Http\EmptyResponse;
use App\Http\HttpStatus;
use App\Http\Json;
use App\Mail\Mail;
use App\Models\Cipher;
use App\Models\EmergencyAccess;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\OrgPolicy;
use App\Models\TwoFactor;
use App\Models\User;
use App\Support\Dates;
use App\Support\EscapeDebug;
use App\Support\Uuid;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

/**
 * src/api/core/emergency_access.rs: grantors invite trusted contacts, who
 * may request to view or take over the grantor's vault. The grantor can
 * approve or reject; without an answer the request is granted after the
 * wait time by the scheduled jobs (App\Scheduler\Jobs).
 */
final readonly class EmergencyAccessController
{
    public function __construct(
        private Config $config,
        private Jwt $jwt,
    ) {}

    /**
     * GET /api/emergency-access/trusted: the grantees of the user.
     */
    public function getContacts(Headers $headers): Response
    {
        $accesses = $this->config->bool('emergency_access_allowed') ? EmergencyAccess::findAllByGrantorUuid($headers->user->uuid) : [];
        $data = [];
        foreach ($accesses as $access) {
            $grantee = $access->toApiGranteeDetails();
            if ($grantee !== null) {
                $data[] = $grantee;
            }
        }

        return Json::response(Json::sorted(['data' => $data, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * GET /api/emergency-access/granted: the grantors who trust the user.
     */
    public function getGrantees(Headers $headers): Response
    {
        $accesses = $this->config->bool('emergency_access_allowed') ? EmergencyAccess::findAllByGranteeUuid($headers->user->uuid) : [];
        $data = [];
        foreach ($accesses as $access) {
            $data[] = $access->toApiGrantorDetails();
        }

        return Json::response(Json::sorted(['data' => $data, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * GET /api/emergency-access/{emer_id}
     */
    public function getEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        $emerId = Uuid::parameter($request, $emerId);
        $this->checkEmergencyAccessEnabled();

        $access = EmergencyAccess::findByUuidAndGrantorUuid($emerId, $headers->user->uuid) ?? throw ApiError::message('Emergency access not valid.');

        return Json::response($access->toApiGranteeDetails() ?? throw new LogicException('Grantee user should exist but does not!'));
    }

    /**
     * PUT /api/emergency-access/{emer_id}
     */
    public function putEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        return $this->postEmergencyAccess($headers, $request, $emerId);
    }

    /**
     * POST /api/emergency-access/{emer_id}: the type, the wait time and the key.
     */
    public function postEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        $emerId = Uuid::parameter($request, $emerId);
        $body = Body::json($request);
        $type = self::rawType($body);
        $waitTimeDays = $body->int('waitTimeDays');
        $keyEncrypted = $body->optionalString('keyEncrypted');
        $this->checkEmergencyAccessEnabled();

        $newType = EmergencyAccess::typeFromString($type) ?? throw ApiError::message('Invalid emergency access type.');
        if (! EmergencyAccess::isValidWaitTime($waitTimeDays)) {
            throw ApiError::message('Emergency access wait time is out-of-bounds!');
        }

        $access = EmergencyAccess::findByUuidAndGrantorUuid($emerId, $headers->user->uuid) ?? throw ApiError::message('Emergency access not valid.');
        $access->atype = $newType;
        $access->wait_time_days = $waitTimeDays;
        if ($keyEncrypted !== null) {
            $access->key_encrypted = $keyEncrypted;
        }
        $access->save();

        return Json::response($access->toApi());
    }

    /**
     * DELETE /api/emergency-access/{emer_id}: by the grantor or the grantee.
     */
    public function deleteEmergencyAccess(Headers $headers, Request $request, string $emerId): EmptyResponse
    {
        $emerId = Uuid::parameter($request, $emerId);
        $this->checkEmergencyAccessEnabled();

        $asGrantor = EmergencyAccess::findByUuidAndGrantorUuid($emerId, $headers->user->uuid);
        $asGrantee = EmergencyAccess::findByUuidAndGranteeUuid($emerId, $headers->user->uuid);
        if ($asGrantor !== null && $asGrantee === null) {
            logger()->info("Grantor deleted emergency access {$emerId}");
            $access = $asGrantor;
        } elseif ($asGrantor === null && $asGrantee !== null) {
            logger()->info("Grantee deleted emergency access {$emerId}");
            $access = $asGrantee;
        } else {
            throw ApiError::message('Emergency access not valid.');
        }

        $access->deleteAccess();

        return new EmptyResponse;
    }

    /**
     * POST /api/emergency-access/{emer_id}/delete
     */
    public function postDeleteEmergencyAccess(Headers $headers, Request $request, string $emerId): EmptyResponse
    {
        return $this->deleteEmergencyAccess($headers, $request, $emerId);
    }

    /**
     * POST /api/emergency-access/invite: an unknown email gets an invited
     * user. Without mail, existing users accept right away.
     */
    public function sendInvite(Headers $headers, Request $request): EmptyResponse
    {
        $body = Body::json($request);
        $email = $body->string('email');
        $type = self::rawType($body);
        $waitTimeDays = $body->int('waitTimeDays');
        $this->checkEmergencyAccessEnabled();

        $email = mb_strtolower($email);
        if (! EmergencyAccess::isValidWaitTime($waitTimeDays)) {
            throw ApiError::message('Emergency access wait time is out-of-bounds!');
        }
        $newType = EmergencyAccess::typeFromString($type) ?? throw ApiError::message('Invalid emergency access type.');

        $grantor = $headers->user;
        // Not oneself.
        if ($email === $grantor->email) {
            throw ApiError::message('You can not set yourself as an emergency contact.');
        }

        $grantee = User::findByMail($email);
        if ($grantee === null) {
            if (! $this->config->bool('invitations_allowed')) {
                throw ApiError::message('Grantee user does not exist: '.EscapeDebug::of($email));
            }
            if (! $this->config->isEmailDomainAllowed($email)) {
                throw ApiError::message('Email domain not eligible for invitations');
            }
            if (! $this->config->mailEnabled()) {
                Invitation::new($email)->save();
            }
            $grantee = User::new($email);
            $grantee->save();
            $newUser = true;
        } else {
            $newUser = $grantee->password_hash === '';
        }

        if (EmergencyAccess::findByGrantorUuidAndGranteeUuidOrEmail($grantor->uuid, $grantee->uuid, $grantee->email) !== null) {
            throw ApiError::message("Grantee user already invited: {$grantee->email}");
        }

        $access = EmergencyAccess::new($grantor->uuid, $grantee->email, EmergencyAccess::STATUS_INVITED, $newType, $waitTimeDays);
        $access->save();

        if ($this->config->mailEnabled()) {
            Mail::sendEmergencyAccessInvite($grantee->email, $grantee->uuid, $access->uuid, $grantor->name, $grantor->email);
        } elseif (! $newUser) {
            $access->acceptInvite($grantee->uuid, $email);
        }

        return new EmptyResponse;
    }

    /**
     * POST /api/emergency-access/{emer_id}/reinvite
     */
    public function resendInvite(Headers $headers, Request $request, string $emerId): EmptyResponse
    {
        $emerId = Uuid::parameter($request, $emerId);
        $this->checkEmergencyAccessEnabled();

        $access = EmergencyAccess::findByUuidAndGrantorUuid($emerId, $headers->user->uuid) ?? throw ApiError::message('Emergency access not valid.');
        if ($access->status !== EmergencyAccess::STATUS_INVITED) {
            throw ApiError::message('The grantee user is already accepted or confirmed for emergency access');
        }
        $email = $access->email ?? throw ApiError::message('Email not valid.');
        $grantee = User::findByMail($email) ?? throw ApiError::message('Grantee user not found.');
        $grantor = $headers->user;

        if ($this->config->mailEnabled()) {
            // Upstream puts the grantor's id into the token here.
            Mail::sendEmergencyAccessInvite($email, $grantor->uuid, $access->uuid, $grantor->name, $grantor->email);
        } elseif ($grantee->password_hash !== '') {
            $access->acceptInvite($grantee->uuid, $email);
        } elseif ($this->config->bool('invitations_allowed') && Invitation::findByMail($email) === null) {
            Invitation::new($email)->save();
        }

        return new EmptyResponse;
    }

    /**
     * POST /api/emergency-access/{emer_id}/accept: with the mailed token,
     * by the user it was sent to.
     */
    public function acceptInvite(Headers $headers, Request $request, string $emerId): EmptyResponse
    {
        $emerId = Uuid::parameter($request, $emerId);
        $token = Body::json($request)->string('token');
        $this->checkEmergencyAccessEnabled();

        $claims = $this->jwt->decode($token, JwtIssuer::EmergencyAccessInvite, fields: Jwt::EMERGENCY_ACCESS_INVITE_CLAIMS);
        // The invited user may have signed up with another email; the invitation stays as it is.
        if ($claims['email'] !== $headers->user->email) {
            throw ApiError::message('Claim email does not match current users email');
        }

        $grantee = User::findByMail((string) $claims['email']) ?? throw ApiError::message('Invited user not found');
        Invitation::take((string) $claims['email']);

        // Until it is accepted, the access knows the grantee only by email.
        $access = EmergencyAccess::findByUuidAndGranteeEmail($emerId, $headers->user->email) ?? throw ApiError::message('Emergency access not valid.');
        $grantor = User::findByUuid($access->grantor_uuid) ?? throw ApiError::message('Grantor user not found.');

        if ($emerId !== $claims['emer_id'] || $grantor->name !== $claims['grantor_name'] || $grantor->email !== $claims['grantor_email']) {
            throw ApiError::message('Emergency access invitation error.');
        }

        $access->acceptInvite($grantee->uuid, $grantee->email);
        if ($this->config->mailEnabled()) {
            Mail::sendEmergencyAccessInviteAccepted($grantor->email, $grantee->email);
        }

        return new EmptyResponse;
    }

    /**
     * POST /api/emergency-access/{emer_id}/confirm: the grantor hands over
     * the key, encrypted for the grantee.
     */
    public function confirmEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        $emerId = Uuid::parameter($request, $emerId);
        $key = Body::json($request)->string('key');
        $this->checkEmergencyAccessEnabled();

        $confirming = $headers->user;
        $access = EmergencyAccess::findByUuidAndGrantorUuid($emerId, $confirming->uuid) ?? throw ApiError::message('Emergency access not valid.');
        if ($access->status !== EmergencyAccess::STATUS_ACCEPTED || $access->grantor_uuid !== $confirming->uuid) {
            throw ApiError::message('Emergency access not valid.');
        }
        $grantor = User::findByUuid($confirming->uuid) ?? throw ApiError::message('Grantor user not found.');
        $grantee = $this->grantee($access);

        $access->status = EmergencyAccess::STATUS_CONFIRMED;
        $access->key_encrypted = $key;
        $access->email = null;
        $access->save();

        if ($this->config->mailEnabled()) {
            Mail::sendEmergencyAccessInviteConfirmed($grantee->email, $grantor->name);
        }

        return Json::response($access->toApi());
    }

    /**
     * POST /api/emergency-access/{emer_id}/initiate: the grantee asks for access.
     */
    public function initiateEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        $emerId = Uuid::parameter($request, $emerId);
        $this->checkEmergencyAccessEnabled();

        $initiating = $headers->user;
        $access = EmergencyAccess::findByUuidAndGranteeUuid($emerId, $initiating->uuid)
            ?? throw ApiError::message('Emergency access not valid.', logMessage: "Emergency access '{$emerId}', not found for user '{$initiating->email}'.");
        if ($access->status !== EmergencyAccess::STATUS_CONFIRMED) {
            throw ApiError::message('Emergency access not valid.', logMessage: "Emergency access '{$emerId}', not confirmed for user '{$initiating->email}'.");
        }
        if (! EmergencyAccess::isValidWaitTime($access->wait_time_days)) {
            throw ApiError::message('Emergency access not valid.', logMessage: "Emergency access '{$emerId}' timeout range '{$access->wait_time_days}' is invalid");
        }
        $grantor = User::findByUuid($access->grantor_uuid) ?? throw ApiError::message('Grantor user not found.');

        $now = Dates::now();
        $access->status = EmergencyAccess::STATUS_RECOVERY_INITIATED;
        $access->updated_at = $now;
        $access->recovery_initiated_at = $now;
        $access->last_notification_at = $now;
        $access->save();

        if ($this->config->mailEnabled()) {
            Mail::sendEmergencyAccessRecoveryInitiated($grantor->email, $initiating->name, $access->typeAsString(), $access->wait_time_days);
        }

        return Json::response($access->toApi());
    }

    /**
     * POST /api/emergency-access/{emer_id}/approve: the grantor grants the request early.
     */
    public function approveEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        $emerId = Uuid::parameter($request, $emerId);
        $this->checkEmergencyAccessEnabled();

        $access = EmergencyAccess::findByUuidAndGrantorUuid($emerId, $headers->user->uuid)
            ?? throw ApiError::message('Emergency access not valid.', logMessage: "Emergency access '{$emerId}', not found for user '{$headers->user->email}'.");
        if ($access->status !== EmergencyAccess::STATUS_RECOVERY_INITIATED) {
            throw ApiError::message('Emergency access not valid.', logMessage: "Emergency access '{$emerId}', not initiated for user '{$headers->user->email}'.");
        }
        $grantor = User::findByUuid($headers->user->uuid) ?? throw ApiError::message('Grantor user not found.');
        $grantee = $this->grantee($access);

        $access->status = EmergencyAccess::STATUS_RECOVERY_APPROVED;
        $access->save();

        if ($this->config->mailEnabled()) {
            Mail::sendEmergencyAccessRecoveryApproved($grantee->email, $grantor->name);
        }

        return Json::response($access->toApi());
    }

    /**
     * POST /api/emergency-access/{emer_id}/reject: the grantor refuses a
     * request, even an approved one.
     */
    public function rejectEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        $emerId = Uuid::parameter($request, $emerId);
        $this->checkEmergencyAccessEnabled();

        $access = EmergencyAccess::findByUuidAndGrantorUuid($emerId, $headers->user->uuid) ?? throw ApiError::message('Emergency access not valid.');
        if ($access->status !== EmergencyAccess::STATUS_RECOVERY_INITIATED && $access->status !== EmergencyAccess::STATUS_RECOVERY_APPROVED) {
            throw ApiError::message('Emergency access not valid.');
        }
        $grantee = $this->grantee($access);

        $access->status = EmergencyAccess::STATUS_CONFIRMED;
        $access->save();

        if ($this->config->mailEnabled()) {
            Mail::sendEmergencyAccessRecoveryRejected($grantee->email, $headers->user->name);
        }

        return Json::response($access->toApi());
    }

    /**
     * POST /api/emergency-access/{emer_id}/view: the grantor's own ciphers,
     * with the key to read them.
     */
    public function viewEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        $emerId = Uuid::parameter($request, $emerId);
        $this->checkEmergencyAccessEnabled();

        $access = EmergencyAccess::findByUuidAndGranteeUuid($emerId, $headers->user->uuid) ?? throw ApiError::message('Emergency access not valid.');
        if (! self::isValidRequest($access, $headers->user->uuid, EmergencyAccess::TYPE_VIEW)) {
            throw ApiError::message('Emergency access not valid.');
        }

        $sync = CiphersController::syncData($access->grantor_uuid);
        $ciphers = [];
        foreach (Cipher::findOwnedByUser($access->grantor_uuid) as $cipher) {
            $ciphers[] = $cipher->toApi($headers->host, $access->grantor_uuid, $sync);
        }

        return Json::response(Json::sorted([
            'ciphers' => $ciphers,
            'keyEncrypted' => $access->key_encrypted,
            'object' => 'emergencyAccessView',
        ]));
    }

    /**
     * POST /api/emergency-access/{emer_id}/takeover: what the grantee needs
     * to set a new master password for the grantor.
     */
    public function takeoverEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        $emerId = Uuid::parameter($request, $emerId);
        $this->checkEmergencyAccessEnabled();

        [$access, $grantor] = $this->takeover($headers, $emerId);

        return Json::response(Json::sorted([
            'kdf' => $grantor->client_kdf_type,
            'kdfIterations' => $grantor->client_kdf_iter,
            'kdfMemory' => $grantor->client_kdf_memory,
            'kdfParallelism' => $grantor->client_kdf_parallelism,
            'keyEncrypted' => $access->key_encrypted,
            'salt' => $grantor->masterPasswordSalt(),
            'object' => 'emergencyAccessTakeover',
        ]));
    }

    /**
     * POST /api/emergency-access/{emer_id}/password: the grantee sets the
     * grantor's new master password. Two-factor login is removed, and so are
     * the grantor's memberships, except where the grantor is an owner.
     */
    public function passwordEmergencyAccess(Headers $headers, Request $request, string $emerId): EmptyResponse
    {
        $emerId = Uuid::parameter($request, $emerId);
        $body = Body::json($request);
        $newMasterPasswordHash = $body->optionalString('newMasterPasswordHash');
        $key = $body->optionalString('key');
        $authentication = $body->optionalObject('authenticationData');
        $authentication = $authentication === null ? null : AuthenticationData::fromFields($authentication);
        $unlock = $body->optionalObject('unlockData');
        $unlock = $unlock === null ? null : UnlockData::fromFields($unlock);
        $this->checkEmergencyAccessEnabled();

        [, $grantor] = $this->takeover($headers, $emerId);

        if ($authentication !== null && $unlock !== null) {
            $authentication->check($grantor, $unlock);
            if (! $authentication->kdf->matchesUser($grantor)) {
                throw ApiError::message('KDF settings do not match the grantor account');
            }
            [$newMasterPasswordHash, $key] = [$authentication->masterPasswordAuthenticationHash, $unlock->masterKeyWrappedUserKey];
        } elseif ($newMasterPasswordHash === null || $key === null) {
            throw ApiError::message('Invalid request!');
        }

        $grantor->setPassword($newMasterPasswordHash, $key, true, null);
        $grantor->save();

        // Two-factor login would keep the grantee out.
        TwoFactor::deleteAllByUser($grantor->uuid);

        foreach (Membership::findAnyStateByUser($grantor->uuid) as $member) {
            if ($member->atype !== Membership::TYPE_OWNER) {
                $member->deleteMembership();
            }
        }

        return new EmptyResponse;
    }

    /**
     * GET /api/emergency-access/{emer_id}/policies: the policies of the
     * grantor's organizations, for the new master password. Upstream does not
     * check here whether emergency access is enabled.
     */
    public function policiesEmergencyAccess(Headers $headers, Request $request, string $emerId): Response
    {
        $emerId = Uuid::parameter($request, $emerId);
        [, $grantor] = $this->takeover($headers, $emerId);

        return Json::response(Json::sorted([
            'data' => OrgPolicy::findConfirmedByUser($grantor->uuid)->map(static fn (OrgPolicy $policy): array => $policy->toApi())->all(),
            'object' => 'list',
            'continuationToken' => null,
        ]));
    }

    /**
     * The accepted grantee, for the grantor's answers.
     *
     * @throws ApiError
     */
    private function grantee(EmergencyAccess $access): User
    {
        if ($access->grantee_uuid === null) {
            throw ApiError::message('Grantee user not found.');
        }

        return User::findByUuid($access->grantee_uuid) ?? throw ApiError::message('Grantee user not found.');
    }

    /**
     * An approved takeover by the requesting grantee, and its grantor.
     *
     * @return array{EmergencyAccess, User}
     *
     * @throws ApiError
     */
    private function takeover(Headers $headers, string $emerId): array
    {
        $access = EmergencyAccess::findByUuidAndGranteeUuid($emerId, $headers->user->uuid) ?? throw ApiError::message('Emergency access not valid.');
        if (! self::isValidRequest($access, $headers->user->uuid, EmergencyAccess::TYPE_TAKEOVER)) {
            throw ApiError::message('Emergency access not valid.');
        }

        return [$access, User::findByUuid($access->grantor_uuid) ?? throw ApiError::message('Grantor user not found.')];
    }

    /**
     * An approved request of the given type, by its grantee.
     */
    private static function isValidRequest(EmergencyAccess $access, string $requestingUserUuid, int $requestedType): bool
    {
        return $access->grantee_uuid !== null
            && $access->grantee_uuid === $requestingUserUuid
            && $access->status === EmergencyAccess::STATUS_RECOVERY_APPROVED
            && $access->atype === $requestedType;
    }

    /**
     * @throws ApiError
     */
    private function checkEmergencyAccessEnabled(): void
    {
        if (! $this->config->bool('emergency_access_allowed')) {
            throw ApiError::message('Emergency access is not enabled.');
        }
    }

    /**
     * The `type` of the update and invite data, as NumberOrString::into_string.
     *
     * @throws HttpStatus
     */
    private static function rawType(Fields $body): string
    {
        return (string) (NumberOrString::optional($body, 'type') ?? throw new HttpStatus(422, 'Missing field `type`.'));
    }
}
