<?php

namespace App\Parity\Watch;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The few calls of GitHub's REST API the watch needs. Without a token it reads
 * public data only, within GitHub's lower rate limit.
 */
final readonly class GitHub
{
    public const string API = 'https://api.github.com';

    public function __construct(
        private ?string $token,
    ) {}

    /**
     * With the token of the gh command line tool or of GitHub Actions.
     */
    public static function fromEnvironment(): self
    {
        foreach (['GH_TOKEN', 'GITHUB_TOKEN'] as $name) {
            $token = getenv($name);
            if (is_string($token) && $token !== '') {
                return new self($token);
            }
        }

        return new self(null);
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request()->get(self::API.$path, $query)->throw()->json();
    }

    /**
     * Every page of a list.
     *
     * @param  array<string, scalar>  $query
     * @return list<array<mixed>>
     */
    public function all(string $path, array $query = []): array
    {
        $items = [];
        for ($page = 1; $page <= 50; $page++) {
            $batch = $this->get($path, [...$query, 'per_page' => 100, 'page' => $page]);
            if (! is_array($batch)) {
                throw new RuntimeException("GitHub answered {$path} with something else than a list.");
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            if (count($batch) < 100) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     */
    public function send(string $method, string $path, array $body): array
    {
        $response = $this->request()->send($method, self::API.$path, ['json' => $body])->throw()->json();

        return is_array($response) ? $response : [];
    }

    private function request(): PendingRequest
    {
        $request = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->timeout(30)->connectTimeout(10);

        return $this->token === null ? $request : $request->withToken($this->token);
    }
}
