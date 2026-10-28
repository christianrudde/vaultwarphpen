<?php

namespace App\Icons;

/**
 * FaviconEmitter of src/api/icons.rs: an html5gum emitter that keeps only
 * what the icon search needs. It builds tags with their attributes (link,
 * base and head only, the first of duplicate attributes wins) and hands out
 * a token for a link whose rel contains "icon" but not "mask-icon", a base
 * with an href, and the end tag of head. Strings, comments and doctypes are
 * dropped.
 *
 * Tokens are popped by the tokenizer between two steps of its state
 * machine, so a base tag may leave before its last attributes, which then
 * stay pending for the next tag, as in Vaultwarden.
 */
final class FaviconEmitter
{
    /**
     * @var array{name: string, attributes: array<array-key, string>, closing: bool}|null
     */
    private ?array $currentToken = null;

    private string $lastStartTag = '';

    /**
     * @var array{string, string}|null The name and the value
     */
    private ?array $currentAttribute = null;

    private bool $emitToken = false;

    /**
     * @return array{name: string, attributes: array<array-key, string>, closing: bool}|null
     */
    public function popToken(): ?array
    {
        if (! $this->emitToken) {
            return null;
        }
        $this->emitToken = false;
        $token = $this->currentToken;
        $this->currentToken = null;

        return $token;
    }

    public function initStartTag(): void
    {
        $this->currentToken = ['name' => '', 'attributes' => [], 'closing' => false];
    }

    public function initEndTag(): void
    {
        $this->currentToken = ['name' => '', 'attributes' => [], 'closing' => true];
    }

    /**
     * @return string|null The state html5gum::naive_next_state picks after a start tag
     */
    public function emitCurrentTag(): ?string
    {
        $this->flushCurrentAttribute(true);
        $this->lastStartTag = '';
        if ($this->currentToken !== null && ! $this->currentToken['closing']) {
            $this->lastStartTag = $this->currentToken['name'];
        }

        return match ($this->lastStartTag) {
            'textarea', 'title' => HtmlTokenizer::RC_DATA,
            'plaintext' => HtmlTokenizer::PLAIN_TEXT,
            'script' => HtmlTokenizer::SCRIPT_DATA,
            'style', 'iframe', 'xmp', 'noembed', 'noframe', 'noscript' => HtmlTokenizer::RAW_TEXT,
            default => null,
        };
    }

    public function pushTagName(string $bytes): void
    {
        if ($this->currentToken !== null) {
            $this->currentToken['name'] .= $bytes;
        }
    }

    public function initAttribute(): void
    {
        $this->flushCurrentAttribute(false);
        $this->currentAttribute = $this->currentToken !== null && in_array($this->currentToken['name'], ['link', 'head', 'base'], true)
            ? ['', '']
            : null;
    }

    public function pushAttributeName(string $bytes): void
    {
        if ($this->currentAttribute !== null) {
            $this->currentAttribute[0] .= $bytes;
        }
    }

    public function pushAttributeValue(string $bytes): void
    {
        if ($this->currentAttribute !== null) {
            $this->currentAttribute[1] .= $bytes;
        }
    }

    public function currentIsAppropriateEndTagToken(): bool
    {
        return $this->currentToken !== null && $this->currentToken['closing']
            && $this->lastStartTag !== '' && $this->lastStartTag === $this->currentToken['name'];
    }

    private function flushCurrentAttribute(bool $emitCurrentTag): void
    {
        if ($this->currentToken === null) {
            return;
        }
        $name = $this->currentToken['name'];

        if ($this->currentAttribute !== null && ($name === 'base' || $name === 'link')) {
            [$key, $value] = $this->currentAttribute;
            $this->currentAttribute = null;
            if (! array_key_exists($key, $this->currentToken['attributes'])) {
                $this->currentToken['attributes'][$key] = $value;
            }
        }

        $attributes = $this->currentToken['attributes'];
        if ($name === 'head' && $this->currentToken['closing']) {
            $this->emitToken = true;
        } elseif ($name === 'base' && array_key_exists('href', $attributes)) {
            $this->emitToken = true;
        } elseif ($name === 'link' && $emitCurrentTag && array_key_exists('rel', $attributes) && array_key_exists('href', $attributes)) {
            $rel = mb_check_encoding($attributes['rel'], 'UTF-8') ? $attributes['rel'] : '';
            if (str_contains($rel, 'icon') && ! str_contains($rel, 'mask-icon')) {
                $this->emitToken = true;
            }
        }
    }
}
