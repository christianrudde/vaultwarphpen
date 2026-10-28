<?php

namespace App\Auth;

/**
 * auth::OrgMemberHeaders: any member who is not revoked, which OrgHeaders
 * already ensures; invited members may join through it.
 */
final readonly class OrgMemberHeaders extends OrgHeaders {}
