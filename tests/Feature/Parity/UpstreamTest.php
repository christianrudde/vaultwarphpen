<?php

namespace Tests\Feature\Parity;

use App\Parity\Reference;
use App\Parity\Upstream\Counterparts;
use App\Parity\Upstream\Diff;
use App\Parity\Upstream\Report;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class UpstreamTest extends TestCase
{
    private const string CIPHERS = <<<'RUST'
        #[post("/ciphers/<cipher_id>/attachment/v2", data = "<data>")]
        async fn post_attachment_v2(cipher_id: CipherId, data: Json<AttachmentRequestData>) -> JsonResult {
            let file_size = data.file_size;
            Ok(Json(json!({ "size": file_size })))
        }

        struct AttachmentRequestData {
            file_size: i64,
        }

        fn helper_thing() -> bool {
            true
        }

        RUST;

    private string $upstream;

    private string $checkout;

    private string $pin;

    protected function setUp(): void
    {
        parent::setUp();

        // An upstream repository with the pinned commit and two after it, and a
        // shallow checkout of the pin alone, as parity:fetch leaves it.
        $this->upstream = $this->dataFolder.'/upstream';
        $this->checkout = $this->dataFolder.'/checkout';
        File::ensureDirectoryExists($this->upstream.'/src/api/core');
        File::ensureDirectoryExists($this->upstream.'/docker');
        File::ensureDirectoryExists($this->checkout);

        $this->git($this->upstream, 'init', '--quiet', '--initial-branch=main');
        File::put($this->upstream.'/src/api/core/ciphers.rs', self::CIPHERS);
        File::put($this->upstream.'/src/config.rs', "make_config! {\n    folders {\n        data_folder: String, false, def, \"data\".to_owned();\n    },\n}\n");
        $this->writeSettings('2026.8.0');
        $this->commit('Pinned commit');
        $this->pin = trim($this->git($this->upstream, 'rev-parse', 'HEAD'));

        File::put($this->upstream.'/src/api/core/ciphers.rs', str_replace(
            ['let file_size = data.file_size;', "    file_size: i64,\n"],
            ["let file_size = data.file_size;\n    enforce_quota(file_size)?;", "    file_size: i64,\n    key: String,\n"],
            self::CIPHERS,
        )."fn enforce_quota(size: i64) -> EmptyResult {\n    Ok(())\n}\n");
        $this->commit('fix(attachments): enforce quota @someone | v2 (#7450)');

        File::put($this->upstream.'/src/config.rs', str_replace('"data"', '"vault"', (string) File::get($this->upstream.'/src/config.rs')));
        File::put($this->upstream.'/Cargo.lock', "# changed\n");
        $this->writeSettings('2026.9.0');
        $this->commit('Change the data folder');

        $this->git($this->checkout, 'init', '--quiet');
        $this->git($this->checkout, 'remote', 'add', 'origin', 'file://'.$this->upstream);
        $this->git($this->checkout, 'fetch', '--quiet', '--depth', '1', 'origin', $this->pin);
        $this->git($this->checkout, 'checkout', '--quiet', '--detach', $this->pin);
    }

    public function test_it_fetches_upstream_back_to_the_pin_and_lists_the_commits_in_order(): void
    {
        $diff = new Diff($this->checkout);
        $head = $diff->fetch('main', $this->pin);

        $this->assertSame(trim($this->git($this->upstream, 'rev-parse', 'main')), $head);
        $this->assertTrue($diff->isAncestor($this->pin, $head));

        $commits = $diff->commits($this->pin, $head);
        $this->assertSame(['fix(attachments): enforce quota @someone | v2 (#7450)', 'Change the data folder'], array_map(static fn ($commit) => $commit->subject, $commits));
        $this->assertSame(7450, $commits[0]->pullRequest());
        $this->assertNull($commits[1]->pullRequest());
        $this->assertTrue($commits[0]->looksLikeSecurityFix());
        $this->assertFalse($commits[1]->looksLikeSecurityFix());

        $this->assertSame(['src/api/core/ciphers.rs'], $commits[0]->changes->files);
        $this->assertSame(['fn post_attachment_v2', 'struct AttachmentRequestData', 'fn helper_thing', 'fn enforce_quota'], $commits[0]->changes->scopes('src/api/core/ciphers.rs'));
        $this->assertSame(['post_attachment_v2', 'helper_thing', 'enforce_quota'], $commits[0]->changes->functions('src/api/core/ciphers.rs'));
        $this->assertSame(['Cargo.lock', 'docker/DockerSettings.yaml', 'src/config.rs'], $commits[1]->changes->files);

        $this->assertStringContainsString('2026.9.0', (string) $diff->show($head, 'docker/DockerSettings.yaml'));
        $this->assertNull($diff->show($head, 'missing.txt'));
        $this->assertNull($diff->resolve('no-such-branch'));
    }

    public function test_the_report_names_the_items_and_counterparts_of_the_changes(): void
    {
        File::ensureDirectoryExists($this->dataFolder.'/app/Http/Controllers');
        File::put($this->dataFolder.'/app/Http/Controllers/CiphersController.php', "<?php\n\nclass CiphersController\n{\n    public function postAttachmentV2() {}\n\n    private function helperThing() {}\n}\n");

        $items = [
            'route:POST /api/ciphers/{cipher_id}/attachment/v2' => ['handler' => 'core::ciphers::post_attachment_v2', 'source' => 'src/api/core/ciphers.rs:1', 'status' => 'done'],
            'config:DATA_FOLDER' => ['source' => 'src/config.rs:3', 'status' => 'done'],
            'config:OTHER' => ['source' => 'src/config.rs:9', 'status' => 'todo'],
            'template:404' => ['source' => 'src/static/templates/404.hbs', 'status' => 'done'],
        ];
        $counterparts = new Counterparts($items, $this->dataFolder);
        $this->assertSame(['app/Http/Controllers/CiphersController.php:5'], $counterparts->php('post_attachment_v2'));
        $this->assertSame(['route:POST /api/ciphers/{cipher_id}/attachment/v2'], $counterparts->handlers('post_attachment_v2'));
        $this->assertSame(['template:404'], $counterparts->items('src/static/templates/404.hbs'));

        $diff = new Diff($this->checkout);
        $head = $diff->fetch('main', $this->pin);
        $reference = new Reference('https://github.com/dani-garcia/vaultwarden.git', $this->pin, '1.37.4', '2026.8.0', 'sha256:'.str_repeat('a', 64), $this->checkout);
        $report = new Report($reference, $counterparts)->render('`main`', $head, $diff->commits($this->pin, $head), $diff->changes($this->pin, $head), $diff->show($head, 'docker/DockerSettings.yaml'));

        $this->assertStringStartsWith(sprintf(
            'Vaultwarden `main` ([`%s`](https://github.com/dani-garcia/vaultwarden/commit/%s)) is 2 commits ahead of the pinned commit [`%s`](https://github.com/dani-garcia/vaultwarden/commit/%s) (1.37.4).',
            substr($head, 0, 8), $head, substr($this->pin, 0, 8), $this->pin,
        ), $report);
        // Upstream text cannot break the table or mention anyone.
        $this->assertStringContainsString("**Security?** fix(attachments): enforce quota @\u{200B}someone \\| v2 ([#7450](https://github.com/dani-garcia/vaultwarden/pull/7450)) | `src/api/core/ciphers.rs`: fn post_attachment_v2, struct AttachmentRequestData, fn helper_thing, fn enforce_quota |", $report);
        $this->assertStringContainsString("| `route:POST /api/ciphers/{cipher_id}/attachment/v2` | done | `src/api/core/ciphers.rs` |\n| `config:DATA_FOLDER` | done | `src/config.rs` |\n\n", $report);
        $this->assertStringNotContainsString('config:OTHER', $report);
        $this->assertStringContainsString("| `src/api/core/ciphers.rs` | `helper_thing` | `app/Http/Controllers/CiphersController.php:7` |\n| `src/api/core/ciphers.rs` | `enforce_quota` | not found by name |", $report);
        $this->assertStringNotContainsString('| `post_attachment_v2` |', $report);
        $this->assertStringContainsString('- `Cargo.lock`: crate versions changed', $report);
        $this->assertStringContainsString('Vaultwarden now ships web vault 2026.9.0 (`sha256:'.str_repeat('b', 64).'`); the pin has 2026.8.0.', $report);
    }

    public function test_parity_upstream_prints_the_report_for_the_reference_checkout(): void
    {
        // A project of its own whose reference checkout is at the pin.
        $project = $this->dataFolder.'/project';
        File::ensureDirectoryExists($project.'/app');
        File::ensureDirectoryExists($project.'/parity');
        File::copy(base_path('parity/reference.json'), $project.'/parity/reference.json');
        File::put($project.'/parity/matrix.json', '{"commit": "", "items": []}');
        $reference = Reference::load(base_path());
        File::ensureDirectoryExists($project.'/reference/vaultwarden/.git');
        File::put($project.'/reference/vaultwarden/.git/HEAD', $reference->commit."\n");
        $this->app->setBasePath($project);

        Process::fake([
            '*--format=%ct*' => (string) time(),
            '*fetch*' => '',
            '*rev-parse*' => $reference->commit,
            '*merge-base*' => '',
            '*log*' => '',
            '*diff*' => '',
            '*show*' => Process::result(exitCode: 128),
        ]);

        $this->command('parity:upstream', ['revision' => '1.37.5'])
            ->expectsOutputToContain('is 0 commits ahead of the pinned commit')
            ->assertSuccessful();

        Process::assertRan(static fn (PendingProcess $process): bool => is_array($process->command) && in_array('+1.37.5:'.Diff::REF, $process->command, true));
        $this->assertSame("*.rs diff=rust\n", File::get($project.'/reference/vaultwarden/.git/info/attributes'));
    }

    public function test_a_patch_is_read_into_files_and_hunks(): void
    {
        $changes = Diff::parse(<<<'PATCH'
            diff --git a/src/a.rs b/src/a.rs
            index 1..2 100644
            --- a/src/a.rs
            +++ b/src/a.rs
            @@ -10,0 +11,3 @@ impl<'a> Foo<'a> {
            +    pub async fn added(&self) {
            +    }
            +
            @@ -20 +23 @@ pub fn changed() {
            -    -- an old line
            +    a new line
            diff --git a/README.md b/README.md
            deleted file mode 100644
            --- a/README.md
            +++ /dev/null
            @@ -1,2 +0,0 @@
            -# Readme
            -
            PATCH);

        $this->assertSame(['src/a.rs', 'README.md'], $changes->files);
        $this->assertCount(3, $changes->hunks);
        [$added, $changed, $removed] = $changes->hunks;
        $this->assertSame([10, 10, 'impl Foo', ['added']], [$added->start, $added->end, $added->scope, $added->functions]);
        $this->assertSame([20, 20, 'fn changed', ['changed']], [$changed->start, $changed->end, $changed->scope, $changed->functions]);
        $this->assertSame(['README.md', 1, 2, null], [$removed->file, $removed->start, $removed->end, $removed->scope]);
    }

    private function writeSettings(string $webVault): void
    {
        $digest = $webVault === '2026.8.0' ? str_repeat('a', 64) : str_repeat('b', 64);
        File::put($this->upstream.'/docker/DockerSettings.yaml', "---\nvault_version: \"v{$webVault}\"\nvault_image_digest: \"sha256:{$digest}\"\n");
    }

    private function commit(string $message): void
    {
        $this->git($this->upstream, 'add', '--all');
        $this->git($this->upstream, 'commit', '--quiet', '--message', $message);
    }

    private function git(string $directory, string ...$arguments): string
    {
        return Process::path($directory)
            ->env(['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.invalid', 'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.invalid'])
            ->run(['git', ...$arguments])->throw()->output();
    }
}
