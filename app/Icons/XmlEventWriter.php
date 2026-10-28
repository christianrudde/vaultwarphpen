<?php

namespace App\Icons;

/**
 * xml-rs 1.4's EventWriter (src/writer/emitter.rs) with the configuration
 * of svg-hush: indented with two spaces, but never after text, empty
 * elements closed with "/>", and a default namespace declared only where
 * it changes. Elements and attributes have no prefixes here.
 */
final class XmlEventWriter
{
    private const int WROTE_NOTHING = 0;

    private const int WROTE_MARKUP = 1;

    private const int WROTE_TEXT = 2;

    private string $output = '';

    private int $indentLevel = 0;

    /**
     * @var list<int>
     */
    private array $indentStack = [self::WROTE_NOTHING];

    private bool $justWroteStartElement = false;

    private bool $startDocumentEmitted = false;

    /**
     * The default namespace each open element declares, if any.
     *
     * @var list<string|null>
     */
    private array $namespaces = [];

    public function output(): string
    {
        return $this->output;
    }

    public function startDocument(string $version, string $encoding = 'utf-8'): void
    {
        $this->startDocumentEmitted = true;
        $this->beforeMarkup();
        $this->output .= "<?xml version=\"{$version}\" encoding=\"{$encoding}\"?>";
        $this->afterMarkup();
    }

    /**
     * @param  list<array{string, string}>  $attributes  Names and values
     */
    public function startElement(string $name, string $defaultNamespace, array $attributes): void
    {
        // NamespaceStack::put_checked: only declared where no ancestor has it already.
        $this->namespaces[] = in_array($defaultNamespace, $this->namespaces, true) ? null : $defaultNamespace;

        $this->checkDocumentStarted();
        $this->fixNonEmptyElement();
        $this->beforeMarkup();
        $this->indentStack[] = self::WROTE_NOTHING;
        $this->output .= "<{$name}";
        $declared = $this->namespaces[count($this->namespaces) - 1];
        if ($declared !== null && $declared !== '') {
            $this->output .= ' xmlns="'.self::escapeAttribute($declared).'"';
        }
        foreach ($attributes as [$attribute, $value]) {
            $this->output .= " {$attribute}=\"".self::escapeAttribute($value).'"';
        }
        $this->afterMarkup();
        $this->indentLevel++;
        $this->justWroteStartElement = true;
    }

    public function endElement(string $name): void
    {
        if ($this->justWroteStartElement) {
            $this->justWroteStartElement = false;
            $this->output .= '/>';
        } else {
            if ($this->indentLevel > 0 && $this->wrote(self::WROTE_MARKUP)) {
                $this->writeNewline($this->indentLevel - 1);
            }
            $this->output .= "</{$name}>";
        }
        $this->afterEndElement();
        array_pop($this->namespaces);
    }

    public function characters(string $content): void
    {
        $this->checkDocumentStarted();
        $this->fixNonEmptyElement();
        $this->output .= strtr($content, ['<' => '&lt;', '>' => '&gt;', '&' => '&amp;']);
        $this->setTop(self::WROTE_TEXT);
    }

    private function checkDocumentStarted(): void
    {
        if (! $this->startDocumentEmitted) {
            $this->startDocument('1.0', 'UTF-8');
        }
    }

    private function fixNonEmptyElement(): void
    {
        if ($this->justWroteStartElement) {
            $this->justWroteStartElement = false;
            $this->output .= '>';
        }
    }

    private function wrote(int $flag): bool
    {
        return $this->indentStack !== [] && $this->indentStack[count($this->indentStack) - 1] === $flag;
    }

    private function setTop(int $flag): void
    {
        if ($this->indentStack !== []) {
            array_pop($this->indentStack);
            $this->indentStack[] = $flag;
        }
    }

    private function writeNewline(int $level): void
    {
        $this->output .= "\n".str_repeat('  ', $level);
    }

    private function beforeMarkup(): void
    {
        if (! $this->wrote(self::WROTE_TEXT) && ($this->indentLevel > 0 || $this->wrote(self::WROTE_MARKUP))) {
            $this->writeNewline($this->indentLevel);
            if ($this->indentLevel > 0) {
                $this->afterMarkup();
            }
        }
    }

    private function afterMarkup(): void
    {
        $this->setTop(self::WROTE_MARKUP);
    }

    private function afterEndElement(): void
    {
        if ($this->indentLevel > 0) {
            $this->indentLevel--;
            array_pop($this->indentStack);
        }
        $this->setTop(self::WROTE_MARKUP);
    }

    private static function escapeAttribute(string $value): string
    {
        return strtr($value, ['<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&apos;', '&' => '&amp;', "\n" => '&#xA;', "\r" => '&#xD;']);
    }
}
