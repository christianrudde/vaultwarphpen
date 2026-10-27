<?php

namespace App\Support;

use App\Config\Config;
use App\Http\ApiError;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use LogicException;

/**
 * Vaultwarden's per-IP rate limits (src/ratelimit.rs): governor's GCRA with
 * a burst of `*_MAX_BURST` requests and one more every `*_SECONDS`. The state
 * lives in the cache, so all PHP workers share it.
 */
final readonly class RateLimiter
{
    private const array LIMITS = [
        'login' => ['login_ratelimit_seconds', 'login_ratelimit_max_burst', 'Too many login requests'],
        'admin' => ['admin_ratelimit_seconds', 'admin_ratelimit_max_burst', 'Too many admin requests'],
        'unauthenticated' => ['unauthenticated_ratelimit_seconds', 'unauthenticated_ratelimit_max_burst', 'Too many requests'],
    ];

    public function __construct(
        private Config $config,
        private Repository $cache,
    ) {}

    /**
     * @param  'login'|'admin'|'unauthenticated'  $limit
     *
     * @throws ApiError With status 429
     */
    public function check(string $limit, string $ip): void
    {
        [$secondsSetting, $burstSetting, $message] = self::LIMITS[$limit];
        $interval = $this->config->int($secondsSetting) * 1_000_000;
        $burst = $this->config->int($burstSetting);
        $key = "vaultwarden:ratelimit:{$limit}:{$ip}";

        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new LogicException('The cache store must support locks.');
        }

        $allowed = $store->lock("{$key}:lock", 5)->block(5, function () use ($key, $interval, $burst): bool {
            $now = (int) (microtime(true) * 1_000_000);
            $stored = $this->cache->get($key);
            // The theoretical arrival time of the next request.
            $tat = max(is_int($stored) ? $stored : $now, $now) + $interval;
            if ($tat - $now > $interval * $burst) {
                return false;
            }

            $this->cache->put($key, $tat, (int) ceil(($tat - $now) / 1_000_000) + 1);

            return true;
        });

        if ($allowed !== true) {
            throw ApiError::message($message, 429);
        }
    }
}
