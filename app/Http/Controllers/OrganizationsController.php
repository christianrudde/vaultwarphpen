<?php

namespace App\Http\Controllers;

use App\Auth\AdminHeaders;
use App\Auth\Headers;
use App\Auth\ManagerHeaders;
use App\Auth\ManagerHeadersLoose;
use App\Auth\OrgHeaders;
use App\Auth\OrgMemberHeaders;
use App\Auth\OwnerHeaders;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\Fields;
use App\Http\Data\NumberOrString;
use App\Http\Data\PasswordOrOtpData;
use App\Http\Data\Query;
use App\Http\EmptyResponse;
use App\Http\HttpStatus;
use App\Http\Json;
use App\Models\Cipher;
use App\Models\CollectionCipher;
use App\Models\CollectionGroup;
use App\Models\CollectionUser;
use App\Models\Group;
use App\Models\GroupUser;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrgCollection;
use App\Models\OrgPolicy;
use App\Models\User;
use App\Support\Uuid;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use stdClass;

/**
 * src/api/core/organizations.rs: organizations, their members, collections
 * and groups. Policies, account recovery, API keys, import and export are
 * not ported yet; nor are the invitation mails, so the accept route that
 * their links lead to is missing too.
 */
final readonly class OrganizationsController
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * POST /api/organizations: the creator becomes its confirmed owner, with
     * a first collection.
     */
    public function createOrganization(Headers $headers, Request $request): Response
    {
        $body = Body::json($request);
        $billingEmail = $body->string('billingEmail');
        $collectionName = $body->string('collectionName');
        $key = $body->string('key');
        $name = $body->string('name');
        $keys = $body->optionalObject('keys');
        [$privateKey, $publicKey] = $keys === null ? [null, null] : [$keys->string('encryptedPrivateKey'), $keys->string('publicKey')];
        // The plan is ignored, always the same.
        NumberOrString::optional($body, 'planType') ?? throw new HttpStatus(422, 'Missing field `planType`.');

        if (! $this->config->isOrgCreationAllowed($headers->user->email)) {
            throw ApiError::message('User not allowed to create organizations');
        }
        if (OrgPolicy::isApplicableToUser($headers->user->uuid, OrgPolicy::TYPE_SINGLE_ORG)) {
            throw ApiError::message('You may not create an organization. You belong to an organization which has a policy that prohibits you from being a member of any other organization.');
        }

        $org = Organization::new($name, $billingEmail, $privateKey, $publicKey);
        $member = Membership::new($headers->user->uuid, $org->uuid, null);
        $collection = OrgCollection::new($org->uuid, $collectionName, null);
        $member->akey = $key;
        $member->access_all = true;
        $member->atype = Membership::TYPE_OWNER;
        $member->status = Membership::STATUS_CONFIRMED;

        $org->save();
        $member->save();
        $collection->save();

        return Json::response($org->toApi());
    }

    /**
     * DELETE /api/organizations/{org_id}: with everything in it.
     */
    public function deleteOrganization(OwnerHeaders $headers, Request $request, string $orgId): EmptyResponse
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $data = PasswordOrOtpData::fromFields(Body::json($request));
        $data->validate($headers->user, true, $this->config);

        $org = Organization::findByUuid($orgId) ?? throw ApiError::message('Organization not found');
        $org->deleteOrganization();

        return new EmptyResponse;
    }

    /**
     * POST /api/organizations/{org_id}/leave
     */
    public function leaveOrganization(OrgMemberHeaders $headers, Request $request, string $orgId): EmptyResponse
    {
        $orgId = Uuid::parameter($request, $orgId);
        $membership = $headers->membership;
        if ($membership->status !== Membership::STATUS_CONFIRMED) {
            throw ApiError::message('You need to be a Member of the Organization to call this endpoint');
        }
        if ($membership->atype === Membership::TYPE_OWNER && Membership::countConfirmedByOrgAndType($orgId, Membership::TYPE_OWNER) <= 1) {
            throw ApiError::message("The last owner can't leave");
        }

        $membership->deleteMembership();

        return new EmptyResponse;
    }

    /**
     * GET /api/organizations/{org_id}
     */
    public function getOrganization(OwnerHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $org = Organization::findByUuid($orgId) ?? throw ApiError::message("Can't find organization details");

        return Json::response($org->toApi());
    }

    /**
     * PUT /api/organizations/{org_id}
     */
    public function putOrganization(OwnerHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $body = Body::json($request);
        $billingEmail = $body->string('billingEmail');
        $name = $body->string('name');

        $org = Organization::findByUuid($orgId) ?? throw ApiError::message('Organization not found');
        $org->name = $name;
        $org->billing_email = mb_strtolower($billingEmail);
        $org->save();

        return Json::response($org->toApi());
    }

    /**
     * POST /api/organizations/{org_id}/keys: the organization's key pair, once.
     */
    public function postOrgKeys(AdminHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $body = Body::json($request);
        $privateKey = $body->string('encryptedPrivateKey');
        $publicKey = $body->string('publicKey');

        $org = Organization::findByUuid($orgId) ?? throw ApiError::message("Can't find organization details");
        if ($org->private_key !== null && $org->public_key !== null) {
            throw ApiError::message('Organization Keys already exist');
        }
        $org->private_key = $privateKey;
        $org->public_key = $publicKey;
        $org->save();

        return Json::response(Json::sorted(['object' => 'organizationKeys', 'publicKey' => $org->public_key, 'privateKey' => $org->private_key]));
    }

    /**
     * GET /api/organizations/{org_id}/public-key, also the older /keys
     */
    public function getOrganizationPublicKey(OrgMemberHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $org = Organization::findByUuid($orgId) ?? throw ApiError::message('Organization not found');

        return Json::response(Json::sorted(['object' => 'organizationPublicKey', 'publicKey' => $org->public_key]));
    }

    /**
     * GET /api/collections: every collection the user reaches.
     */
    public function getUserCollections(Headers $headers): Response
    {
        $collections = OrgCollection::findByUserUuid($headers->user->uuid)->map(static fn (OrgCollection $collection): array => $collection->toApi())->all();

        return Json::response(Json::sorted(['data' => array_values($collections), 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * GET /api/organizations/{org_id}/collections
     */
    public function getOrgCollections(ManagerHeadersLoose $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        if (! $headers->membership->hasFullAccess()) {
            throw ApiError::message('Resource not found.', 404, 'User does not have full access');
        }

        $collections = OrgCollection::findByOrganization($orgId)->map(static fn (OrgCollection $collection): array => $collection->toApi())->all();

        return Json::response(Json::sorted(['data' => array_values($collections), 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * GET /api/organizations/{org_id}/collections/details: the collections the
     * user is assigned to (all of them with full access), with who may use them.
     */
    public function getOrgCollectionsDetails(ManagerHeadersLoose $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $member = Membership::findByUserAndOrg($headers->user->uuid, $orgId) ?? throw ApiError::message('User is not part of organization');

        $accesses = CollectionUser::findByOrganizationSwapUserUuidWithMemberUuid($orgId);
        $types = self::membershipTypes($orgId);
        $groups = Group::enabled();
        $fullAccess = $member->hasFullAccess() || ($groups && GroupUser::hasFullAccessByMember($orgId, $member->uuid));

        // Owners, admins and managers with access to all are listed as well.
        $manageAll = array_values(Membership::findConfirmedAndManageAllByOrg($orgId)->map(static fn (Membership $manager): array => [
            'hidePasswords' => false, 'id' => $manager->uuid, 'manage' => true, 'readOnly' => false,
        ])->all());

        $data = [];
        foreach (OrgCollection::findByOrganization($orgId) as $collection) {
            $assigned = $fullAccess
                || CollectionUser::hasAccessToCollectionByUser($collection->uuid, $member->user_uuid)
                || ($groups && GroupUser::hasAccessToCollectionByMember($collection->uuid, $member->uuid));
            if (! $assigned) {
                continue;
            }

            $users = [];
            foreach ($accesses as $access) {
                if ($access->collectionUuid === $collection->uuid) {
                    $users[] = $access->toApiDetailsForMember($types[$access->userUuid] ?? Membership::TYPE_USER);
                }
            }

            $data[] = Json::sorted([
                ...$collection->toApiDetails($headers->user->uuid),
                'assigned' => $assigned,
                'users' => [...$users, ...$manageAll],
                'groups' => self::collectionGroups($collection->uuid),
                'object' => 'collectionAccessDetails',
                'unmanaged' => false,
            ]);
        }

        return Json::response(Json::sorted(['data' => $data, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * POST /api/organizations/{org_id}/collections
     */
    public function postOrganizationCollections(ManagerHeadersLoose $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        [$name, $groups, $users, $externalId] = self::fullCollectionData(Body::json($request));
        self::validateCollectionAccess($orgId, $groups, $users);

        if ($headers->membership->atype === Membership::TYPE_MANAGER && ! $headers->membership->access_all) {
            throw ApiError::message("You don't have permission to create collections");
        }

        $collection = OrgCollection::new($orgId, $name, $externalId);
        $collection->save();
        self::saveCollectionAccess($orgId, $collection->uuid, $groups, $users);

        return Json::response($collection->toApiDetails($headers->membership->user_uuid));
    }

    /**
     * POST /api/organizations/{org_id}/collections/bulk-access: replaces who
     * may use each of the collections.
     */
    public function postBulkAccessCollections(ManagerHeadersLoose $headers, Request $request, string $orgId): EmptyResponse
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $body = Body::json($request);
        $collectionIds = $body->stringList('collectionIds');
        $groups = array_map(self::collectionAccessData(...), $body->objectList('groups'));
        $users = array_map(self::collectionAccessData(...), $body->objectList('users'));

        Organization::findByUuid($orgId) ?? throw ApiError::message("Can't find organization details");
        self::validateGroups($orgId, $groups);

        foreach ($collectionIds as $collectionId) {
            $collection = OrgCollection::findByUuidAndOrg($collectionId, $orgId) ?? throw ApiError::message('Collection not found');
            if (! $collection->isManageableByUser($headers->membership->user_uuid)) {
                throw ApiError::message('Collection not found', logMessage: "The current user isn't a manager for this collection");
            }
            // Bumps the revision of everyone with access.
            $collection->save();

            self::replaceCollectionAccess($orgId, $collectionId, $groups, $users);
        }

        return new EmptyResponse;
    }

    /**
     * PUT /api/organizations/{org_id}/collections/{col_id}
     */
    public function putOrganizationCollectionUpdate(ManagerHeaders $headers, Request $request, string $orgId, string $colId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $colId = Uuid::parameter($request, $colId);
        [$name, $groups, $users, $externalId] = self::fullCollectionData(Body::json($request));
        self::validateCollectionAccess($orgId, $groups, $users);

        Organization::findByUuid($orgId) ?? throw ApiError::message("Can't find organization details");
        $collection = OrgCollection::findByUuidAndOrg($colId, $orgId) ?? throw ApiError::message('Collection not found');

        $collection->name = $name;
        $collection->external_id = $externalId === null || trim($externalId) === '' ? null : $externalId;
        $collection->save();

        self::replaceCollectionAccess($orgId, $colId, $groups, $users);

        return Json::response($collection->toApiDetails($headers->user->uuid));
    }

    /**
     * DELETE /api/organizations/{org_id}/collections/{col_id}
     */
    public function deleteOrganizationCollection(ManagerHeaders $headers, Request $request, string $orgId, string $colId): EmptyResponse
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $this->deleteCollection($orgId, Uuid::parameter($request, $colId));

        return new EmptyResponse;
    }

    /**
     * DELETE /api/organizations/{org_id}/collections: several, each of which
     * the user must manage.
     */
    public function bulkDeleteOrganizationCollections(ManagerHeadersLoose $headers, Request $request, string $orgId): EmptyResponse
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $collectionIds = Body::json($request)->stringList('ids');

        // ManagerHeaders::from_loose
        foreach ($collectionIds as $collectionId) {
            if (! Uuid::isValid($collectionId)) {
                throw ApiError::message('Collection Id is malformed!');
            }
            if (! OrgCollection::isCollManageableByUser($collectionId, $headers->membership->user_uuid)) {
                throw ApiError::message('Collection not found', logMessage: "The current user isn't a manager for this collection");
            }
        }

        foreach ($collectionIds as $collectionId) {
            $this->deleteCollection($orgId, $collectionId);
        }

        return new EmptyResponse;
    }

    /**
     * GET /api/organizations/{org_id}/collections/{col_id}/details
     */
    public function getOrgCollectionDetail(ManagerHeaders $headers, Request $request, string $orgId, string $colId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $colId = Uuid::parameter($request, $colId);

        $collection = OrgCollection::findByUuidAndUser($colId, $headers->user->uuid) ?? throw ApiError::message('Collection not found');
        if ($collection->org_uuid !== $orgId) {
            throw ApiError::message('Collection is not owned by organization');
        }
        $member = Membership::findByUserAndOrg($headers->user->uuid, $orgId) ?? throw ApiError::message('User is not part of organization');

        $types = self::membershipTypes($orgId);
        $users = array_map(
            static fn (CollectionUser $access): array => $access->toApiDetailsForMember($types[$access->userUuid] ?? Membership::TYPE_USER),
            CollectionUser::findByOrgAndCollSwapUserUuidWithMemberUuid($orgId, $collection->uuid),
        );

        return Json::response(Json::sorted([
            ...$collection->toApiDetails($headers->user->uuid),
            'assigned' => OrgCollection::canAccessCollection($member, $collection->uuid),
            'users' => $users,
            'groups' => self::collectionGroups($collection->uuid),
            'object' => 'collectionAccessDetails',
        ]));
    }

    /**
     * GET /api/organizations/{org_id}/collections/{col_id}/users
     */
    public function getCollectionUsers(ManagerHeaders $headers, Request $request, string $orgId, string $colId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $colId = Uuid::parameter($request, $colId);
        $collection = OrgCollection::findByUuidAndOrg($colId, $orgId) ?? throw ApiError::message('Collection not found in Organization');

        $members = [];
        foreach (CollectionUser::findByCollection($collection->uuid) as $access) {
            $member = Membership::findByUserAndOrg($access->userUuid, $orgId) ?? throw new \LogicException("No membership for {$access->userUuid}");
            $members[] = $member->toApiUserAccessRestrictions($access);
        }

        return Json::response($members);
    }

    /**
     * GET /api/organizations/{org_id}/users
     */
    public function getMembers(ManagerHeadersLoose $headers, Request $request, string $orgId): Response
    {
        $includeCollections = self::optionalQueryBool($request, 'includeCollections');
        $includeGroups = self::optionalQueryBool($request, 'includeGroups');
        $orgId = $this->orgId($request, $orgId, $headers);
        if (! $headers->membership->hasFullAccess()) {
            throw ApiError::message('Resource not found.', 404, 'User does not have full access');
        }

        $members = Membership::findByOrg($orgId)->map(static fn (Membership $member): array => $member->toApiDetailsForAdmin($includeCollections ?? false, $includeGroups ?? false))->all();

        return Json::response(Json::sorted(['data' => array_values($members), 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * GET /api/organizations/{org_id}/users/mini-details
     */
    public function getOrgUserMiniDetails(ManagerHeadersLoose $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $members = Membership::findByOrg($orgId)->map(static fn (Membership $member): array => $member->toApiMiniDetails())->all();

        return Json::response(Json::sorted(['data' => array_values($members), 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * GET /api/organizations/{org_id}/users/{member_id}
     */
    public function getUser(AdminHeaders $headers, Request $request, string $orgId, string $memberId): Response
    {
        $includeCollections = self::optionalQueryBool($request, 'includeCollections');
        // With groups the collections must come too, or saving would lose them.
        $includeGroups = self::optionalQueryBool($request, 'includeGroups') ?? false;
        $orgId = $this->orgId($request, $orgId, $headers);
        $memberId = Uuid::parameter($request, $memberId);

        $member = Membership::findByUuidAndOrg($memberId, $orgId) ?? throw ApiError::message("The specified user isn't a member of the organization");

        return Json::response($member->toApiDetailsForAdmin($includeCollections ?? $includeGroups, $includeGroups));
    }

    /**
     * POST /api/organizations/{org_id}/users/invite: without mail, existing
     * users join as accepted, new ones get an invitation to register.
     */
    public function sendInvite(AdminHeaders $headers, Request $request, string $orgId): EmptyResponse
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $body = Body::json($request);
        $emails = $body->stringList('emails');
        $groups = $body->stringList('groups');
        $rawType = self::rawType($body);
        $collectionsField = $body->value('collections');
        $collections = $collectionsField === null ? [] : array_map(self::collectionAccessData(...), $body->objectList('collections'));
        $permissions = self::permissions($body);

        // InviteData::validate
        $orgCollections = OrgCollection::findByOrganization($orgId)->pluck('uuid')->all();
        foreach ($collections as $collection) {
            if (! in_array($collection['id'], $orgCollections, true)) {
                throw ApiError::message('Invalid collection', logMessage: "Collection {$collection['id']} does not belong to organization {$orgId}!");
            }
        }
        $orgGroups = self::groupIds($orgId);
        foreach ($groups as $group) {
            if (! in_array($group, $orgGroups, true)) {
                throw ApiError::message('Invalid group', logMessage: "Group {$group} does not belong to organization {$orgId}!");
            }
        }

        $newType = Membership::typeFromString($rawType) ?? throw ApiError::message('Invalid type');
        if ($newType !== Membership::TYPE_USER && $headers->membership->atype !== Membership::TYPE_OWNER) {
            throw ApiError::message('Only Owners can invite Managers, Admins or Owners');
        }
        $accessAll = self::accessAll($newType, $rawType, $permissions);

        foreach ($emails as $email) {
            $status = Membership::STATUS_INVITED;
            $user = User::findByMail($email);
            if ($user === null) {
                if (! $this->config->bool('invitations_allowed')) {
                    throw ApiError::message('User does not exist: '.addcslashes($email, "\0..\37\"\\"));
                }
                if (! $this->config->isEmailDomainAllowed($email)) {
                    throw ApiError::message('Email domain not eligible for invitations');
                }
                if (! $this->config->mailEnabled()) {
                    Invitation::new($email)->save();
                }
                $user = User::new($email);
                $user->save();
            } else {
                if (Membership::findByUserAndOrg($user->uuid, $orgId) !== null) {
                    throw ApiError::message('User already in organization: '.addcslashes($email, "\0..\37\"\\"));
                }
                if (! $this->config->mailEnabled()) {
                    if ($user->password_hash === '') {
                        Invitation::new($email)->save();
                    } else {
                        // Without mail, existing users accept right away.
                        $status = Membership::STATUS_ACCEPTED;
                    }
                }
            }

            $member = Membership::new($user->uuid, $orgId, $headers->user->email);
            $member->access_all = $accessAll;
            $member->atype = $newType;
            $member->status = $status;
            $member->save();

            if ($this->config->mailEnabled()) {
                throw ApiError::message('Sending mail is not implemented yet');
            }

            if (! $accessAll) {
                foreach ($collections as $collection) {
                    OrgCollection::findByUuidAndOrg($collection['id'], $orgId) ?? throw ApiError::message('Collection not found in Organization');
                    CollectionUser::save($user->uuid, $collection['id'], $collection['readOnly'], $collection['hidePasswords'], $collection['manage']);
                }
            }

            foreach ($groups as $group) {
                self::addToGroup($orgId, $group, $member->uuid);
            }
        }

        return new EmptyResponse;
    }

    /**
     * POST /api/organizations/{org_id}/users/{member_id}/reinvite
     */
    public function reinviteMember(AdminHeaders $headers, Request $request, string $orgId, string $memberId): EmptyResponse
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $this->reinvite($orgId, Uuid::parameter($request, $memberId));

        return new EmptyResponse;
    }

    /**
     * POST /api/organizations/{org_id}/users/reinvite
     */
    public function bulkReinviteMembers(AdminHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $ids = Body::json($request)->stringList('ids');

        return self::bulkResponse($ids, 'OrganizationBulkConfirmResponseModel', fn (string $id) => $this->reinvite($orgId, $id));
    }

    /**
     * POST /api/organizations/{org_id}/users/{member_id}/confirm
     */
    public function confirmInvite(AdminHeaders $headers, Request $request, string $orgId, string $memberId): EmptyResponse
    {
        $memberId = Uuid::parameter($request, $memberId);
        $orgId = Uuid::parameter($request, $orgId);
        $body = Body::json($request);
        $body->optionalString('id');
        $key = $body->optionalString('key') ?? '';

        $this->confirm($orgId, $memberId, $key, $headers);

        return new EmptyResponse;
    }

    /**
     * POST /api/organizations/{org_id}/users/confirm
     */
    public function bulkConfirmInvite(AdminHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $body = Body::json($request);
        $keys = $body->value('keys') === null ? null : $body->objectList('keys');
        $invites = array_map(static fn (Fields $invite): array => [$invite->optionalString('id'), $invite->optionalString('key')], $keys ?? []);

        $responses = [];
        foreach ($invites as [$memberId, $key]) {
            $memberId ?? throw ApiError::message('Member id is required');
            $responses[] = self::bulkItem($memberId, 'OrganizationBulkConfirmResponseModel', fn () => $this->confirm($orgId, $memberId, $key ?? '', $headers));
        }
        if ($keys === null) {
            logger()->error('No keys to confirm');
        }

        return Json::response(Json::sorted(['data' => $responses, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * PUT /api/organizations/{org_id}/users/{member_id}: type and collections.
     */
    public function editMember(AdminHeaders $headers, Request $request, string $orgId, string $memberId): EmptyResponse
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $memberId = Uuid::parameter($request, $memberId);
        $body = Body::json($request);
        $rawType = self::rawType($body);
        $collections = $body->value('collections') === null ? [] : array_map(self::collectionAccessData(...), $body->objectList('collections'));
        $groups = $body->value('groups') === null ? [] : $body->stringList('groups');
        $permissions = self::permissions($body);

        $newType = Membership::typeFromString($rawType) ?? throw ApiError::message('Invalid type');
        $accessAll = self::accessAll($newType, $rawType, $permissions);

        $member = Membership::findByUuidAndOrg($memberId, $orgId) ?? throw ApiError::message("The specified user isn't member of the organization");

        if ($newType !== $member->atype
            && (Membership::typeAtLeast($member->atype, Membership::TYPE_ADMIN) || Membership::typeAtLeast($newType, Membership::TYPE_ADMIN))
            && $headers->membership->atype !== Membership::TYPE_OWNER) {
            throw ApiError::message('Only Owners can grant and remove Admin or Owner privileges');
        }
        if ($member->atype === Membership::TYPE_OWNER && $headers->membership->atype !== Membership::TYPE_OWNER) {
            throw ApiError::message('Only Owners can edit Owner users');
        }
        if ($member->atype === Membership::TYPE_OWNER && $newType !== Membership::TYPE_OWNER && $member->status === Membership::STATUS_CONFIRMED
            && Membership::countConfirmedByOrgAndType($orgId, Membership::TYPE_OWNER) <= 1) {
            throw ApiError::message("Can't delete the last owner");
        }

        $member->access_all = $accessAll;
        $member->atype = $newType;
        // After the change: admins are exempt.
        OrgPolicy::checkUserAllowed($member, 'modify');

        foreach (CollectionUser::findByOrganizationAndUserUuid($orgId, $member->user_uuid) as $access) {
            $access->delete();
        }
        if (! $accessAll) {
            foreach ($collections as $collection) {
                OrgCollection::findByUuidAndOrg($collection['id'], $orgId) ?? throw ApiError::message('Collection not found in Organization');
                CollectionUser::save($member->user_uuid, $collection['id'], $collection['readOnly'], $collection['hidePasswords'], $collection['manage']);
            }
        }

        GroupUser::deleteAllByMember($member->uuid);
        foreach ($groups as $group) {
            self::addToGroup($orgId, $group, $member->uuid);
        }

        $member->save();

        return new EmptyResponse;
    }

    /**
     * DELETE /api/organizations/{org_id}/users/{member_id}
     */
    public function deleteMember(AdminHeaders $headers, Request $request, string $orgId, string $memberId): EmptyResponse
    {
        $memberId = Uuid::parameter($request, $memberId);
        $this->removeMember(Uuid::parameter($request, $orgId), $memberId, $headers);

        return new EmptyResponse;
    }

    /**
     * DELETE /api/organizations/{org_id}/users
     */
    public function bulkDeleteMember(AdminHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $ids = Body::json($request)->stringList('ids');

        return self::bulkResponse($ids, 'OrganizationBulkConfirmResponseModel', fn (string $id) => $this->removeMember($orgId, $id, $headers));
    }

    /**
     * POST /api/organizations/{org_id}/users/public-keys: of the members that exist.
     */
    public function bulkPublicKeys(AdminHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $ids = Body::json($request)->stringList('ids');

        $keys = [];
        foreach ($ids as $id) {
            $member = Membership::findByUuidAndOrg($id, $orgId);
            $user = $member === null ? null : User::findByUuid($member->user_uuid);
            if ($user !== null) {
                $keys[] = Json::sorted(['object' => 'organizationUserPublicKeyResponseModel', 'id' => $id, 'userId' => $user->uuid, 'key' => $user->public_key]);
            }
        }

        return Json::response(Json::sorted(['data' => $keys, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * PUT /api/organizations/{org_id}/users/{member_id}/revoke
     */
    public function revokeMember(AdminHeaders $headers, Request $request, string $orgId, string $memberId): EmptyResponse
    {
        $memberId = Uuid::parameter($request, $memberId);
        $this->revoke(Uuid::parameter($request, $orgId), $memberId, $headers);

        return new EmptyResponse;
    }

    /**
     * PUT /api/organizations/{org_id}/users/revoke
     */
    public function bulkRevokeMembers(AdminHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $body = Body::json($request);
        $ids = $body->value('ids') === null ? null : $body->stringList('ids');
        if ($ids === null) {
            logger()->error('No users to revoke');
        }

        return self::bulkResponse($ids ?? [], 'OrganizationUserBulkResponseModel', fn (string $id) => $this->revoke($orgId, $id, $headers));
    }

    /**
     * PUT /api/organizations/{org_id}/users/{member_id}/restore, also /restore/vnext,
     * which sends a default collection name that is not supported.
     */
    public function restoreMember(AdminHeaders $headers, Request $request, string $orgId, string $memberId): EmptyResponse
    {
        $memberId = Uuid::parameter($request, $memberId);
        $this->restore(Uuid::parameter($request, $orgId), $memberId, $headers);

        return new EmptyResponse;
    }

    /**
     * PUT /api/organizations/{org_id}/users/restore
     */
    public function bulkRestoreMembers(AdminHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $ids = Body::json($request)->stringList('ids');

        return self::bulkResponse($ids, 'OrganizationUserBulkResponseModel', fn (string $id) => $this->restore($orgId, $id, $headers));
    }

    /**
     * POST /api/ciphers/bulk-collections: adds the ciphers to the collections,
     * or removes them. Ciphers the user cannot write to are skipped.
     */
    public function postBulkCollections(Headers $headers, Request $request): EmptyResponse
    {
        $body = Body::json($request);
        $orgId = $body->string('organizationId');
        // HashSets: each id once.
        $cipherIds = array_values(array_unique($body->stringList('cipherIds')));
        $collectionIds = array_values(array_unique($body->stringList('collectionIds')));
        $remove = $body->bool('removeCollections');

        Membership::findConfirmedByUserAndOrg($headers->user->uuid, $orgId)
            ?? throw ApiError::message('You need to be a Member of the Organization to call this endpoint');

        $reachable = [];
        foreach (OrgCollection::findByOrganizationAndUserUuid($orgId, $headers->user->uuid) as $collection) {
            $reachable[$collection->uuid] = $collection;
        }
        foreach ($collectionIds as $collectionId) {
            if (! isset($reachable[$collectionId]) || ! $reachable[$collectionId]->isWritableByUser($headers->user->uuid)) {
                throw ApiError::message('Resource not found', 404, 'User does not have access to a collection');
            }
        }

        foreach ($cipherIds as $cipherId) {
            // Gone ciphers are skipped; they may just have been deleted.
            $cipher = Cipher::findByUuidAndOrg($cipherId, $orgId);
            if ($cipher === null || ! $cipher->isWriteAccessibleToUser($headers->user->uuid)) {
                continue;
            }
            foreach ($collectionIds as $collectionId) {
                if ($remove) {
                    CollectionCipher::delete($cipher->uuid, $collectionId);
                } else {
                    CollectionCipher::save($cipher->uuid, $collectionId);
                }
            }
        }

        return new EmptyResponse;
    }

    /**
     * GET /api/ciphers/organization-details?organizationId=: the organization's
     * ciphers as an admin sees them.
     */
    public function getOrgDetails(ManagerHeadersLoose $headers, Request $request): Response
    {
        $orgId = Query::first($request, 'organizationId') ?? throw new HttpStatus(404, 'No organizationId.');
        if ($orgId !== $headers->membership->org_uuid) {
            throw ApiError::message('Resource not found.', 404, "Organization id's do not match");
        }
        if (! $headers->membership->hasFullAccess()) {
            throw ApiError::message('Resource not found.', 404, 'User does not have full access');
        }

        $sync = CiphersController::syncData($headers->user->uuid, organizationView: true);
        $ciphers = Cipher::findByOrg($orgId)->map(static fn (Cipher $cipher): array => $cipher->toApi($headers->host, $headers->user->uuid, $sync, organizationView: true))->all();

        return Json::response(Json::sorted(['data' => array_values($ciphers), 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * GET /api/organizations/{org_id}/groups: for members with full access,
     * also through a group, or who manage a collection, to assign groups.
     */
    public function getGroups(ManagerHeadersLoose $headers, Request $request, string $orgId): Response
    {
        return $this->groupsData(false, $headers, $request, $orgId);
    }

    /**
     * GET /api/organizations/{org_id}/groups/details: with the groups'
     * collections, for members with full access only.
     */
    public function getGroupsDetails(ManagerHeadersLoose $headers, Request $request, string $orgId): Response
    {
        return $this->groupsData(true, $headers, $request, $orgId);
    }

    /**
     * POST /api/organizations/{org_id}/groups
     */
    public function postGroups(AdminHeaders $headers, Request $request, string $orgId): Response
    {
        $orgId = Uuid::parameter($request, $orgId);
        $data = self::groupRequest(Body::json($request));
        $this->checkGroups($orgId, $headers);
        self::validateGroupRequest($orgId, $data);

        $group = Group::new($orgId, $data['name'], $data['accessAll'], $data['externalId']);

        return Json::response(self::addUpdateGroup($group, $data, $orgId));
    }

    /**
     * PUT /api/organizations/{org_id}/groups/{group_id}: the external id stays,
     * only the directory import sets it.
     */
    public function putGroup(AdminHeaders $headers, Request $request, string $orgId, string $groupId): Response
    {
        $orgId = Uuid::parameter($request, $orgId);
        $groupId = Uuid::parameter($request, $groupId);
        $data = self::groupRequest(Body::json($request));
        $this->checkGroups($orgId, $headers);

        $group = self::findGroup($groupId, $orgId);
        self::validateGroupRequest($orgId, $data);
        $group->name = $data['name'];
        $group->access_all = $data['accessAll'];

        CollectionGroup::deleteAllByGroup($groupId, $orgId);
        GroupUser::deleteAllByGroup($groupId, $orgId);

        return Json::response(self::addUpdateGroup($group, $data, $orgId));
    }

    /**
     * GET /api/organizations/{org_id}/groups/{group_id}/details
     */
    public function getGroupDetails(AdminHeaders $headers, Request $request, string $orgId, string $groupId): Response
    {
        $orgId = Uuid::parameter($request, $orgId);
        $groupId = Uuid::parameter($request, $groupId);
        $this->checkGroups($orgId, $headers);

        return Json::response(self::findGroup($groupId, $orgId)->toApiDetails());
    }

    /**
     * DELETE /api/organizations/{org_id}/groups/{group_id}
     */
    public function deleteGroup(AdminHeaders $headers, Request $request, string $orgId, string $groupId): EmptyResponse
    {
        $orgId = Uuid::parameter($request, $orgId);
        $this->deleteGroupImpl($orgId, Uuid::parameter($request, $groupId), $headers);

        return new EmptyResponse;
    }

    /**
     * DELETE /api/organizations/{org_id}/groups: one after the other, until one fails.
     */
    public function bulkDeleteGroups(AdminHeaders $headers, Request $request, string $orgId): EmptyResponse
    {
        $orgId = Uuid::parameter($request, $orgId);
        $ids = Body::json($request)->stringList('ids');
        $this->checkGroups($orgId, $headers);

        foreach ($ids as $groupId) {
            $this->deleteGroupImpl($orgId, $groupId, $headers);
        }

        return new EmptyResponse;
    }

    /**
     * GET /api/organizations/{org_id}/groups/{group_id}
     */
    public function getGroup(AdminHeaders $headers, Request $request, string $orgId, string $groupId): Response
    {
        $orgId = Uuid::parameter($request, $orgId);
        $groupId = Uuid::parameter($request, $groupId);
        $this->checkGroups($orgId, $headers);

        return Json::response(self::findGroup($groupId, $orgId)->toApi());
    }

    /**
     * GET /api/organizations/{org_id}/groups/{group_id}/users: the ids of its memberships.
     */
    public function getGroupMembers(AdminHeaders $headers, Request $request, string $orgId, string $groupId): Response
    {
        $orgId = Uuid::parameter($request, $orgId);
        $groupId = Uuid::parameter($request, $groupId);
        $this->checkGroups($orgId, $headers);

        Group::findByUuidAndOrg($groupId, $orgId)
            ?? throw ApiError::message('Group could not be found!', logMessage: 'Group uuid is invalid or does not belong to the organization');

        return Json::response(array_map(static fn (GroupUser $groupUser): string => $groupUser->membershipUuid, GroupUser::findByGroup($groupId, $orgId)));
    }

    /**
     * get_groups_data
     *
     * @throws ApiError|HttpStatus
     */
    private function groupsData(bool $details, ManagerHeadersLoose $headers, Request $request, string $orgId): Response
    {
        $orgId = $this->orgId($request, $orgId, $headers);
        $member = $headers->membership;

        $fullAccess = $member->hasFullAccess() || (Group::enabled() && GroupUser::hasFullAccessByMember($orgId, $member->uuid));
        $allowed = $details ? $fullAccess : $fullAccess || OrgCollection::hasManageableCollectionByUser($orgId, $member->user_uuid);
        if (! $allowed) {
            throw ApiError::message('Resource not found.', 404, 'User does not have access');
        }

        // Clients ask regardless of whether groups are enabled.
        $groups = Group::enabled()
            ? array_values(Group::findByOrganization($orgId)->map(static fn (Group $group): array => $details ? $group->toApiDetails() : $group->toApi())->all())
            : [];

        return Json::response(Json::sorted(['data' => $groups, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * The checks every group route of admins starts with.
     *
     * @throws ApiError
     */
    private function checkGroups(string $orgId, AdminHeaders $headers): void
    {
        if ($orgId !== $headers->membership->org_uuid) {
            throw ApiError::message('Organization not found', logMessage: "Organization id's do not match");
        }
        if (! Group::enabled()) {
            throw ApiError::message('Group support is disabled');
        }
    }

    /**
     * @throws ApiError
     */
    private function deleteGroupImpl(string $orgId, string $groupId, AdminHeaders $headers): void
    {
        $this->checkGroups($orgId, $headers);
        self::findGroup($groupId, $orgId)->deleteGroup($orgId);
    }

    /**
     * @throws ApiError
     */
    private static function findGroup(string $groupId, string $orgId): Group
    {
        return Group::findByUuidAndOrg($groupId, $orgId)
            ?? throw ApiError::message('Group not found', logMessage: 'Group uuid is invalid or does not belong to the organization');
    }

    /**
     * GroupRequest
     *
     * @return array{name: string, accessAll: bool, externalId: ?string, collections: list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>, users: list<string>}
     *
     * @throws HttpStatus
     */
    private static function groupRequest(Fields $body): array
    {
        return [
            'name' => $body->string('name'),
            'accessAll' => $body->defaultBool('accessAll'),
            'externalId' => $body->optionalString('externalId'),
            'collections' => array_map(self::collectionAccessData(...), $body->objectList('collections')),
            'users' => $body->stringList('users'),
        ];
    }

    /**
     * GroupRequest::validate: the collections and members must be the organization's.
     *
     * @param  array{collections: list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>, users: list<string>}  $data
     *
     * @throws ApiError
     */
    private static function validateGroupRequest(string $orgId, array $data): void
    {
        $collections = OrgCollection::findByOrganization($orgId)->pluck('uuid')->all();
        foreach ($data['collections'] as $collection) {
            if (! in_array($collection['id'], $collections, true)) {
                throw ApiError::message('Invalid collection', logMessage: "Collection {$collection['id']} does not belong to organization {$orgId}!");
            }
        }

        $members = Membership::findByOrg($orgId)->pluck('uuid')->all();
        foreach ($data['users'] as $memberId) {
            if (! in_array($memberId, $members, true)) {
                throw ApiError::message('Invalid member', logMessage: "Member {$memberId} does not belong to organization {$orgId}!");
            }
        }
    }

    /**
     * add_update_group: saves the group with its collections and members.
     *
     * @param  array{collections: list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>, users: list<string>}  $data
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private static function addUpdateGroup(Group $group, array $data, string $orgId): array
    {
        $group->save();

        foreach ($data['collections'] as $collection) {
            (new CollectionGroup($collection['id'], $group->uuid, $collection['readOnly'], $collection['hidePasswords'], $collection['manage']))->save($orgId);
        }
        foreach ($data['users'] as $memberId) {
            (new GroupUser($group->uuid, $memberId))->save();
        }

        return $group->toApi();
    }

    /**
     * The org id of the path must be the guard's; it is, as the guard read it from there.
     *
     * @throws ApiError|HttpStatus
     */
    private function orgId(Request $request, string $orgId, OrgHeaders $headers): string
    {
        $orgId = Uuid::parameter($request, $orgId);
        if ($orgId !== $headers->membership->org_uuid) {
            throw ApiError::message('Organization not found', logMessage: "Organization id's do not match");
        }

        return $orgId;
    }

    /**
     * @throws ApiError
     */
    private function deleteCollection(string $orgId, string $collectionId): void
    {
        $collection = OrgCollection::findByUuidAndOrg($collectionId, $orgId)
            ?? throw ApiError::message('Collection not found', logMessage: 'Collection does not exist or does not belong to this organization');
        $collection->deleteCollection();
    }

    /**
     * organizations::reinvite_member_impl, without mail.
     *
     * @throws ApiError
     */
    private function reinvite(string $orgId, string $memberId): void
    {
        $member = Membership::findByUuidAndOrg($memberId, $orgId) ?? throw ApiError::message("The user hasn't been invited to the organization.");
        if ($member->status !== Membership::STATUS_INVITED) {
            throw ApiError::message('The user is already accepted or confirmed to the organization');
        }
        $user = User::findByUuid($member->user_uuid) ?? throw ApiError::message('User not found.');
        if (! $this->config->bool('invitations_allowed') && $user->password_hash === '') {
            throw ApiError::message('Invitations are not allowed.');
        }
        Organization::findByUuid($orgId) ?? throw ApiError::message('Error looking up organization.');

        if ($this->config->mailEnabled()) {
            throw ApiError::message('Sending mail is not implemented yet');
        }
        if ($user->password_hash === '') {
            Invitation::new($user->email)->save();
        } else {
            Invitation::take($user->email);
            $member->status = Membership::STATUS_ACCEPTED;
            $member->save();
        }
    }

    /**
     * organizations::confirm_invite_impl: the member gets the organization key.
     *
     * @throws ApiError
     */
    private function confirm(string $orgId, string $memberId, string $key, AdminHeaders $headers): void
    {
        if ($orgId !== $headers->membership->org_uuid) {
            throw ApiError::message('Organization not found', logMessage: "Organization id's do not match");
        }
        if ($key === '' || $memberId === '') {
            throw ApiError::message('Key or UserId is not set, unable to process request');
        }

        $member = Membership::findByUuidAndOrg($memberId, $orgId) ?? throw ApiError::message("The specified user isn't a member of the organization");
        if ($member->atype !== Membership::TYPE_USER && $headers->membership->atype !== Membership::TYPE_OWNER) {
            throw ApiError::message('Only Owners can confirm Managers, Admins or Owners');
        }
        if ($member->status !== Membership::STATUS_ACCEPTED) {
            throw ApiError::message('User in invalid state');
        }

        $member->status = Membership::STATUS_CONFIRMED;
        $member->akey = $key;
        OrgPolicy::checkUserAllowed($member, 'confirm');

        if ($this->config->mailEnabled()) {
            throw ApiError::message('Sending mail is not implemented yet');
        }

        $member->save();
    }

    /**
     * organizations::delete_member_impl
     *
     * @throws ApiError
     */
    private function removeMember(string $orgId, string $memberId, AdminHeaders $headers): void
    {
        if ($orgId !== $headers->membership->org_uuid) {
            throw ApiError::message('Organization not found', logMessage: "Organization id's do not match");
        }
        $member = Membership::findByUuidAndOrg($memberId, $orgId) ?? throw ApiError::message("User to delete isn't member of the organization");
        if ($member->atype !== Membership::TYPE_USER && $headers->membership->atype !== Membership::TYPE_OWNER) {
            throw ApiError::message('Only Owners can delete Admins or Owners');
        }
        if ($member->atype === Membership::TYPE_OWNER && $member->status === Membership::STATUS_CONFIRMED
            && Membership::countConfirmedByOrgAndType($orgId, Membership::TYPE_OWNER) <= 1) {
            throw ApiError::message("Can't delete the last owner");
        }

        $user = User::findByUuid($member->user_uuid);
        // Without mail the invitation goes with the last pending membership.
        if ($user !== null && ! $this->config->mailEnabled()
            && ! Membership::findInvitedByUser($user->uuid)->contains(static fn (Membership $other): bool => $other->uuid !== $member->uuid)) {
            Invitation::take($user->email);
        }

        $member->deleteMembership();
    }

    /**
     * organizations::revoke_member_impl
     *
     * @throws ApiError
     */
    private function revoke(string $orgId, string $memberId, AdminHeaders $headers): void
    {
        if ($orgId !== $headers->membership->org_uuid) {
            throw ApiError::message('Organization not found', logMessage: "Organization id's do not match");
        }
        $member = Membership::findByUuidAndOrg($memberId, $orgId) ?? throw ApiError::message('User not found in organization');
        if ($member->status <= Membership::STATUS_REVOKED) {
            throw ApiError::message('User is already revoked');
        }
        if ($member->user_uuid === $headers->user->uuid) {
            throw ApiError::message('You cannot revoke yourself');
        }
        if ($member->atype === Membership::TYPE_OWNER && $headers->membership->atype !== Membership::TYPE_OWNER) {
            throw ApiError::message('Only owners can revoke other owners');
        }
        if ($member->atype === Membership::TYPE_OWNER && Membership::countConfirmedByOrgAndType($orgId, Membership::TYPE_OWNER) <= 1) {
            throw ApiError::message('Organization must have at least one confirmed owner');
        }

        $member->revoke();
        $member->save();
    }

    /**
     * organizations::restore_member_impl. Invited members count as active.
     *
     * @throws ApiError
     */
    private function restore(string $orgId, string $memberId, AdminHeaders $headers): void
    {
        if ($orgId !== $headers->membership->org_uuid) {
            throw ApiError::message('Organization not found', logMessage: "Organization id's do not match");
        }
        $member = Membership::findByUuidAndOrg($memberId, $orgId) ?? throw ApiError::message('User not found in organization');
        if ($member->status >= Membership::STATUS_ACCEPTED) {
            throw ApiError::message('User is already active');
        }
        if ($member->user_uuid === $headers->user->uuid) {
            throw ApiError::message('You cannot restore yourself');
        }
        if ($member->atype === Membership::TYPE_OWNER && $headers->membership->atype !== Membership::TYPE_OWNER) {
            throw ApiError::message('Only owners can restore other owners');
        }

        $member->restore();
        // With the restored status.
        OrgPolicy::checkUserAllowed($member, 'restore');
        $member->save();
    }

    /**
     * FullCollectionData: name, group and member accesses, external id.
     *
     * @return array{string, list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>, list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>, ?string}
     *
     * @throws HttpStatus
     */
    private static function fullCollectionData(Fields $body): array
    {
        return [
            $body->string('name'),
            array_map(self::collectionAccessData(...), $body->objectList('groups')),
            array_map(self::collectionAccessData(...), $body->objectList('users')),
            $body->optionalString('externalId'),
        ];
    }

    /**
     * CollectionData, CollectionGroupData and CollectionMembershipData alike.
     *
     * @return array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}
     *
     * @throws HttpStatus
     */
    private static function collectionAccessData(Fields $access): array
    {
        return [
            'id' => $access->string('id'),
            'readOnly' => $access->bool('readOnly'),
            'hidePasswords' => $access->bool('hidePasswords'),
            'manage' => $access->bool('manage'),
        ];
    }

    /**
     * FullCollectionData::validate
     *
     * @param  list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>  $groups
     * @param  list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>  $users
     *
     * @throws ApiError
     */
    private static function validateCollectionAccess(string $orgId, array $groups, array $users): void
    {
        self::validateGroups($orgId, $groups);

        $members = Membership::findByOrg($orgId)->pluck('uuid')->all();
        foreach ($users as $user) {
            if (! in_array($user['id'], $members, true)) {
                throw ApiError::message('Invalid member', logMessage: "Member {$user['id']} does not belong to organization {$orgId}!");
            }
        }
    }

    /**
     * @param  list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>  $groups
     *
     * @throws ApiError
     */
    private static function validateGroups(string $orgId, array $groups): void
    {
        $orgGroups = self::groupIds($orgId);
        foreach ($groups as $group) {
            if (! in_array($group['id'], $orgGroups, true)) {
                throw ApiError::message('Invalid group', logMessage: "Group {$group['id']} does not belong to organization {$orgId}!");
            }
        }
    }

    /**
     * Replaces the group and member accesses of a collection.
     *
     * @param  list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>  $groups
     * @param  list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>  $users
     *
     * @throws ApiError
     */
    private static function replaceCollectionAccess(string $orgId, string $collectionId, array $groups, array $users): void
    {
        CollectionGroup::deleteAllByCollection($collectionId, $orgId);
        CollectionUser::deleteAllByCollection($collectionId);
        self::saveCollectionAccess($orgId, $collectionId, $groups, $users);
    }

    /**
     * Members with access to all need no entry.
     *
     * @param  list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>  $groups
     * @param  list<array{id: string, readOnly: bool, hidePasswords: bool, manage: bool}>  $users
     *
     * @throws ApiError
     */
    private static function saveCollectionAccess(string $orgId, string $collectionId, array $groups, array $users): void
    {
        foreach ($groups as $group) {
            (new CollectionGroup($collectionId, $group['id'], $group['readOnly'], $group['hidePasswords'], $group['manage']))->save($orgId);
        }

        foreach ($users as $user) {
            $member = Membership::findByUuidAndOrg($user['id'], $orgId) ?? throw ApiError::message('User is not part of organization');
            if (! $member->access_all) {
                CollectionUser::save($member->user_uuid, $collectionId, $user['readOnly'], $user['hidePasswords'], $user['manage']);
            }
        }
    }

    /**
     * @return array<string, int> Membership type by membership id
     */
    private static function membershipTypes(string $orgId): array
    {
        $types = [];
        foreach (Membership::findByOrg($orgId) as $member) {
            $types[$member->uuid] = $member->atype;
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private static function groupIds(string $orgId): array
    {
        return array_values(Group::findByOrganization($orgId)->map(static fn (Group $group): string => $group->uuid)->all());
    }

    /**
     * @throws ApiError
     */
    private static function addToGroup(string $orgId, string $groupId, string $memberId): void
    {
        Group::findByUuidAndOrg($groupId, $orgId) ?? throw ApiError::message('Group not found in Organization');
        (new GroupUser($groupId, $memberId))->save();
    }

    /**
     * The groups' accesses to a collection; none while groups are disabled,
     * as the clients ask regardless.
     *
     * @return list<array<string, mixed>>
     */
    private static function collectionGroups(string $collectionUuid): array
    {
        return Group::enabled()
            ? array_map(static fn (CollectionGroup $access): array => $access->toApiDetailsForGroup(), CollectionGroup::findByCollection($collectionUuid))
            : [];
    }

    /**
     * The `type` of InviteData and EditUserData, as NumberOrString::into_string.
     *
     * @throws HttpStatus
     */
    private static function rawType(Fields $body): string
    {
        $type = NumberOrString::optional($body, 'type') ?? throw new HttpStatus(422, 'Missing field `type`.');

        return (string) $type;
    }

    /**
     * The custom permissions, a map that defaults to empty.
     *
     * @return array<string, mixed>
     *
     * @throws HttpStatus
     */
    private static function permissions(Fields $body): array
    {
        $permissions = $body->value('permissions');
        if ($permissions === null) {
            return [];
        }
        if (! $permissions instanceof stdClass) {
            throw new HttpStatus(422, 'Field `permissions` must be a map.');
        }

        $map = [];
        foreach (get_object_vars($permissions) as $name => $value) {
            $map[(string) $name] = $value;
        }

        return $map;
    }

    /**
     * Admins and owners have access to all; so does a custom user whose three
     * collection permissions are all set.
     *
     * @param  array<string, mixed>  $permissions
     */
    private static function accessAll(int $type, string $rawType, array $permissions): bool
    {
        return Membership::typeAtLeast($type, Membership::TYPE_ADMIN)
            || ($rawType === '4'
                && ($permissions['editAnyCollection'] ?? null) === true
                && ($permissions['deleteAnyCollection'] ?? null) === true
                && ($permissions['createNewCollections'] ?? null) === true);
    }

    /**
     * An Option<bool> query field: none when missing, repeated or no bool.
     */
    private static function optionalQueryBool(Request $request, string $name): ?bool
    {
        $values = Query::values($request, $name);
        if (count($values) !== 1) {
            return null;
        }

        return match (strtolower($values[0])) {
            'off', 'false', 'no' => false,
            '', 'on', 'true', 'yes' => true,
            default => null,
        };
    }

    /**
     * A list of per-member results, each with the error it ran into.
     *
     * @param  list<string>  $ids
     * @param  callable(string): void  $action
     */
    private static function bulkResponse(array $ids, string $object, callable $action): Response
    {
        $responses = array_map(static fn (string $id): array => self::bulkItem($id, $object, static fn () => $action($id)), $ids);

        return Json::response(Json::sorted(['data' => $responses, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * @param  callable(): void  $action
     * @return array<string, string>
     */
    private static function bulkItem(string $id, string $object, callable $action): array
    {
        try {
            $action();
            $error = '';
        } catch (ApiError $exception) {
            $error = $exception->debugText();
        }

        return ['error' => $error, 'id' => $id, 'object' => $object];
    }
}
