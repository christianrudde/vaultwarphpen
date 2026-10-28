<?php

namespace App\Parity\Upstream;

/**
 * The files and places a commit, or a range of commits, changes.
 */
final readonly class Changes
{
    /**
     * @param  list<string>  $files
     * @param  list<Hunk>  $hunks
     */
    public function __construct(
        public array $files,
        public array $hunks,
    ) {}

    /**
     * @return list<Hunk>
     */
    public function hunks(string $file): array
    {
        return array_values(array_filter($this->hunks, static fn (Hunk $hunk): bool => $hunk->file === $file));
    }

    /**
     * The functions changed in a file, in the order they were found.
     *
     * @return list<string>
     */
    public function functions(string $file): array
    {
        $functions = [];
        foreach ($this->hunks($file) as $hunk) {
            $functions = [...$functions, ...$hunk->functions];
        }

        return array_values(array_unique($functions));
    }

    /**
     * What changed in a file: its functions, or the items the changes lie in.
     *
     * @return list<string>
     */
    public function scopes(string $file): array
    {
        $scopes = [];
        foreach ($this->hunks($file) as $hunk) {
            foreach ($hunk->functions === [] ? [$hunk->scope] : array_map(static fn (string $name): string => "fn {$name}", $hunk->functions) as $scope) {
                if ($scope !== null && ! in_array($scope, $scopes, true)) {
                    $scopes[] = $scope;
                }
            }
        }

        return $scopes;
    }
}
