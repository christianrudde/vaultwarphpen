<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;

/**
 * A two-factor method of a user (src/db/models/two_factor.rs); so far only
 * the lookup the login needs.
 *
 * @property string $uuid
 * @property string $user_uuid
 * @property int $atype
 * @property bool $enabled
 * @property string $data
 * @property int $last_used
 */
final class TwoFactor extends Model
{
    protected $table = 'twofactor';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'atype' => 'integer',
            'enabled' => 'boolean',
            'last_used' => 'integer',
        ];
    }

    /**
     * Every method except the internal ones (types 1000 and up).
     *
     * @return Collection<int, self>
     */
    public static function findByUser(string $userUuid): Collection
    {
        return self::query()->where('user_uuid', $userUuid)->where('atype', '<', 1000)->get();
    }
}
