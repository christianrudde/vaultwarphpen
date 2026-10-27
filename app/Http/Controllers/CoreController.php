<?php

namespace App\Http\Controllers;

use App\Auth\Headers;
use App\Http\Data\Body;
use App\Http\HttpStatus;
use App\Http\Json;
use App\Models\User;
use App\Support\Dates;
use App\Support\JsonValue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

/**
 * src/api/core/mod.rs
 */
final readonly class CoreController
{
    public function __construct(
        private WebController $web,
    ) {}

    /**
     * GET /api/alive
     */
    public function alive(): Response
    {
        return $this->web->alive();
    }

    /**
     * GET /api/now
     */
    public function now(): Response
    {
        return Json::response(Dates::format(Dates::now()));
    }

    /**
     * GET /api/version
     */
    public function version(): Response
    {
        return Json::response(config()->string('vaultwarden.version'));
    }

    /**
     * GET /api/settings/domains
     */
    public function getSettingsDomains(Headers $headers): Response
    {
        return Json::response(self::eqDomains($headers->user, noExcluded: false));
    }

    /**
     * POST /api/settings/domains, also PUT
     */
    public function postSettingsDomains(Headers $headers, Request $request): Response
    {
        $body = Body::json($request);
        $excluded = $body->value('excludedGlobalEquivalentDomains') ?? [];
        $equivalent = $body->value('equivalentDomains') ?? [];

        // Option<Vec<i32>> and Option<Vec<Vec<String>>>
        if (! is_array($excluded) || ! array_is_list($excluded)) {
            throw new HttpStatus(422, 'Field `excludedGlobalEquivalentDomains` must be a sequence.');
        }
        foreach ($excluded as $type) {
            if (! is_int($type) || $type < -2147483648 || $type > 2147483647) {
                throw new HttpStatus(422, 'Field `excludedGlobalEquivalentDomains` must hold i32s.');
            }
        }
        if (! is_array($equivalent) || ! array_is_list($equivalent)) {
            throw new HttpStatus(422, 'Field `equivalentDomains` must be a sequence.');
        }
        foreach ($equivalent as $group) {
            if (! is_array($group) || ! array_is_list($group) || array_filter($group, is_string(...)) !== $group) {
                throw new HttpStatus(422, 'Field `equivalentDomains` must hold sequences of strings.');
            }
        }

        $user = $headers->user;
        $user->excluded_globals = Json::encode($excluded);
        $user->equivalent_domains = Json::encode($equivalent);
        $user->save();

        return Json::response(new \stdClass);
    }

    /**
     * core::get_eq_domains: the user's own groups of equivalent domains and the
     * global ones, marked as excluded by the user or left out.
     *
     * @return array<string, mixed>
     */
    public static function eqDomains(User $user, bool $noExcluded): array
    {
        // Upstream unwraps these; values it did not write itself make it panic.
        [$ok, $equivalent] = JsonValue::decode($user->equivalent_domains);
        if (! $ok || ! is_array($equivalent) || ! array_is_list($equivalent)) {
            throw new LogicException('Invalid equivalent_domains of user '.$user->uuid);
        }
        [$ok, $excluded] = JsonValue::decode($user->excluded_globals);
        if (! $ok || ! is_array($excluded) || ! array_is_list($excluded)) {
            throw new LogicException('Invalid excluded_globals of user '.$user->uuid);
        }

        $globals = [];
        foreach (self::globalDomains() as $global) {
            $global['excluded'] = in_array($global['type'], $excluded, true);
            if (! $noExcluded || ! $global['excluded']) {
                $globals[] = $global;
            }
        }

        return Json::sorted([
            'equivalentDomains' => $equivalent,
            'globalEquivalentDomains' => $globals,
            'object' => 'domains',
        ]);
    }

    /**
     * src/static/global_domains.json
     *
     * @return list<array{type: int, domains: list<string>, excluded: bool}>
     */
    private static function globalDomains(): array
    {
        /** @var list<array{type: int, domains: list<string>, excluded: bool}> */
        return json_decode((string) file_get_contents(resource_path('vaultwarden/static/global_domains.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}
