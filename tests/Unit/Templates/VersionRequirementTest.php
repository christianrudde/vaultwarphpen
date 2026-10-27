<?php

namespace Tests\Unit\Templates;

use App\Templates\VersionRequirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VersionRequirementTest extends TestCase
{
    /**
     * Expectations follow Rust's semver crate.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function requirements(): iterable
    {
        yield 'less than, below' => ['<2025.12.2', '2025.12.1', true];
        yield 'less than, equal' => ['<2025.12.2', '2025.12.2', false];
        yield 'at least, equal' => ['>=2025.1.0', '2025.1.0', true];
        yield 'greater than partial' => ['>1.2', '1.2.9', false];
        yield 'greater than partial, next minor' => ['>1.2', '1.3.0', true];
        yield 'at most partial' => ['<=1.2', '1.2.9', true];
        yield 'exact partial' => ['=1.2', '1.2.5', true];
        yield 'exact partial, next minor' => ['=1.2', '1.3.0', false];
        yield 'bare means caret' => ['1.32', '1.37.4', true];
        yield 'caret major' => ['^1.32.5', '2.0.0', false];
        yield 'caret zero minor' => ['^0.2.3', '0.3.0', false];
        yield 'caret zero patch' => ['^0.0.3', '0.0.4', false];
        yield 'tilde' => ['~1.2.3', '1.2.9', true];
        yield 'tilde, next minor' => ['~1.2.3', '1.3.0', false];
        yield 'tilde major' => ['~1', '1.9.0', true];
        yield 'range' => ['>=2025.1.0, <2026.0.0', '2025.6.1', true];
        yield 'range, outside' => ['>=2025.1.0, <2026.0.0', '2026.8.0', false];
        yield 'wildcard' => ['*', '1.0.0', true];
    }

    #[DataProvider('requirements')]
    public function test_requirements_match_like_rust_semver(string $requirement, string $version, bool $expected): void
    {
        $this->assertSame($expected, VersionRequirement::matches($requirement, $version));
    }
}
