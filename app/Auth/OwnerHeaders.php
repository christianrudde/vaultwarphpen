<?php

namespace App\Auth;

use App\Http\Unauthorized;
use App\Models\Membership;
use Illuminate\Http\Request;

/**
 * auth::OwnerHeaders: a confirmed owner.
 */
final readonly class OwnerHeaders extends OrgHeaders
{
    protected function check(Request $request): void
    {
        if (! $this->isConfirmed() || ! ($this->membership->atype === Membership::TYPE_OWNER)) {
            throw new Unauthorized('You need to be Owner to call this endpoint');
        }
    }
}
