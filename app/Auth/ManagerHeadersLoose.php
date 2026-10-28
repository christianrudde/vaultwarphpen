<?php

namespace App\Auth;

use App\Http\Unauthorized;
use App\Models\Membership;
use Illuminate\Http\Request;

/**
 * auth::ManagerHeadersLoose: a confirmed manager, admin or owner, for requests
 * without a collection to check.
 */
final readonly class ManagerHeadersLoose extends OrgHeaders
{
    protected function check(Request $request): void
    {
        if (! $this->isConfirmed() || ! ($this->membership->atLeast(Membership::TYPE_MANAGER))) {
            throw new Unauthorized('You need to be a Manager, Admin or Owner to call this endpoint');
        }
    }
}
