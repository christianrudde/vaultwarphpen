<?php

namespace Tests\Unit\Support;

use App\Support\DataUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The data-url crate 0.3.2: WHATWG data: URL processing and forgiving base64.
 */
class DataUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?string, ?string}>
     */
    public static function urls(): iterable
    {
        yield 'plain' => ['data:,Hello%2C%20World', 'text', 'plain', 'Hello, World'];
        yield 'base64' => ['data:image/png;base64,iVBORw0KGgo=', 'image', 'png', "\x89PNG\r\n\x1A\n"];
        yield 'case and spaces' => ["  DaTa:Image/PNG ; BASE64,iVBO\nRw0KGgo #frag", 'image', 'png', "\x89PNG\r\n\x1A\n"];
        yield 'padding is optional' => ['data:image/gif;base64,R0lGODlh', 'image', 'gif', 'GIF89a'];
        yield 'bad padding' => ['data:image/gif;base64,R0lGODl=h', 'image', 'gif', null];
        yield 'a lone symbol' => ['data:image/gif;base64,R0lGO', 'image', 'gif', null];
        yield 'not base64' => ['data:image/gif;base64,R0l*', 'image', 'gif', null];
        yield 'percent escapes in base64' => ['data:;base64,R0lG%4fDlh', 'text', 'plain', 'GIF89a'];
        yield 'broken percent escapes stay' => ['data:,%4%41%', 'text', 'plain', '%4A%'];
        yield 'an invalid type' => ['data:image,x', 'text', 'plain', 'x'];
        yield 'parameters' => ['data:image/svg+xml;charset=utf-8,<svg/>', 'image', 'svg+xml', '<svg/>'];
        yield 'no comma' => ['data:image/png;base64', null, null, null];
        yield 'a comma after the fragment' => ['data:image/png#,x', null, null, null];
        yield 'not data' => ['http://example.com/,x', null, null, null];
    }

    #[DataProvider('urls')]
    public function test_data_urls(string $input, ?string $type, ?string $subtype, ?string $body): void
    {
        $url = DataUrl::process($input);

        $this->assertSame($type, $url?->type);
        $this->assertSame($subtype, $url?->subtype);
        $this->assertSame($body, $url?->decode());
    }
}
