<?php

namespace App\Models;

use App\Config\Config;
use App\Http\ApiError;
use App\Http\Json;
use App\Support\EmailAddress;
use App\Support\EscapeDebug;
use App\Support\Random;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * An organization (src/db/models/organization.rs).
 *
 * @property string $uuid
 * @property string $name
 * @property string $billing_email
 * @property ?string $private_key
 * @property ?string $public_key
 */
final class Organization extends Model
{
    protected $table = 'organizations';

    protected $primaryKey = 'uuid';

    public static function new(string $name, string $billingEmail, ?string $privateKey, ?string $publicKey): self
    {
        return new self([
            'uuid' => Random::uuid(),
            'name' => $name,
            'billing_email' => mb_strtolower($billingEmail),
            'private_key' => $privateKey,
            'public_key' => $publicKey,
        ]);
    }

    /**
     * Organization::to_json, the OrganizationResponseModel.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        $config = app(Config::class);

        return Json::sorted([
            'id' => $this->uuid,
            'name' => $this->name,
            'seats' => null,
            'maxCollections' => null,
            // Not checked server-side.
            'maxStorageGb' => 32767,
            'use2fa' => true,
            'useCustomPermissions' => true,
            'useDirectory' => false,
            'useEvents' => $config->bool('org_events_enabled'),
            'useGroups' => $config->bool('org_groups_enabled'),
            'useTotp' => true,
            'usePolicies' => true,
            'useScim' => false,
            'useSso' => false,
            'useKeyConnector' => false,
            'usePasswordManager' => true,
            'useSecretsManager' => false,
            'selfHost' => true,
            'useApi' => true,
            'useDisableSMAdsForUsers' => true,
            'useInviteLinks' => false,
            'useMyItems' => false,
            'useOrganizationDomains' => false,
            'usePam' => false,
            'usePhishingBlocker' => false,
            'hasPublicAndPrivateKeys' => $this->private_key !== null && $this->public_key !== null,
            'useResetPassword' => $config->mailEnabled(),
            'allowAdminAccessToAllCollectionItems' => true,
            'limitCollectionCreation' => true,
            'limitCollectionDeletion' => true,
            'limitItemDeletion' => false,
            'businessName' => $this->name,
            'businessAddress1' => null,
            'businessAddress2' => null,
            'businessAddress3' => null,
            'businessCountry' => null,
            'businessTaxNumber' => null,
            'maxAutoscaleSeats' => null,
            'maxAutoscaleSmSeats' => null,
            'maxAutoscaleSmServiceAccounts' => null,
            'secretsManagerPlan' => null,
            'smSeats' => null,
            'smServiceAccounts' => null,
            'billingEmail' => $this->billing_email,
            // A custom plan.
            'planType' => 6,
            'usersGetPremium' => true,
            'object' => 'organization',
        ]);
    }

    /**
     * Saving tells every member's clients to sync.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        if (! EmailAddress::isValid($this->billing_email)) {
            throw ApiError::message('BillingEmail '.EscapeDebug::of($this->billing_email).' is not a valid email address');
        }

        foreach (Membership::findByOrg($this->uuid) as $member) {
            User::updateUuidRevision($member->user_uuid);
        }
        $this->upsertRow(['uuid'], 'Error saving organization');

        return true;
    }

    /**
     * With its ciphers, collections, members, policies, groups and API key.
     *
     * @throws ApiError
     */
    public function deleteOrganization(): void
    {
        foreach (Cipher::findByOrg($this->uuid) as $cipher) {
            $cipher->deleteCipher();
        }
        OrgCollection::deleteAllByOrganization($this->uuid);
        Membership::deleteAllByOrganization($this->uuid);

        OrgPolicy::deleteAllByOrganization($this->uuid);

        Group::deleteAllByOrganization($this->uuid);

        OrganizationApiKey::deleteAllByOrganization($this->uuid);

        try {
            self::query()->where('uuid', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error saving organization');
        }
    }

    public static function findByUuid(string $uuid): ?self
    {
        return self::query()->where('uuid', $uuid)->first();
    }
}
