<?php

namespace App\Auth;

use App\Config\Config;
use App\Http\Data\Query;
use App\Http\Unauthorized;
use App\Models\Membership;
use App\Models\OrgCollection;
use App\Support\Uuid;
use Illuminate\Http\Request;

/**
 * auth::ManagerHeaders: a confirmed manager, admin or owner who manages the
 * collection of the request, the fourth path segment below /api or the
 * collectionId query field.
 */
final readonly class ManagerHeaders extends OrgHeaders
{
    protected function check(Request $request): void
    {
        if (! $this->isConfirmed() || ! $this->membership->atLeast(Membership::TYPE_MANAGER)) {
            throw new Unauthorized('You need to be a Manager, Admin or Owner to call this endpoint');
        }

        $segment = self::pathSegment($request, app(Config::class), 3);
        $query = Query::first($request, 'collectionId');
        $collectionId = match (true) {
            $segment !== null && Uuid::isValid($segment) => $segment,
            $query !== null && Uuid::isValid($query) => $query,
            default => throw new Unauthorized('Error getting the collection id'),
        };

        if (! OrgCollection::isCollManageableByUser($collectionId, $this->membership->user_uuid)) {
            throw new Unauthorized("The current user isn't a manager for this collection");
        }
    }
}
