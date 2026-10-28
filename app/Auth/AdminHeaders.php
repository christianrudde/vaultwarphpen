<?php

namespace App\Auth;

use App\Http\Unauthorized;
use App\Models\Membership;
use Illuminate\Http\Request;

/**
 * auth::AdminHeaders: a confirmed admin or owner.
 */
final readonly class AdminHeaders extends OrgHeaders
{
    protected function check(Request $request): void
    {
        if (! $this->isConfirmed() || ! ($this->membership->atLeast(Membership::TYPE_ADMIN))) {
            throw new Unauthorized('You need to be Admin or Owner to call this endpoint');
        }
    }
}
