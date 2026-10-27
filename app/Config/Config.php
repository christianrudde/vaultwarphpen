<?php

namespace App\Config;

use InvalidArgumentException;

/**
 * Vaultwarden's configuration (CONFIG in src/config.rs): environment variables,
 * overridden by the config.json the admin panel writes, completed with the
 * defaults of every setting.
 *
 * Relative paths are relative to the working directory in Vaultwarden; here
 * they are relative to the application's base path (see path()).
 */
final class Config
{
    /**
     * @param  array<string, string|int|bool|null>  $values  Every setting, keyed by name
     * @param  list<string>  $overrides  Environment variables overridden by config.json
     * @param  list<string>  $warnings  What Vaultwarden prints at startup without refusing to start
     */
    public function __construct(
        private readonly array $values,
        private readonly string $basePath,
        public readonly ConfigBuilder $env = new ConfigBuilder,
        public readonly ConfigBuilder $file = new ConfigBuilder,
        public readonly array $overrides = [],
        public readonly ConfigFileStatus $fileStatus = ConfigFileStatus::NotFound,
        public readonly string $configFile = 'data/config.json',
        public private(set) array $warnings = [],
    ) {}

    /**
     * Load and validate the configuration like Config::load.
     *
     * @param  callable(string): ?string  $env  Reads an environment variable
     *
     * @throws ConfigException
     */
    public static function load(callable $env, string $basePath, bool $validate = true): self
    {
        $fromEnv = ConfigBuilder::fromEnv($env);
        $configFile = ConfigBuilder::envValue($env, 'CONFIG_FILE')
            ?? Settings::joinPath(ConfigBuilder::envValue($env, 'DATA_FOLDER') ?? 'data', 'config.json');

        $warnings = [];
        foreach ($fromEnv->invalid as $key) {
            $warnings[] = "`{$key}` contains an invalid value for its type and is ignored, the default is used instead.";
        }

        try {
            $fromFile = ConfigBuilder::fromFile(self::absolute($configFile, $basePath));
            $status = $fromFile === null ? ConfigFileStatus::NotFound : ConfigFileStatus::Loaded;
        } catch (ConfigException $exception) {
            // An invalid file is ignored as a whole.
            $warnings[] = "Failed to load configuration from `{$configFile}`: {$exception->getMessage()}";
            $fromFile = null;
            $status = ConfigFileStatus::Error;
        }
        $fromFile ??= new ConfigBuilder;

        $overrides = [];
        $merged = $fromEnv->merge($fromFile, $overrides);
        if ($overrides !== []) {
            $warnings[] = 'The following environment variables are being overridden by the config.json file. '
                .'Please use the admin panel to make changes to them: '.implode(', ', $overrides);
        }
        if (isset($merged->values['smtp_ssl']) || isset($merged->values['smtp_explicit_tls'])) {
            $warnings[] = '`SMTP_SSL` or `SMTP_EXPLICIT_TLS` is set. Please use `SMTP_SECURITY` instead.';
        }

        $config = new self($merged->build(), $basePath, $fromEnv, $fromFile, $overrides, $status, $configFile, $warnings);
        if ($validate) {
            $config->warnings = [...$warnings, ...ConfigValidator::validate($config)];
        }

        return $config;
    }

    public function get(string $name): string|int|bool|null
    {
        if (! array_key_exists($name, $this->values)) {
            throw new InvalidArgumentException("Unknown setting [{$name}].");
        }

        return $this->values[$name];
    }

    public function string(string $name): string
    {
        $value = $this->get($name);

        return is_string($value) ? $value : throw new InvalidArgumentException("Setting [{$name}] is not a string.");
    }

    public function optionalString(string $name): ?string
    {
        $value = $this->get($name);

        return $value === null || is_string($value) ? $value : throw new InvalidArgumentException("Setting [{$name}] is not a string.");
    }

    public function bool(string $name): bool
    {
        $value = $this->get($name);

        return is_bool($value) ? $value : throw new InvalidArgumentException("Setting [{$name}] is not a boolean.");
    }

    public function int(string $name): int
    {
        $value = $this->get($name);

        return is_int($value) ? $value : throw new InvalidArgumentException("Setting [{$name}] is not an integer.");
    }

    public function optionalInt(string $name): ?int
    {
        $value = $this->get($name);

        return $value === null || is_int($value) ? $value : throw new InvalidArgumentException("Setting [{$name}] is not an integer.");
    }

    /**
     * A path setting, made absolute against the base path.
     */
    public function path(string $name): string
    {
        return self::absolute($this->string($name), $this->basePath);
    }

    public function absolutePath(string $path): string
    {
        return self::absolute($path, $this->basePath);
    }

    /**
     * Whether the domain of an email address is in SIGNUPS_DOMAINS_WHITELIST, or no whitelist is set.
     */
    public function isEmailDomainAllowed(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return false;
        }

        $domain = strtolower(substr($email, $at + 1));
        $whitelist = $this->string('signups_domains_whitelist');

        return $whitelist === '' || in_array($domain, array_map(trim(...), explode(',', $whitelist)), true);
    }

    /**
     * The whitelist overrides SIGNUPS_ALLOWED.
     */
    public function isSignupAllowed(string $email): bool
    {
        return $this->string('signups_domains_whitelist') === ''
            ? $this->bool('signups_allowed')
            : $this->isEmailDomainAllowed($email);
    }

    public function isSsoSignupAllowed(string $email): bool
    {
        return $this->string('signups_domains_whitelist') === ''
            ? $this->bool('sso_signups_allowed')
            : $this->isEmailDomainAllowed($email);
    }

    /**
     * Whether the registration link should be hidden.
     */
    public function isSignupDisabled(): bool
    {
        return (! $this->bool('signups_allowed')
                && $this->string('signups_domains_whitelist') === ''
                && ($this->mailEnabled() || ! $this->bool('invitations_allowed')))
            || ($this->bool('sso_enabled') && $this->bool('sso_only'));
    }

    public function isOrgCreationAllowed(string $email): bool
    {
        $users = $this->string('org_creation_users');

        return match ($users) {
            '', 'all' => true,
            'none' => false,
            default => in_array(strtolower($email), array_map(trim(...), explode(',', $users)), true),
        };
    }

    /**
     * The file of the RSA key that signs the login tokens.
     */
    public function privateRsaKey(): string
    {
        return $this->string('rsa_key_filename').'.pem';
    }

    public function mailEnabled(): bool
    {
        return $this->bool('_enable_smtp') && ($this->optionalString('smtp_host') !== null || $this->bool('use_sendmail'));
    }

    public function isWebauthn2faSupported(): bool
    {
        return Url::domain($this->string('domain')) !== null;
    }

    public function isAdminTokenSet(): bool
    {
        return trim($this->optionalString('admin_token') ?? '') !== '';
    }

    private static function absolute(string $path, string $basePath): string
    {
        return $path === '' || str_starts_with($path, '/') ? $path : rtrim($basePath, '/').'/'.$path;
    }
}
