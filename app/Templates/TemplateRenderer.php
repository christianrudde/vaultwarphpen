<?php

namespace App\Templates;

use Closure;
use DevTheorem\Handlebars\Handlebars;
use DevTheorem\Handlebars\HelperOptions;
use DevTheorem\Handlebars\Options;
use DevTheorem\Handlebars\SafeString;
use RuntimeException;

/**
 * Renders Vaultwarden's Handlebars templates (mails, admin pages, CSS).
 *
 * Templates are addressed by their path without ".hbs", e.g. "email/welcome"
 * or "email/welcome.html". A file with the same name in the templates folder
 * overrides the built-in template, exactly like in Vaultwarden.
 */
final class TemplateRenderer
{
    /**
     * Vaultwarden's own helpers, then those handlebars-rust registers by default that the templates use.
     */
    private const array HELPERS = ['case', 'to_json', 'webver', 'vwver', 'eq', 'not', 'or'];

    /**
     * Marks a blank line that handlebars-rust leaves unindented in an indented partial.
     */
    private const string UNINDENTED = "\u{F8FF}";

    /** @var array<string, Closure> */
    private array $compiled = [];

    public function __construct(
        private readonly string $builtinPath,
        private readonly ?string $overridePath,
        private readonly string $vaultwardenVersion,
        private readonly string $webVaultVersion,
        private readonly bool $reload = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $name, array $data): string
    {
        return $this->execute($this->compile($name, builtinOnly: false), $data);
    }

    /**
     * Render the built-in version of a template, ignoring overrides.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderFallback(string $name, array $data): string
    {
        return $this->execute($this->compile($name, builtinOnly: true), $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function execute(Closure $template, array $data): string
    {
        $result = $template($data, [
            'helpers' => $this->helpers(),
            'partialResolver' => fn (string $name): Closure => $this->compile($name, builtinOnly: false),
        ]);

        if (! is_string($result)) {
            throw new RuntimeException('Template did not render to a string.');
        }

        return str_replace(self::UNINDENTED, '', (string) preg_replace('/^[ \t]*'.self::UNINDENTED.'/mu', '', $result));
    }

    private function compile(string $name, bool $builtinOnly): Closure
    {
        $key = ($builtinOnly ? 'fallback:' : '').$name;

        if ($this->reload || ! isset($this->compiled[$key])) {
            // Vaultwarden renders in strict mode: a missing value is an error.
            $this->compiled[$key] = Handlebars::compile($this->source($name, $builtinOnly), new Options(
                knownHelpers: array_fill_keys(self::HELPERS, true),
                strict: true,
            ));
        }

        return $this->compiled[$key];
    }

    private function source(string $name, bool $builtinOnly): string
    {
        if (! preg_match('#^[\w.-]+(?:/[\w.-]+)*$#', $name) || str_contains($name, '..')) {
            throw new RuntimeException("Invalid template name [{$name}].");
        }

        $paths = $builtinOnly || $this->overridePath === null
            ? [$this->builtinPath]
            : [$this->overridePath, $this->builtinPath];

        foreach ($paths as $path) {
            $file = "{$path}/{$name}.hbs";
            if (is_file($file)) {
                return self::markUnindentedLines((string) file_get_contents($file));
            }
        }

        throw new RuntimeException("Template [{$name}] not found.");
    }

    /**
     * handlebars-rust indents a partial while writing it: after a line break
     * only when more of the same text follows, or when the next text does not
     * start with a line break. So an empty line right after a standalone tag
     * line (whose line break is stripped) stays empty, where DevTheorem
     * indents every line. Those lines are marked here; execute() removes the
     * indent in front of the mark, and the mark.
     */
    private static function markUnindentedLines(string $source): string
    {
        return (string) preg_replace('/^([ \t]*\{\{~?\s*(?:[#\/^>!]|else\b)(?:[^}]|\}(?!\}))*\}\}[ \t]*\n)(?=\n)/m', '$1'.self::UNINDENTED, $source);
    }

    /**
     * The helpers Vaultwarden registers (src/config.rs, load_templates).
     *
     * @return array<string, Closure>
     */
    private function helpers(): array
    {
        return [
            // {{#case value "a" "b"}}: render the block if value equals one of the others.
            'case' => static function (mixed $value, mixed ...$arguments): string {
                $options = array_pop($arguments);
                if (! $options instanceof HelperOptions) {
                    throw new RuntimeException('The "case" helper must be used as a block.');
                }

                return in_array($value, $arguments, true) ? $options->fn() : '';
            },
            'to_json' => static fn (mixed $value): SafeString => new SafeString(
                json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ),
            'webver' => fn (string $requirement): bool => VersionRequirement::matches($requirement, $this->webVaultVersion),
            'vwver' => fn (string $requirement): bool => VersionRequirement::matches($requirement, $this->vaultwardenVersion),
            'eq' => static fn (mixed $a, mixed $b): bool => $a === $b,
            'not' => static fn (mixed $value): bool => ! self::isTruthy($value),
            'or' => static fn (mixed $a, mixed $b): bool => self::isTruthy($a) || self::isTruthy($b),
        ];
    }

    /**
     * JsonTruthy::is_truthy of handlebars-rust, without zero: empty strings,
     * lists and objects, zero, null and false are false.
     */
    private static function isTruthy(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_int($value), is_float($value) => $value != 0 && ! is_nan((float) $value),
            is_string($value), is_array($value) => $value !== '' && $value !== [],
            default => $value !== null,
        };
    }
}
