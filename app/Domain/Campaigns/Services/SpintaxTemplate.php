<?php

namespace App\Domain\Campaigns\Services;

use InvalidArgumentException;

class SpintaxTemplate
{
    private const MAX_NESTING_DEPTH = 20;

    private const MAX_VARIATION_DELIMITERS = 256;

    /**
     * Render standard spintax such as {Hello|Hi} with choices that remain
     * stable for a given seed. Nested groups and escaped delimiters are
     * supported.
     */
    public function render(string $template, string $seed): string
    {
        if (! $this->hasVariations($template)) {
            return $this->unescapeLiterals($template);
        }

        $offset = 0;
        $groupIndex = 0;
        $nodes = $this->parseSequence($template, $offset, $groupIndex, false, 0);

        return $this->renderNodes($nodes, $seed);
    }

    public function validate(string $template): void
    {
        if (! $this->hasVariations($template)) {
            return;
        }

        $offset = 0;
        $groupIndex = 0;
        $this->parseSequence($template, $offset, $groupIndex, false, 0);
    }

    /** @return list<SpintaxNode> */
    private function parseSequence(
        string $template,
        int &$offset,
        int &$groupIndex,
        bool $insideGroup,
        int $depth,
    ): array {
        $nodes = [];
        $text = '';
        $length = strlen($template);

        while ($offset < $length) {
            $character = $template[$offset];

            if ($character === '\\') {
                $next = $offset + 1 < $length ? $template[$offset + 1] : null;

                if ($next !== null && in_array($next, ['\\', '{', '}', '|'], true)) {
                    $text .= $next;
                    $offset += 2;

                    continue;
                }

                $text .= $character;
                $offset++;

                continue;
            }

            $mergeVariable = $this->mergeVariableAt($template, $offset);

            if ($mergeVariable !== null) {
                $text .= $mergeVariable;
                $offset += strlen($mergeVariable);

                continue;
            }

            if ($character === '{') {
                if (! $this->looksLikeVariation($template, $offset)) {
                    $literalEnd = $this->matchingBraceEnd($template, $offset);

                    if ($literalEnd !== null) {
                        $text .= substr($template, $offset, $literalEnd + 1 - $offset);
                        $offset = $literalEnd + 1;

                        continue;
                    }

                    $text .= $character;
                    $offset++;

                    continue;
                }

                if ($depth >= self::MAX_NESTING_DEPTH) {
                    throw new InvalidArgumentException(
                        'Variations cannot be nested more than '.self::MAX_NESTING_DEPTH.' levels.',
                    );
                }

                $this->appendText($nodes, $text);
                $text = '';
                $offset++;
                $currentIndex = $groupIndex++;
                $options = [];

                while (true) {
                    $option = $this->parseSequence($template, $offset, $groupIndex, true, $depth + 1);

                    if (! $this->hasContent($option)) {
                        throw new InvalidArgumentException('Variation options cannot be empty.');
                    }

                    $options[] = $option;

                    if ($offset >= $length) {
                        throw new InvalidArgumentException('Unclosed variation. Add a closing } or escape the opening brace as \\{.');
                    }

                    if ($template[$offset] === '|') {
                        $offset++;

                        continue;
                    }

                    $offset++;

                    break;
                }

                if (count($options) < 2) {
                    throw new InvalidArgumentException('A variation needs at least two options separated by |.');
                }

                $nodes[] = SpintaxNode::group($currentIndex, $options);

                continue;
            }

            if ($insideGroup && ($character === '|' || $character === '}')) {
                break;
            }

            $text .= $character;
            $offset++;
        }

        $this->appendText($nodes, $text);

        return $nodes;
    }

    /**
     * @param  list<SpintaxNode>  $nodes
     */
    private function renderNodes(array $nodes, string $seed): string
    {
        $rendered = '';

        foreach ($nodes as $node) {
            if ($node->text !== null) {
                $rendered .= $node->text;

                continue;
            }

            $options = $node->options;
            $choice = $this->choice($seed, $node->index ?? 0, count($options));
            $rendered .= $this->renderNodes($options[$choice], $seed);
        }

        return $rendered;
    }

    /**
     * @param  list<SpintaxNode>  $nodes
     */
    private function hasContent(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node->options !== [] || trim((string) $node->text) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<SpintaxNode>  $nodes
     */
    private function appendText(array &$nodes, string $text): void
    {
        if ($text !== '') {
            $nodes[] = SpintaxNode::text($text);
        }
    }

    private function choice(string $seed, int $groupIndex, int $optionCount): int
    {
        $bytes = hash('sha256', $seed.'|'.$groupIndex, true);
        $number = unpack('Nvalue', substr($bytes, 0, 4));

        return ((int) ($number['value'] ?? 0)) % $optionCount;
    }

    private function mergeVariableAt(string $template, int $offset): ?string
    {
        if ($template[$offset] !== '{') {
            return null;
        }

        if (! preg_match('/\G\{\{\s*\w+\s*\}\}/', $template, $matches, 0, $offset)) {
            return null;
        }

        return $matches[0];
    }

    private function hasVariations(string $template): bool
    {
        $delimiters = 0;
        $hasOpeningBrace = false;
        $hasSeparator = false;
        $length = strlen($template);

        for ($cursor = 0; $cursor < $length; $cursor++) {
            if ($template[$cursor] === '\\') {
                $cursor++;

                continue;
            }

            $mergeVariable = $this->mergeVariableAt($template, $cursor);

            if ($mergeVariable !== null) {
                $cursor += strlen($mergeVariable) - 1;

                continue;
            }

            if (! in_array($template[$cursor], ['{', '}', '|'], true)) {
                continue;
            }

            $delimiters++;
            $hasOpeningBrace = $hasOpeningBrace || $template[$cursor] === '{';
            $hasSeparator = $hasSeparator || $template[$cursor] === '|';
        }

        if ($hasOpeningBrace && $hasSeparator && $delimiters > self::MAX_VARIATION_DELIMITERS) {
            throw new InvalidArgumentException(
                'Templates cannot contain more than '.self::MAX_VARIATION_DELIMITERS.' variation delimiters.',
            );
        }

        return $hasOpeningBrace && $hasSeparator;
    }

    private function unescapeLiterals(string $template): string
    {
        return preg_replace('/\\\\([\\\\{}|])/', '$1', $template) ?? $template;
    }

    private function looksLikeVariation(string $template, int $offset): bool
    {
        $depth = 1;
        $hasTopLevelSeparator = false;
        $length = strlen($template);

        for ($cursor = $offset + 1; $cursor < $length; $cursor++) {
            if ($template[$cursor] === '\\') {
                $cursor++;

                continue;
            }

            $mergeVariable = $this->mergeVariableAt($template, $cursor);

            if ($mergeVariable !== null) {
                $cursor += strlen($mergeVariable) - 1;

                continue;
            }

            if ($template[$cursor] === '{') {
                $depth++;
            } elseif ($template[$cursor] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $hasTopLevelSeparator;
                }
            } elseif ($template[$cursor] === '|' && $depth === 1) {
                $hasTopLevelSeparator = true;
            }
        }

        return $hasTopLevelSeparator;
    }

    private function matchingBraceEnd(string $template, int $offset): ?int
    {
        $depth = 1;
        $length = strlen($template);

        for ($cursor = $offset + 1; $cursor < $length; $cursor++) {
            if ($template[$cursor] === '\\') {
                $cursor++;

                continue;
            }

            $mergeVariable = $this->mergeVariableAt($template, $cursor);

            if ($mergeVariable !== null) {
                $cursor += strlen($mergeVariable) - 1;

                continue;
            }

            if ($template[$cursor] === '{') {
                $depth++;
            } elseif ($template[$cursor] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $cursor;
                }
            }
        }

        return null;
    }
}

final readonly class SpintaxNode
{
    /**
     * @param  list<list<SpintaxNode>>  $options
     */
    private function __construct(
        public ?string $text,
        public ?int $index,
        public array $options,
    ) {}

    public static function text(string $text): self
    {
        return new self($text, null, []);
    }

    /**
     * @param  list<list<SpintaxNode>>  $options
     */
    public static function group(int $index, array $options): self
    {
        return new self(null, $index, $options);
    }
}
