<?php

namespace App\Models;

use App\Http\ApiError;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use App\Support\Random;
use DateTimeImmutable;
use Illuminate\Database\QueryException;

/**
 * An organization's API key (OrganizationApiKey in
 * src/db/models/organization.rs), with which the directory connector logs in.
 *
 * @property string $uuid
 * @property string $org_uuid
 * @property int $atype
 * @property string $api_key
 * @property DateTimeImmutable $revision_date
 */
final class OrganizationApiKey extends Model
{
    protected $table = 'organization_api_key';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'atype' => 'integer',
            'revision_date' => NaiveDateTime::class,
        ];
    }

    /**
     * Type 0 is the only one.
     */
    public static function new(string $orgUuid, string $apiKey): self
    {
        $key = new self([
            'uuid' => Random::uuid(),
            'org_uuid' => $orgUuid,
            'atype' => 0,
            'api_key' => $apiKey,
            'revision_date' => null,
        ]);
        $key->revision_date = Dates::now();

        return $key;
    }

    /**
     * crypto::generate_api_key
     */
    public static function generate(): string
    {
        return Random::alphanumeric(30);
    }

    public function checkValidApiKey(string $apiKey): bool
    {
        return hash_equals($this->api_key, $apiKey);
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        $this->upsertRow(['uuid', 'org_uuid'], 'Error saving organization');

        return true;
    }

    public static function findByOrgUuid(string $orgUuid): ?self
    {
        return self::query()->where('org_uuid', $orgUuid)->first();
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByOrganization(string $orgUuid): void
    {
        try {
            self::query()->where('org_uuid', $orgUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing organization api key from organization');
        }
    }
}
