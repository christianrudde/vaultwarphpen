<?php

namespace Tests\Unit\Config;

use App\Config\Config;
use App\Config\ConfigException;
use App\Config\ConfigFileStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Attributes\Parity;

/**
 * Loading like Config::load: environment, *_FILE, config.json, defaults.
 */
class ConfigTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/vw-config-'.bin2hex(random_bytes(6));
        mkdir($this->base.'/data', recursive: true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->base));
    }

    #[Parity('config:DATA_FOLDER')]
    #[Parity('config:TEMPLATES_FOLDER')]
    public function test_defaults_without_any_setting(): void
    {
        $config = $this->load();

        $this->assertSame('data', $config->string('data_folder'));
        $this->assertSame('sqlite://data/db.sqlite3', $config->string('database_url'));
        $this->assertSame('data/templates', $config->string('templates_folder'));
        $this->assertSame("{$this->base}/data/templates", $config->path('templates_folder'));
        $this->assertSame('data/rsa_key.pem', $config->privateRsaKey());
        $this->assertSame('http://localhost', $config->string('domain'));
        $this->assertFalse($config->bool('domain_set'));
        $this->assertSame('http://localhost', $config->string('domain_origin'));
        $this->assertSame('', $config->string('domain_path'));
        $this->assertSame('http://localhost/identity/connect/oidc-signin', $config->string('sso_callback_path'));
        $this->assertTrue($config->bool('_ip_header_enabled'));
        $this->assertSame('starttls', $config->string('smtp_security'));
        $this->assertSame(587, $config->int('smtp_port'));
        $this->assertFalse($config->bool('_enable_email_2fa'));
        $this->assertSame(10_000, $config->int('_max_note_size'));
        $this->assertSame('cid:', $config->string('_smtp_img_src'));
        $this->assertTrue($config->bool('http_request_block_non_global_ips'));
        $this->assertSame('', $config->string('_icon_service_url'));
        $this->assertNull($config->optionalString('admin_token'));
        $this->assertNull($config->optionalInt('trash_auto_delete_days'));
        $this->assertSame(ConfigFileStatus::NotFound, $config->fileStatus);
        $this->assertSame([], $config->warnings);
    }

    #[Parity('config:DATA_FOLDER')]
    public function test_folders_follow_the_data_folder(): void
    {
        // The folder does not exist here, which validation would refuse.
        $config = $this->load(['DATA_FOLDER' => '/srv/vaultwarden/'], validate: false);

        $this->assertSame('sqlite:///srv/vaultwarden/db.sqlite3', $config->string('database_url'));
        $this->assertSame('/srv/vaultwarden/attachments', $config->string('attachments_folder'));
        $this->assertSame('/srv/vaultwarden/icon_cache', $config->path('icon_cache_folder'));
        $this->assertSame('/srv/vaultwarden/rsa_key', $config->string('rsa_key_filename'));
    }

    public function test_domain_is_split_into_origin_and_path(): void
    {
        $config = $this->load(['DOMAIN' => 'https://Vault.Example.com:443/base/']);

        $this->assertSame('https://Vault.Example.com:443/base', $config->string('domain'));
        $this->assertTrue($config->bool('domain_set'));
        $this->assertSame('https://vault.example.com', $config->string('domain_origin'));
        $this->assertSame('/base', $config->string('domain_path'));
        $this->assertSame('https://Vault.Example.com:443/base/identity/connect/oidc-signin', $config->string('sso_callback_path'));
        $this->assertTrue($config->isWebauthn2faSupported());

        $this->assertFalse($this->load(['DOMAIN' => 'http://192.168.1.2:8080'])->isWebauthn2faSupported());
    }

    /**
     * @return iterable<string, array{string, string, string|int|bool}>
     */
    public static function environmentValues(): iterable
    {
        yield 'bool true' => ['SIGNUPS_ALLOWED', 'false', false];
        yield 'bool yes' => ['SIGNUPS_VERIFY', 'Yes', true];
        yield 'bool t' => ['SIGNUPS_VERIFY', 't', true];
        yield 'bool 0' => ['SIGNUPS_ALLOWED', '0', false];
        yield 'bool N' => ['SIGNUPS_ALLOWED', 'N', false];
        yield 'u16 with plus sign' => ['ICON_DOWNLOAD_TIMEOUT', '+20', 20];
        yield 'u16 leading zeros' => ['ICON_DOWNLOAD_TIMEOUT', '0020', 20];
        yield 'i32 negative' => ['PASSWORD_ITERATIONS', '-5', -5];
        yield 'i64 large' => ['USER_ATTACHMENT_LIMIT', '9007199254740991', 9_007_199_254_740_991];
        yield 'string untouched' => ['INVITATION_ORG_NAME', '  Acme  ', '  Acme  '];
    }

    #[DataProvider('environmentValues')]
    public function test_environment_values_are_parsed_like_rust(string $key, string $value, string|int|bool $expected): void
    {
        // PASSWORD_ITERATIONS below 100000 fails validation, which is not what this test is about.
        $this->assertSame($expected, $this->load([$key => $value], validate: false)->get(strtolower($key)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidEnvironmentValues(): iterable
    {
        yield 'bool word' => ['SIGNUPS_ALLOWED', 'maybe'];
        yield 'u16 negative' => ['ICON_DOWNLOAD_TIMEOUT', '-1'];
        yield 'u16 too large' => ['ICON_DOWNLOAD_TIMEOUT', '65536'];
        yield 'u8 too large' => ['EMAIL_TOKEN_SIZE', '256'];
        yield 'underscores' => ['ICON_CACHE_TTL', '1_000'];
        yield 'whitespace' => ['ICON_CACHE_TTL', ' 5'];
        yield 'decimal' => ['ICON_CACHE_TTL', '5.0'];
        yield 'empty' => ['ICON_CACHE_TTL', ''];
    }

    #[DataProvider('invalidEnvironmentValues')]
    public function test_invalid_environment_values_fall_back_to_the_default(string $key, string $value): void
    {
        $config = $this->load([$key => $value]);

        $this->assertSame($this->load()->get(strtolower($key)), $config->get(strtolower($key)));
        $this->assertSame([$key], $config->env->invalid);
        $this->assertStringContainsString("`{$key}` contains an invalid value", $config->warnings[0]);
    }

    public function test_values_can_come_from_files(): void
    {
        file_put_contents("{$this->base}/token", "  secret-token \n");

        $config = $this->load(['ADMIN_TOKEN_FILE' => "{$this->base}/token"]);

        $this->assertSame('secret-token', $config->optionalString('admin_token'));
    }

    public function test_a_value_and_a_file_for_the_same_setting_are_refused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('You should not define both ADMIN_TOKEN and ADMIN_TOKEN_FILE!');

        $this->load(['ADMIN_TOKEN' => 'a', 'ADMIN_TOKEN_FILE' => "{$this->base}/token"]);
    }

    public function test_config_json_overrides_the_environment(): void
    {
        $this->writeConfigJson([
            'domain' => 'https://vault.example.com',
            'signups_allowed' => false,
            'icon_cache_ttl' => 60,
            'admin_token' => null,
            'unknown_setting' => 'ignored',
        ]);

        $config = $this->load(['DOMAIN' => 'https://other.example.com', 'ADMIN_TOKEN' => 'from-env']);

        $this->assertSame(ConfigFileStatus::Loaded, $config->fileStatus);
        $this->assertSame('https://vault.example.com', $config->string('domain'));
        $this->assertFalse($config->bool('signups_allowed'));
        $this->assertSame(60, $config->int('icon_cache_ttl'));
        $this->assertSame('from-env', $config->optionalString('admin_token'));
        $this->assertSame(['DOMAIN'], $config->overrides);
    }

    public function test_config_json_is_found_through_data_folder_or_config_file(): void
    {
        mkdir("{$this->base}/elsewhere");
        file_put_contents("{$this->base}/elsewhere/config.json", '{"invitation_org_name": "From data folder"}');
        file_put_contents("{$this->base}/custom.json", '{"invitation_org_name": "From CONFIG_FILE"}');

        $this->assertSame('From data folder', $this->load(['DATA_FOLDER' => 'elsewhere'])->string('invitation_org_name'));
        $this->assertSame('From CONFIG_FILE', $this->load(['CONFIG_FILE' => 'custom.json'])->string('invitation_org_name'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidConfigFiles(): iterable
    {
        yield 'syntax' => ['{"domain": '];
        yield 'not an object' => ['["domain"]'];
        yield 'string for a number' => ['{"domain": "https://a.example", "icon_cache_ttl": "60"}'];
        yield 'float for a number' => ['{"domain": "https://a.example", "icon_cache_ttl": 60.0}'];
        yield 'out of range' => ['{"domain": "https://a.example", "email_token_size": 300}'];
        yield 'number for a bool' => ['{"domain": "https://a.example", "signups_allowed": 0}'];
    }

    #[DataProvider('invalidConfigFiles')]
    public function test_an_invalid_config_json_is_ignored_as_a_whole(string $json): void
    {
        file_put_contents("{$this->base}/data/config.json", $json);

        $config = $this->load();

        $this->assertSame(ConfigFileStatus::Error, $config->fileStatus);
        $this->assertSame('http://localhost', $config->string('domain'));
        $this->assertStringContainsString('Failed to load configuration from `data/config.json`', $config->warnings[0]);
    }

    public function test_generated_values_ignore_given_values(): void
    {
        $config = $this->load(['_MAX_NOTE_SIZE' => '5', 'INCREASE_NOTE_SIZE_LIMIT' => 'true', '_IP_HEADER_ENABLED' => 'false']);

        $this->assertSame(100_000, $config->int('_max_note_size'));
        $this->assertTrue($config->bool('_ip_header_enabled'));
        $this->assertFalse($this->load(['IP_HEADER' => ' NONE '])->bool('_ip_header_enabled'));
    }

    #[Parity('config:SMTP_SSL')]
    #[Parity('config:SMTP_EXPLICIT_TLS')]
    public function test_deprecated_smtp_options_choose_the_security(): void
    {
        $tls = $this->load(['SMTP_EXPLICIT_TLS' => 'true']);
        $this->assertSame('force_tls', $tls->string('smtp_security'));
        $this->assertSame(465, $tls->int('smtp_port'));
        $this->assertStringContainsString('`SMTP_SSL` or `SMTP_EXPLICIT_TLS` is set', $tls->warnings[0]);

        $off = $this->load(['SMTP_SSL' => 'false']);
        $this->assertSame('off', $off->string('smtp_security'));
        $this->assertSame(25, $off->int('smtp_port'));

        $this->assertSame(2525, $this->load(['SMTP_SECURITY' => 'off', 'SMTP_PORT' => '2525'])->int('smtp_port'));
    }

    public function test_values_are_normalized_after_building(): void
    {
        $config = $this->load([
            'SIGNUPS_DOMAINS_WHITELIST' => ' Example.COM,example.org ',
            'ORG_CREATION_USERS' => ' Admin@Example.com ',
            'ICON_BLACKLIST_REGEX' => '^internal\.',
        ]);

        $this->assertSame('example.com,example.org', $config->string('signups_domains_whitelist'));
        $this->assertSame('admin@example.com', $config->string('org_creation_users'));
        $this->assertSame('^internal\.', $config->optionalString('http_request_block_regex'));
    }

    public function test_email_2fa_is_enabled_with_a_mail_transport(): void
    {
        $config = $this->load(['SMTP_HOST' => 'smtp.example.com', 'SMTP_FROM' => 'vault@example.com']);

        $this->assertTrue($config->bool('_enable_email_2fa'));
        $this->assertTrue($config->mailEnabled());
    }

    public function test_signup_and_organization_rules(): void
    {
        $config = $this->load(['SIGNUPS_ALLOWED' => 'false', 'SIGNUPS_DOMAINS_WHITELIST' => 'example.com', 'ORG_CREATION_USERS' => 'boss@example.com']);

        $this->assertTrue($config->isSignupAllowed('new@EXAMPLE.com'));
        $this->assertFalse($config->isSignupAllowed('new@example.org'));
        $this->assertFalse($config->isEmailDomainAllowed('@example.com'));
        $this->assertFalse($config->isEmailDomainAllowed('nobody@'));
        $this->assertTrue($config->isOrgCreationAllowed('Boss@Example.com'));
        $this->assertFalse($config->isOrgCreationAllowed('other@example.com'));
        $this->assertFalse($config->isSignupDisabled());

        $closed = $this->load(['SIGNUPS_ALLOWED' => 'false', 'INVITATIONS_ALLOWED' => 'false']);
        $this->assertFalse($closed->isSignupAllowed('new@example.com'));
        $this->assertTrue($closed->isSignupDisabled());
        $this->assertFalse($this->load(['ORG_CREATION_USERS' => 'none'])->isOrgCreationAllowed('a@b.c'));
    }

    /**
     * @param  array<string, string>  $env
     */
    private function load(array $env = [], bool $validate = true): Config
    {
        return Config::load(static fn (string $key): ?string => $env[$key] ?? null, $this->base, $validate);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function writeConfigJson(array $values): void
    {
        file_put_contents("{$this->base}/data/config.json", json_encode($values, JSON_THROW_ON_ERROR));
    }
}
