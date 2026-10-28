<?php

namespace App\Parity\Upstream;

use App\Parity\Reference;

/**
 * Markdown for what changed upstream since the pin: the commits, the parity
 * items whose source changed and our counterparts of the other changed
 * functions. parity:upstream prints it, the watch puts it in an issue.
 */
final readonly class Report
{
    /**
     * GitHub takes issue bodies up to 65536 characters.
     */
    private const int MAX_LENGTH = 60_000;

    /**
     * Files outside src/ that need a look of their own when they change.
     */
    private const array FILE_HINTS = [
        'Cargo.lock' => 'crate versions changed; check the crates NOTICE.md says we reproduce',
        'Cargo.toml' => 'dependencies or features changed',
        'docker/DockerSettings.yaml' => 'the web vault Vaultwarden ships may have changed (see below)',
        'rust-toolchain.toml' => 'the Rust version changed; `str::escape_debug` follows it (NOTICE.md)',
    ];

    public function __construct(
        private Reference $reference,
        private Counterparts $counterparts,
    ) {}

    /**
     * @param  string  $name  What $head is, e.g. "`main`"
     * @param  list<Commit>  $commits  The commits from the pin to $head
     * @param  Changes  $changes  All changes from the pin to $head
     * @param  ?string  $settings  docker/DockerSettings.yaml at $head
     */
    public function render(string $name, string $head, array $commits, Changes $changes, ?string $settings): string
    {
        $intro = sprintf(
            'Vaultwarden %s (%s) is %s ahead of the pinned commit %s (%s).',
            $name, $this->commitLink($head), count($commits) === 1 ? '1 commit' : count($commits).' commits',
            $this->commitLink($this->reference->commit), $this->reference->release,
        );

        $sections = [
            $this->items($changes),
            $this->functions($changes),
            $this->files($changes),
            $this->webVault($settings),
        ];
        $rest = implode("\n\n", array_filter($sections, static fn (string $section): bool => $section !== ''));

        $rows = array_map($this->commitRow(...), $commits);
        $hint = '**Security?** marks titles that suggest a security fix. It is only a hint: Vaultwarden often fixes '
            .'security issues under plain titles, so read every commit.';

        while (true) {
            $table = "| Commit | Title | Changes |\n| --- | --- | --- |\n".implode("\n", $rows);
            if (count($rows) < count($commits)) {
                $table .= sprintf("\n\n%d more commits are left out; run `php artisan parity:upstream` for all of them.", count($commits) - count($rows));
            }

            $body = implode("\n\n", array_filter([$intro, $commits === [] ? '' : $table, $commits === [] ? '' : $hint, $rest]));
            if (strlen($body) <= self::MAX_LENGTH || $rows === []) {
                return $body;
            }

            $rows = array_slice($rows, 0, max(0, count($rows) - 10));
        }
    }

    private function commitRow(Commit $commit): string
    {
        $title = self::text($commit->subject);
        $pullRequest = $commit->pullRequest();
        if ($pullRequest !== null) {
            $title = preg_replace('/\(#\d+\)\s*$/', "([#{$pullRequest}]({$this->reference->webUrl()}/pull/{$pullRequest}))", $title) ?? $title;
        }
        if ($commit->looksLikeSecurityFix()) {
            $title = "**Security?** {$title}";
        }

        $changes = [];
        foreach ($commit->changes->files as $file) {
            $scopes = $commit->changes->scopes($file);
            $shown = array_slice($scopes, 0, 6);
            $changes[] = "`{$file}`".($shown === [] ? '' : ': '.implode(', ', $shown).(count($scopes) > 6 ? ', …' : ''));
        }

        return sprintf('| %s | %s | %s |', $this->commitLink($commit->sha), $title, implode('<br>', $changes));
    }

    private function items(Changes $changes): string
    {
        $rows = [];
        foreach ($changes->files as $file) {
            $lines = array_map(static fn (Hunk $hunk): array => [$hunk->start, $hunk->end], $changes->hunks($file));
            foreach ($this->counterparts->items($file, $changes->functions($file), $lines) as $id) {
                $rows[$id] = sprintf('| `%s` | %s | `%s` |', $id, $this->counterparts->status($id), $file);
            }
        }

        if ($rows === []) {
            return '';
        }

        return "### Parity items whose source changed\n\n"
            ."Their behaviour may have changed. Port the change and run the differential tests against the new commit.\n\n"
            ."| Item | Status | File |\n| --- | --- | --- |\n".implode("\n", $rows);
    }

    private function functions(Changes $changes): string
    {
        $rows = [];
        foreach ($changes->files as $file) {
            foreach ($changes->functions($file) as $function) {
                if ($this->counterparts->items($file, [$function]) !== []) {
                    continue;
                }

                $php = $this->counterparts->php($function);
                $where = $php === [] ? 'not found by name' : implode('<br>', array_map(static fn (string $place): string => "`{$place}`", array_slice($php, 0, 3)))
                    .(count($php) > 3 ? sprintf('<br>and %d more', count($php) - 3) : '');
                $rows[] = sprintf('| `%s` | `%s` | %s |', $file, $function, $where);
            }
        }

        if ($rows === []) {
            return '';
        }

        return "### Other changed functions\n\n"
            ."Our counterparts are found by name (`post_attachment_v2` → `postAttachmentV2`); helpers may be named differently.\n\n"
            ."| File | Function | Our counterpart |\n| --- | --- | --- |\n".implode("\n", $rows);
    }

    private function files(Changes $changes): string
    {
        $lines = [];
        foreach ($changes->files as $file) {
            $hint = self::FILE_HINTS[$file] ?? (str_starts_with($file, 'migrations/')
                ? 'a database migration; run `parity:sync` after moving the pin and check the migrator and the schema snapshots'
                : null);
            if ($hint !== null) {
                $lines[] = "- `{$file}`: {$hint}";
            }
        }

        return $lines === [] ? '' : "### Other files\n\n".implode("\n", $lines);
    }

    private function webVault(?string $settings): string
    {
        if ($settings === null) {
            return '';
        }

        [$version, $digest] = Reference::webVaultIn($settings);
        if ($version === $this->reference->webVault && $digest === $this->reference->webVaultDigest) {
            return "### Web vault\n\nUnchanged: {$version}, as pinned.";
        }

        return sprintf(
            "### Web vault\n\nVaultwarden now ships web vault %s (`%s`); the pin has %s. Moving the pin moves `web_vault`, `web_vault_digest` and the source tags in `web_vault_source` with it.",
            $version ?? 'unknown', $digest ?? 'no digest', $this->reference->webVault,
        );
    }

    private function commitLink(string $sha): string
    {
        return sprintf('[`%s`](%s/commit/%s)', substr($sha, 0, 8), $this->reference->webUrl(), $sha);
    }

    /**
     * Text from upstream for a table cell: no table breaks, and no mentions,
     * which would notify people.
     */
    public static function text(string $text): string
    {
        return str_replace(['|', '@'], ['\|', "@\u{200B}"], $text);
    }
}
