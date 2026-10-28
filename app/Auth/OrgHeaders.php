<?php

namespace App\Auth;

use App\Config\Config;
use App\Http\Data\Query;
use App\Http\Unauthorized;
use App\Models\Device;
use App\Models\Membership;
use App\Models\User;
use App\Support\Uuid;
use Illuminate\Http\Request;

/**
 * auth::OrgHeaders: Headers plus the user's membership in the organization
 * of the request, whose id is the second path segment below /api or the
 * organizationId query field. Revoked members are refused. The narrower
 * guards (AdminHeaders, ...) extend it with their own checks.
 */
readonly class OrgHeaders
{
    final public function __construct(
        public string $host,
        public Device $device,
        public User $user,
        public Membership $membership,
        public string $ip,
    ) {}

    /**
     * @throws Unauthorized
     */
    public static function fromRequest(Request $request, Config $config, Jwt $jwt): static
    {
        $headers = Headers::fromRequest($request, $config, $jwt);

        $orgId = self::orgId($request, $config);
        if ($orgId === null || ! Uuid::isValid($orgId)) {
            throw new Unauthorized('Error getting the organization id');
        }

        $membership = Membership::findByUserAndOrg($headers->user->uuid, $orgId)
            ?? throw new Unauthorized("The current user isn't member of the organization");
        if (! in_array($membership->atype, [Membership::TYPE_OWNER, Membership::TYPE_ADMIN, Membership::TYPE_USER, Membership::TYPE_MANAGER], true)) {
            throw new Unauthorized('Unknown user type in the database');
        }
        if (! in_array($membership->status, [Membership::STATUS_INVITED, Membership::STATUS_ACCEPTED, Membership::STATUS_CONFIRMED], true)) {
            throw new Unauthorized('User status is either revoked or invalid.');
        }

        $guard = new static($headers->host, $headers->device, $headers->user, $membership, $headers->ip);
        $guard->check($request);

        return $guard;
    }

    /**
     * The narrower guards' own conditions.
     *
     * @throws Unauthorized
     */
    protected function check(Request $request): void {}

    protected function isConfirmed(): bool
    {
        return $this->membership->status === Membership::STATUS_CONFIRMED;
    }

    /**
     * A path segment below the API's mount point, percent-decoded like Rocket's.
     */
    protected static function pathSegment(Request $request, Config $config, int $index): ?string
    {
        $mount = $config->string('domain_path').'/api';
        $path = $request->getPathInfo();
        if (! str_starts_with($path, $mount.'/')) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', substr($path, strlen($mount))), static fn (string $segment): bool => $segment !== ''));

        return isset($segments[$index]) ? rawurldecode($segments[$index]) : null;
    }

    /**
     * auth::get_org_id
     */
    private static function orgId(Request $request, Config $config): ?string
    {
        $segment = self::pathSegment($request, $config, 1);

        return $segment !== null && Uuid::isValid($segment) ? $segment : Query::first($request, 'organizationId');
    }
}
