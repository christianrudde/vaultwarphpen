<?php

namespace Tests\Unit\Icons;

use App\Icons\SvgFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * svg-hush 0.9.7 as icons.rs uses it, with its own tests (urlfunc, css,
 * url_filter) and the output of xml-rs's writer.
 */
class SvgFilterTest extends TestCase
{
    /**
     * @return iterable<array{string, string}>
     */
    public static function urlFunctions(): iterable
    {
        // fn urlfunc
        yield ['hello, url(world)', 'hello, url(world)'];
        yield ['hello, world', 'hello, world'];
        yield ['hello, url(http://evil.com)', 'hello, url(#)'];
        yield ['hello, url( http://evil.com )', 'hello, url(#)'];
        yield ['hello, url( //evil.com )', 'hello, url(#)'];
        yield ['url( /okay ), (), bye', 'url(/okay), (), bye'];
        yield ['hello, url( unclosed', 'hello, url()'];
        yield ['hello, url( ) url(    ) bork', 'hello, url() url() bork'];
        yield ["hello, url('1' )url(  2  ) bork", 'hello, url(1)url(2) bork'];
        yield ["hello, url('(' )", 'hello, url()'];
        yield ['aurl(burl(', 'aurl()'];
        yield ['uurl()rl(', 'uurl()rl('];
        // fn css
        yield ['ok:url(data:text/plain;base64,AAA)', 'ok:url(#)'];
        yield ['color: red; background: URL(X); huh', 'color: red; background: URL(X); huh'];
        yield ["@import 'foo'; FONT-size: 1em;", ' FONT-size: 1em;'];
        yield ['u;url(data:x);rl(hack)', 'u;url(#);rl(hack)'];
        yield ['u;\;rl(hack)', 'u;rl(hack)'];
        yield ['u;\,rl(hack)', 'u;rl(hack)'];
        yield ['u;\url(hack)', 'u;hack)'];
        yield ["font-size: 1em; @Import 'foo';", 'font-size: 1em;'];
        yield ["@\\69MporT 'foo';", ''];
        yield ['color: red; background: URL( url(); huh)', 'color: red; background: URL(); huh)'];
        yield ['color: red; background: URL(//x/rel);', 'color: red; background: URL(/rel);'];
        yield ['color: red; background: URL(data:xx);', 'color: red; background: URL(#);'];
        yield ['color: red; background: UR\L(x); border: blue', 'color: red;x); border: blue'];
        yield ['prop: url (it is not);', 'prop: url (it is not);'];
        yield ['sel, sel\2 { bg: url(data:x); ba\d; }', 'sel,{ bg: url(#); }'];
        // fn url_filter, through url().
        yield ['url(http://test.com/a.jpg)', 'url(/a.jpg)'];
        yield ['url(https://test.com:123/.././a/b/c.jpg)', 'url(/a/b/c.jpg)'];
        yield ['url(./x/)', 'url(x/)'];
        yield ['url(?q)', 'url(?q)'];
        yield ['url(//host/%2fpath)', 'url(/%2fpath)'];
        yield ['url(//host///path)', 'url(#)'];
        // Up to the first ")" after the next "(".
        yield ['url(javascript:alert(1))', 'url(1))'];
        yield ['url(data:image/png;base64,AAAA)', 'url(data:image/png;base64,AAAA)'];
    }

    #[DataProvider('urlFunctions')]
    public function test_url_functions(string $css, string $filtered): void
    {
        $this->assertSame($filtered, SvgFilter::filteredUrlFunc($css));
    }

    public function test_a_document(): void
    {
        $svg = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>',
            '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">',
            '<!-- A comment -->',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 32 32" onload="alert(1)" data-x="1" aria-label=" Logo ">',
            '<script>alert(1)</script>',
            '<style><![CDATA[ .a { fill: url(https://evil.example/x.svg#g) } @import url(x.css); ]]></style>',
            '<defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs>',
            '<a href="https://example.com/"><use xlink:href="#g" href="https://evil.example/#b"/></a>',
            '<image href="data:image/png;base64,AAAA" x="1"/><image href="data:text/html,x"/>',
            '<g fill="url(#g)" xml:space="preserve"><title>T &amp; &lt;&gt; "q"</title></g>',
            '<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">Hi</div></foreignObject>',
            "<path d=\"M0 0\n\tL32 32\" class=\"a\"/>",
            '</svg>',
        ]);

        // Text, whitespace too, stops the indentation until the element ends.
        $this->assertSame(implode("\n", [
            '<?xml version="1.0" encoding="utf-8"?>',
            '<svg xmlns="http://www.w3.org/2000/svg" aria-label="Logo" data-x="1" viewBox="0 0 32 32">',
            '',
            '<style> .a { fill: url(/x.svg#g) }x.css); </style>',
            '<defs>',
            '    <linearGradient id="g">',
            '      <stop offset="0" stop-color="#fff"/>',
            '    </linearGradient>',
            '  </defs>',
            '<a>',
            '    <use href="#g"/>',
            '  </a>',
            '<image href="data:image/png;base64,AAAA" x="1"/>',
            '  <image/>',
            '<g fill="url(#g)">',
            '    <title>T &amp; &lt;&gt; "q"</title>',
            '  </g>',
            '',
            '<path class="a" d="M0 0  L32 32"/>',
            '</svg>',
        ]), SvgFilter::filter($svg));
    }

    public function test_indentation_without_text(): void
    {
        $this->assertSame(
            "<?xml version=\"1.1\" encoding=\"utf-8\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\">\n  <g>\n    <rect/>\n  </g>\n  <rect/>\n</svg>",
            SvgFilter::filter('<?xml version="1.1"?><svg xmlns="http://www.w3.org/2000/svg"><g><rect/></g><rect></rect></svg>'),
        );
        // Prefixes are stripped, which leaves the SVG namespace undeclared.
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<svg>\n  <rect/>\n</svg>",
            SvgFilter::filter('<s:svg xmlns:s="http://www.w3.org/2000/svg"><s:rect/></s:svg>'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'not SVG' => ['<html xmlns="http://www.w3.org/1999/xhtml"/>'];
        yield 'no namespace' => ['<svg/>'];
        yield 'not well-formed' => ['<svg xmlns="http://www.w3.org/2000/svg"><g></svg>'];
        yield 'an unbound prefix' => ['<svg xmlns="http://www.w3.org/2000/svg"><x:g/></svg>'];
        yield 'an unknown entity' => ['<svg xmlns="http://www.w3.org/2000/svg">&nbsp;</svg>'];
        yield 'an external entity' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'];
        yield 'an encoding xml-rs does not know' => ['<?xml version="1.0" encoding="windows-1252"?><svg xmlns="http://www.w3.org/2000/svg"/>'];
        yield 'a version xml-rs does not know' => ['<?xml version="2.0"?><svg xmlns="http://www.w3.org/2000/svg"/>'];
        yield 'two root elements' => ['<svg xmlns="http://www.w3.org/2000/svg"/><svg xmlns="http://www.w3.org/2000/svg"/>'];
        yield 'empty' => [''];
    }

    #[DataProvider('rejected')]
    public function test_rejected_documents(string $svg): void
    {
        $this->assertNull(SvgFilter::filter($svg));
    }

    public function test_internal_entities(): void
    {
        $this->assertSame(
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\">\n  <title>Ünïcödé</title>\n</svg>",
            SvgFilter::filter('<!DOCTYPE svg [<!ENTITY ns "http://www.w3.org/2000/svg"><!ENTITY t "Ünïcödé">]><svg xmlns="&ns;"><title>&t;</title></svg>'),
        );
    }
}
