<?php

namespace App\Icons;

use App\Support\DataUrl;
use Uri\WhatWg\Url;
use XMLReader;

/**
 * svg-hush 0.9.7 as icons.rs configures it (Filter::new with the
 * allow_standard_images filter for data: URLs): only SVG elements and
 * attributes known to be harmless pass, URLs become relative, CSS loses
 * url()s to elsewhere. The result is written like xml-rs 1.4's EventWriter
 * with svg-hush's settings: an XML declaration, two-space indentation where
 * no text is in the way, prefixes stripped, attributes sorted.
 *
 * libxml2 reads the input. Like xml-rs it is a strict, non-validating
 * parser that normalizes whitespace in attribute values; svg-hush rejects
 * whatever xml-rs cannot read.
 */
final class SvgFilter
{
    private const string SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    private const string XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';

    private const string XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';

    private const array ALLOWED_ELEMENTS = [
        'a', 'altGlyph', 'altGlyphDef', 'altGlyphItem', 'animate', 'animateColor', 'animateMotion', 'animateTransform',
        'circle', 'clipPath', 'color-profile', 'defs', 'desc', 'ellipse', 'feBlend', 'feColorMatrix', 'feComponentTransfer',
        'feComposite', 'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight', 'feDropShadow',
        'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode',
        'feMorphology', 'feOffset', 'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence', 'filter',
        'flowDiv', 'flowLine', 'flowPara', 'flowRegion', 'flowRoot', 'flowSpan', 'flowTref', 'font', 'g', 'glyph', 'glyphRef',
        'hkern', 'image', 'line', 'linearGradient', 'marker', 'mask', 'meshgradient', 'meshpatch', 'meshrow', 'mpath', 'mPath',
        'path', 'pattern', 'polygon', 'polyline', 'radialGradient', 'rect', 'set', 'solidColor', 'stop', 'svg', 'switch',
        'symbol', 'text', 'textPath', 'title', 'tref', 'tspan', 'use', 'view', 'vkern',
    ];

    /**
     * Unicode White_Space, which Rust's str::trim and char::is_whitespace go by.
     */
    private const string WHITESPACE = '\x{9}-\x{d}\x{20}\x{85}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}';

    private readonly XmlEventWriter $writer;

    /**
     * How deep inside a dropped element the reader is.
     */
    private int $skipping = 0;

    /**
     * The text of a style element, filtered when the element ends.
     */
    private ?string $css = null;

    private bool $emittedAnyElement = false;

    private function __construct()
    {
        $this->writer = new XmlEventWriter;
    }

    /**
     * Filter::filter: the filtered SVG, or null when it cannot be read or has no element left.
     */
    public static function filter(string $svg): ?string
    {
        $version = '1.0';
        if (preg_match('/^(?:\xEF\xBB\xBF)?<\?xml[\s?]/', $svg) === 1) {
            $declaration = '/^(?:\xEF\xBB\xBF)?<\?xml\s+version\s*=\s*(["\'])(1\.[01])\1'
                .'(?:\s+encoding\s*=\s*(["\'])([A-Za-z][A-Za-z0-9._-]*)\3)?(?:\s+standalone\s*=\s*(["\'])(?:yes|no)\5)?\s*\?>/';
            if (preg_match($declaration, $svg, $match) !== 1) {
                return null;
            }
            $version = $match[2];
            // The encodings xml-rs knows.
            $encoding = strtolower($match[4] ?? '');
            if ($encoding !== '' && ! in_array($encoding, ['utf-8', 'utf8', 'iso-8859-1', 'latin1', 'utf-16', 'utf16', 'ascii', 'us-ascii'], true)) {
                return null;
            }
        }

        $filter = new self;
        $filter->writer->startDocument($version);

        $useErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        // Entities declared in the document are expanded, but nothing is loaded from elsewhere.
        $loader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static fn (): null => null);
        try {
            $reader = XMLReader::fromString($svg, null, LIBXML_NOENT | LIBXML_NONET);
            $defaultNamespaces = [''];
            while ($reader->read()) {
                switch ($reader->nodeType) {
                    case XMLReader::ELEMENT:
                        $defaultNamespace = $defaultNamespaces[count($defaultNamespaces) - 1];
                        $attributes = [];
                        while ($reader->moveToNextAttribute()) {
                            if ($reader->namespaceURI === self::XMLNS_NAMESPACE) {
                                if ($reader->prefix === '') {
                                    $defaultNamespace = $reader->value;
                                }

                                continue;
                            }
                            $attributes[] = [$reader->localName, $reader->namespaceURI === '' ? null : $reader->namespaceURI, $reader->value];
                        }
                        $reader->moveToElement();
                        if (count($attributes) > 200) {
                            return null;
                        }
                        $filter->startElement($reader->localName, $reader->namespaceURI, $defaultNamespace, $attributes);
                        if ($reader->isEmptyElement) {
                            $filter->endElement($reader->localName);
                        } else {
                            $defaultNamespaces[] = $defaultNamespace;
                        }
                        break;
                    case XMLReader::END_ELEMENT:
                        array_pop($defaultNamespaces);
                        $filter->endElement($reader->localName);
                        break;
                    case XMLReader::TEXT:
                    case XMLReader::CDATA:
                    case XMLReader::WHITESPACE:
                    case XMLReader::SIGNIFICANT_WHITESPACE:
                        // Whitespace outside the root element is ignored.
                        if (count($defaultNamespaces) > 1) {
                            $filter->characters($reader->value);
                        }
                        break;
                    case XMLReader::ENTITY_REF:
                        return null;
                }
            }
            $reader->close();

            // xml-rs stops at the first error, and svg-hush with it.
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    return null;
                }
            }

            return $filter->emittedAnyElement ? $filter->writer->output() : null;
        } catch (\ValueError) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useErrors);
            libxml_set_external_entity_loader($loader);
        }
    }

    /**
     * @param  list<array{string, ?string, string}>  $attributes  Local names, namespaces and values
     */
    private function startElement(string $name, string $namespace, string $defaultNamespace, array $attributes): void
    {
        if ($this->skipping > 0) {
            $this->skipping++;

            return;
        }
        $isSvg = $namespace === self::SVG_NAMESPACE;
        if ($isSvg && $name === 'style' && $this->css === null) {
            $this->css = '';
        } elseif (! $isSvg || ! in_array($name, self::ALLOWED_ELEMENTS, true)) {
            $this->skipping++;

            return;
        }

        $keep = [];
        // xlink to href conversion could cause a duplicate if the element had both.
        $seenHref = false;
        foreach ($attributes as [$attribute, $attributeNamespace, $value]) {
            if ($attribute === 'href') {
                if ($seenHref) {
                    continue;
                }
                $seenHref = true;
            }
            $filtered = self::filterAttribute($attribute, $attributeNamespace, $value, $name);
            if ($filtered !== null) {
                $keep[] = [$attribute, $filtered];
            }
        }
        usort($keep, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        $this->emittedAnyElement = true;
        $this->writer->startElement($name, $defaultNamespace, $keep);
    }

    private function endElement(string $name): void
    {
        if ($this->skipping > 0) {
            $this->skipping--;

            return;
        }
        if ($this->css !== null) {
            $this->writer->characters(self::filteredUrlFunc($this->css));
            $this->css = null;
        }
        $this->writer->endElement($name);
    }

    private function characters(string $text): void
    {
        if ($this->skipping > 0) {
            return;
        }
        if ($this->css !== null) {
            $this->css .= $text;
            $this->writer->characters('');
        } else {
            $this->writer->characters($text);
        }
    }

    /**
     * Filter::filter_attribute: the value to write, or null to drop the attribute.
     */
    private static function filterAttribute(string $name, ?string $namespace, string $value, string $element): ?string
    {
        // Only the deprecated xlink:href may have a namespace, and becomes a plain href.
        if ($namespace !== null && ($namespace !== self::XLINK_NAMESPACE || $name !== 'href')) {
            return null;
        }
        // Linking to same-origin images and gradients is fine (the URL is filtered).
        if ($name === 'href' && ! in_array($element, ['radialGradient', 'linearGradient', 'image', 'use', 'pattern', 'feImage'], true)) {
            return null;
        }

        $type = SvgAttributes::TYPES[$name] ?? null;
        if ($type === SvgAttributes::TEXT) {
            return $value;
        }
        if ($type === null) {
            if (str_starts_with($name, 'aria-')) {
                $type = SvgAttributes::ANY_ASCII;
            } elseif (str_starts_with($name, 'data-')) {
                return $value;
            } else {
                return null;
            }
        }

        // Code is ASCII: anything else is stripped, so that what is filtered is what browsers see.
        $trimmed = self::trim($value);
        $cleaned = preg_match('/^[\x01-\x7F]*$/D', $trimmed) === 1
            ? $trimmed
            : (string) preg_replace('/[^\x01-\x7F]/u', '', (string) preg_replace('/['.self::WHITESPACE.']/u', ' ', $value));

        return match ($type) {
            SvgAttributes::ANY_ASCII, SvgAttributes::KEYWORD, SvgAttributes::NUMBER => $cleaned,
            // Deprecated SMIL must not animate to a banned attribute.
            SvgAttributes::ATTRIBUTE_NAME => in_array(SvgAttributes::TYPES[self::trim($value)] ?? null,
                [SvgAttributes::ANY_ASCII, SvgAttributes::KEYWORD, SvgAttributes::NUMBER, SvgAttributes::TEXT], true) ? $cleaned : null,
            SvgAttributes::URL => self::filterUrl($cleaned),
            SvgAttributes::URL_FUNC, SvgAttributes::STYLE_SHEET => self::filteredUrlFunc($cleaned),
        };
    }

    /**
     * Filter::filter_url: a relative URL, since the host is thrown away, or a data: URL of a plain image.
     */
    private static function filterUrl(string $url): ?string
    {
        $base = Url::parse('https://127.0.0.1/__relpath_prefix__/');
        $parsed = $base === null ? null : Url::parse($url, $base);
        if ($parsed === null) {
            return null;
        }
        $scheme = $parsed->getScheme();
        if ($scheme === 'data') {
            $data = DataUrl::process($url);

            return $data !== null && $data->type === 'image' && in_array($data->subtype, ['jpeg', 'png', 'gif'], true) ? $url : null;
        }
        // cannot_be_a_base: an opaque path, such as javascript:.
        if (! in_array($scheme, ['http', 'https', 'ws', 'wss', 'ftp', 'file'], true)
            && ! str_starts_with(substr($parsed->toAsciiString(), strlen($scheme) + 1), '/')) {
            return null;
        }

        $path = $parsed->getPath();
        $relative = str_starts_with($path, '/__relpath_prefix__/') ? substr($path, strlen('/__relpath_prefix__/')) : $path;
        if (str_starts_with(self::trimStart($relative), '//')) {
            // The path became a scheme-relative URL.
            return null;
        }
        $relative = str_replace(':', '%3a', $relative);
        $query = $parsed->getQuery();
        if ($query !== null) {
            $relative .= "?{$query}";
        }
        if ($relative === '/') {
            return null;
        }
        $fragment = $parsed->getFragment();
        if ($fragment !== null) {
            $relative .= "#{$fragment}";
        }

        return str_replace('(', '%28', $relative);
    }

    /**
     * Filter::filtered_url_func: every url() that could lead elsewhere becomes
     * url(#), and whatever looks tricky to parse (escapes, @import) is dropped.
     */
    public static function filteredUrlFunc(string $cssOrSvg): string
    {
        $out = '';
        $insideUrl = false;
        foreach (self::splitInclusive($cssOrSvg, '(') as $chunk) {
            if ($insideUrl) {
                $close = strpos($chunk, ')');
                if ($close === false) {
                    continue;
                }
                $url = self::trim(trim(self::trim(substr($chunk, 0, $close)), '"\''));
                $chunk = substr($chunk, $close + 1);

                $filtered = self::filterUrl($url);
                if ($filtered !== null && strpbrk($filtered, '()\'"\\ ') !== false) {
                    $filtered = null;
                }
                // The url(#) must stay to keep the syntax valid.
                $out .= ($filtered ?? '#').')';
                $insideUrl = false;
            }

            foreach (self::splitInclusive($chunk, ';{},') as $part) {
                if (str_contains($part, '\\') || (str_contains($part, '@') && str_contains(strtolower($part), '@import'))) {
                    $last = substr($part, -1);
                    if ($last === '{' || $last === '}') {
                        $out .= $last;
                    }

                    continue;
                }
                $out .= $part;
                // url() allows no whitespace before the parenthesis.
                $insideUrl = strlen($part) >= 4 && strcasecmp(substr($part, -4), 'url(') === 0;
            }
        }

        return $insideUrl ? "{$out})" : $out;
    }

    /**
     * str::split_inclusive: pieces that each end with one of the separators, except perhaps the last.
     *
     * @return list<string>
     */
    private static function splitInclusive(string $string, string $separators): array
    {
        return preg_split('/(?<=['.preg_quote($separators, '/').'])/', $string, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function trim(string $string): string
    {
        return (string) preg_replace('/^['.self::WHITESPACE.']+|['.self::WHITESPACE.']+$/Du', '', $string);
    }

    private static function trimStart(string $string): string
    {
        return (string) preg_replace('/^['.self::WHITESPACE.']+/u', '', $string);
    }
}
