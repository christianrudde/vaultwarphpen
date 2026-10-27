<?php

namespace Tests\Feature\Templates;

use App\Templates\TemplateRenderer;
use DevTheorem\Handlebars\Handlebars;
use DevTheorem\Handlebars\Options;
use Illuminate\Support\Facades\File;
use Tests\Attributes\Parity;
use Tests\TestCase;
use Throwable;

class TemplateRendererTest extends TestCase
{
    private string $overrides;

    protected function setUp(): void
    {
        parent::setUp();

        $this->overrides = sys_get_temp_dir().'/vaultwarphpen-templates-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->overrides);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->overrides);

        parent::tearDown();
    }

    #[Parity('template:*')]
    public function test_every_builtin_template_compiles(): void
    {
        $files = File::allFiles(resource_path('vaultwarden/templates'));
        $this->assertCount(74, $files);

        foreach ($files as $file) {
            try {
                Handlebars::compile($file->getContents(), new Options(strict: true));
            } catch (Throwable $e) {
                $this->fail("{$file->getRelativePathname()}: {$e->getMessage()}");
            }
        }
    }

    public function test_welcome_mail_renders_with_partials(): void
    {
        $data = ['url' => 'https://vault.example.test', 'img_src' => 'cid:'];

        $text = $this->renderer()->render('email/welcome', $data);
        $html = $this->renderer()->render('email/welcome.html', $data);

        $this->assertStringStartsWith("Welcome\n<!---------------->\n", $text);
        $this->assertStringContainsString('Thank you for creating an account at https://vault.example.test.', $text);
        $this->assertStringContainsString('https://github.com/dani-garcia/vaultwarden', $text);
        $this->assertStringContainsString('<!DOCTYPE html', $html);
    }

    public function test_override_folder_takes_precedence_over_builtin_templates(): void
    {
        File::put($this->overrides.'/404.hbs', 'custom {{urlpath}}');

        $this->assertSame('custom /x', $this->renderer()->render('404', ['urlpath' => '/x']));
        $this->assertStringNotContainsString('custom', $this->renderer()->renderFallback('404', ['urlpath' => '/x']));
    }

    public function test_case_helper_compares_strictly_against_all_alternatives(): void
    {
        $this->template('case', '{{#case value "a" 1}}yes{{/case}}');

        $this->assertSame('yes', $this->renderer()->render('case', ['value' => 'a']));
        $this->assertSame('yes', $this->renderer()->render('case', ['value' => 1]));
        $this->assertSame('', $this->renderer()->render('case', ['value' => '1']));
    }

    public function test_to_json_helper_writes_unescaped_json(): void
    {
        $this->template('json', '{{to_json data}}');

        $this->assertSame('{"a":"<b>","url":"https://x"}', $this->renderer()->render('json', ['data' => ['a' => '<b>', 'url' => 'https://x']]));
    }

    public function test_version_helpers_compare_with_the_mirrored_versions(): void
    {
        $this->template('versions', '{{#if (webver "<2025.12.2")}}old{{else}}new{{/if}} {{#if (vwver ">=1.37.0")}}yes{{/if}}');

        $this->assertSame('new yes', $this->renderer()->render('versions', []));
    }

    public function test_missing_values_are_errors_like_in_vaultwarden(): void
    {
        $this->template('strict', 'Hello {{name}}');

        $this->expectException(Throwable::class);

        $this->renderer()->render('strict', []);
    }

    private function template(string $name, string $source): void
    {
        File::put("{$this->overrides}/{$name}.hbs", $source);
    }

    private function renderer(): TemplateRenderer
    {
        return new TemplateRenderer(
            builtinPath: resource_path('vaultwarden/templates'),
            overridePath: $this->overrides,
            vaultwardenVersion: '1.37.4',
            webVaultVersion: '2026.8.0',
        );
    }
}
