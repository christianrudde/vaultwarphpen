<?php

namespace App\Http\Controllers;

use App\Auth\PublicToken;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\Fields;
use App\Http\EmptyResponse;
use App\Mail\Mail;
use App\Models\Group;
use App\Models\GroupUser;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrgPolicy;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * src/api/core/public.rs: the public API, so far the import of the
 * directory connector, which logs in with the organization's API key.
 */
final readonly class PublicController
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * POST /api/public/organization/import: members and groups of a
     * directory. Owners are never revoked, restored or removed.
     */
    public function ldapImport(PublicToken $token, Request $request): EmptyResponse
    {
        $body = Body::json($request);
        $groups = array_map(static fn (Fields $group): array => [
            'name' => $group->string('name'),
            'externalId' => $group->string('externalId'),
            'memberExternalIds' => $group->stringList('memberExternalIds'),
        ], $body->objectList('groups'));
        $members = array_map(static fn (Fields $member): array => [
            'email' => $member->string('email'),
            'externalId' => $member->string('externalId'),
            'deleted' => $member->bool('deleted'),
        ], $body->objectList('members'));
        $overwriteExisting = $body->bool('overwriteExisting');
        $orgId = $token->orgId;

        foreach ($members as $data) {
            $member = Membership::findByEmailAndOrg($data['email'], $orgId);
            if ($data['deleted']) {
                // Marked for deletion: revoked, if a member.
                if ($member !== null) {
                    $revoked = $member->atype !== Membership::TYPE_OWNER && $member->revoke();
                    $modified = $member->setExternalId($data['externalId']);
                    if ($revoked || $modified) {
                        $member->save();
                    }
                }
            } elseif ($member !== null) {
                $restored = $member->atype !== Membership::TYPE_OWNER && $member->restore();
                $modified = $member->setExternalId($data['externalId']);
                // The policies apply as on every other restore; the external id is updated anyway.
                if ($restored) {
                    try {
                        OrgPolicy::checkUserAllowed($member, 'restore');
                    } catch (ApiError $error) {
                        logger()->warning("Not restoring {$data['email']}: {$error->getMessage()}");
                        $member->revoke();
                        $restored = false;
                    }
                }
                if ($restored || $modified) {
                    $member->save();
                }
            } else {
                $this->addMember($orgId, $data['email'], $data['externalId']);
            }
        }

        if (Group::enabled()) {
            foreach ($groups as $data) {
                $group = Group::findByExternalIdAndOrg($data['externalId'], $orgId);
                if ($group === null) {
                    $group = Group::new($orgId, $data['name'], false, $data['externalId']);
                    $group->save();
                } elseif ($group->name !== $data['name']) {
                    $group->name = $data['name'];
                    $group->save();
                }

                GroupUser::deleteAllByGroup($group->uuid, $orgId);
                foreach ($data['memberExternalIds'] as $externalId) {
                    $member = Membership::findByExternalIdAndOrg($externalId, $orgId);
                    if ($member !== null) {
                        (new GroupUser($group->uuid, $member->uuid))->save();
                    }
                }
            }
        } else {
            logger()->warning('Group support is disabled, groups will not be imported!');
        }

        // Members the directory does not list are removed, unless owners.
        if ($overwriteExisting) {
            $listed = array_column($members, 'externalId');
            foreach (Membership::findByOrg($orgId) as $member) {
                if ($member->external_id !== null && ! in_array($member->external_id, $listed, true) && $member->atype !== Membership::TYPE_OWNER) {
                    $member->deleteMembership();
                }
            }
        }

        return new EmptyResponse;
    }

    /**
     * A user of the directory joins: invited by mail, or, without mail,
     * accepted if they have an account and invited to register if not.
     *
     * @throws ApiError
     */
    private function addMember(string $orgId, string $email, string $externalId): void
    {
        $user = User::findByMail($email);
        $created = $user === null;
        if ($user === null) {
            $user = User::new($email);
            $user->save();
            if (! $this->config->mailEnabled()) {
                Invitation::new($user->email)->save();
            }
        }

        $org = Organization::findByUuid($orgId) ?? throw ApiError::message('Error looking up organization');
        $member = Membership::new($user->uuid, $orgId, $org->billing_email);
        $member->setExternalId($externalId);
        $member->access_all = false;
        $member->atype = Membership::TYPE_USER;
        $member->status = $this->config->mailEnabled() || $user->password_hash === '' ? Membership::STATUS_INVITED : Membership::STATUS_ACCEPTED;
        $member->save();

        if (! $this->config->mailEnabled()) {
            return;
        }
        try {
            Mail::sendInvite($user, $orgId, $member->uuid, $org->name, $org->billing_email);
        } catch (ApiError $error) {
            // Undo what the invitation added.
            if ($created) {
                $user->deleteUser();
            } else {
                $member->deleteMembership();
            }

            throw ApiError::message("Error sending invite: {$error->debugText()} ");
        }
    }
}
