<?php

namespace App\Parity\Upstream;

/**
 * One changed place in a file: the lines it replaces on the old side and the
 * Rust items it lies in or changes, as git's rust diff driver finds them.
 */
final readonly class Hunk
{
    /**
     * @param  int  $start  First line on the old side
     * @param  int  $end  Last line on the old side; for pure additions the line they follow
     * @param  ?string  $scope  The enclosing item, e.g. "fn post_attachment_v2" or "struct AttachmentData"
     * @param  list<string>  $functions  The enclosing function and the functions the changed lines define
     */
    public function __construct(
        public string $file,
        public int $start,
        public int $end,
        public ?string $scope,
        public array $functions,
    ) {}
}
