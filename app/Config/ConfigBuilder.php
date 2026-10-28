<?php

namespace App\Config;

use App\Http\Json;
use Closure;
use JsonException;
use stdClass;

/**
 * The settings given by one source (the environment or config.json), before
 * defaults are filled in. Mirrors Vaultwarden's ConfigBuilder.
 */
final readonly class ConfigBuilder
{
    /**
     * @param  array<string, string|int|bool>  $values  Only the settings this source sets, keyed by name
     * @param  list<string>  $invalid  Environment variables that were ignored because of an invalid value
     */
    public function __construct(
        public array $values = [],
        public array $invalid = [],
    ) {}

    /**
     * Read every setting from the environment, with `<NAME>_FILE` naming a file
     * that holds the value (get_env / get_env_bool).
     *
     * @param  callable(string): ?string  $env
     *
     * @throws ConfigException
     */
    public static function fromEnv(callable $env): self
    {
        $values = [];
        $invalid = [];

        foreach (Settings::all() as $name => $setting) {
            $raw = self::envValue($env, $setting->env());
            if ($raw === null) {
                continue;
            }

            $value = $setting->type->parse($raw);
            if ($value === null) {
                $invalid[] = $setting->env();

                continue;
            }
            $values[$name] = $value;
        }

        return new self($values, $invalid);
    }

    /**
     * Read config.json; null when it does not exist.
     *
     * @throws ConfigException When it exists but is invalid; Vaultwarden then ignores the whole file
     */
    public static function fromFile(string $file): ?self
    {
        if (! is_file($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            throw new ConfigException("Failed to read `{$file}`.");
        }

        try {
            $data = json_decode($content, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new ConfigException("`{$file}` is not valid JSON: {$exception->getMessage()}", previous: $exception);
        }

        if (! $data instanceof stdClass) {
            throw new ConfigException("`{$file}` does not contain a JSON object.");
        }

        try {
            return self::fromJson($data);
        } catch (ConfigException $exception) {
            throw new ConfigException("`{$file}` {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * Deserialize a JSON object like serde: unknown keys are ignored, null
     * means unset, and every other value must fit the type of its setting.
     *
     * @throws ConfigException
     */
    public static function fromJson(stdClass $data): self
    {
        $values = [];
        foreach (Settings::all() as $name => $setting) {
            $value = property_exists($data, $name) ? $data->{$name} : null;
            if ($value === null) {
                continue;
            }
            if (! is_string($value) && ! is_int($value) && ! is_bool($value) || ! $setting->type->accepts($value)) {
                throw new ConfigException("has an invalid value for `{$name}`, expected {$setting->type->value}.");
            }
            $values[$name] = $value;
        }

        return new self($values);
    }

    /**
     * The builder as config.json holds it: serde_json's pretty format, in
     * the order of the settings, without the ones that are not set.
     */
    public function toJson(): string
    {
        $lines = [];
        foreach (array_keys(Settings::all()) as $name) {
            if (isset($this->values[$name])) {
                $lines[] = '  '.Json::encode($name).': '.Json::encode($this->values[$name]);
            }
        }

        return $lines === [] ? '{}' : "{\n".implode(",\n", $lines)."\n}";
    }

    /**
     * Combine both sources; values of $other win (config.json over the environment).
     *
     * @param  list<string>  $overrides  Receives the environment variables that $other overrides
     */
    public function merge(self $other, array &$overrides = []): self
    {
        foreach (array_keys($other->values) as $name) {
            if (isset($this->values[$name])) {
                $overrides[] = strtoupper($name);
            }
        }

        return new self($other->values + $this->values, $this->invalid);
    }

    /**
     * Only the settings that may be changed in the admin panel.
     */
    public function withoutNonEditable(): self
    {
        $settings = Settings::all();

        return new self(array_filter(
            $this->values,
            static fn (string $name): bool => $settings[$name]->editable,
            ARRAY_FILTER_USE_KEY,
        ), $this->invalid);
    }

    /**
     * Fill in defaults, auto and generated values (ConfigBuilder::build).
     *
     * @return array<string, string|int|bool|null>
     */
    public function build(): array
    {
        $config = [];

        foreach (Settings::all() as $name => $setting) {
            $value = $this->values[$name] ?? null;
            $default = $setting->default;

            $config[$name] = match ($setting->action) {
                'option' => $value,
                'def' => $value ?? ($default instanceof Closure ? null : $default),
                'auto' => $value ?? ($default instanceof Closure ? $default($config) : null),
                // generated: a given value is ignored.
                default => $default instanceof Closure ? $default($config) : null,
            };
        }

        $config['domain_set'] = isset($this->values['domain']);
        $config['domain'] = rtrim(self::string($config['domain']), '/');
        $config['signups_domains_whitelist'] = strtolower(trim(self::string($config['signups_domains_whitelist'])));
        $config['org_creation_users'] = strtolower(trim(self::string($config['org_creation_users'])));

        // The deprecated setting still applies when the new one is not given.
        $config['http_request_block_regex'] ??= $config['icon_blacklist_regex'];

        return $config;
    }

    /**
     * @param  callable(string): ?string  $env
     *
     * @throws ConfigException
     */
    public static function envValue(callable $env, string $key): ?string
    {
        $value = $env($key);
        $file = $env("{$key}_FILE");

        if ($value !== null && $file !== null) {
            throw new ConfigException("You should not define both {$key} and {$key}_FILE!");
        }
        if ($file === null) {
            return $value;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            throw new ConfigException("Failed to load {$key}: cannot read {$file}.");
        }

        return trim($content);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
