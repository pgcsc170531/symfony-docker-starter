<?php

namespace App\Service\Manual;

/**
 * A deliberately small Markdown reader for the Edus playbooks.
 *
 * This is not a general Markdown implementation. It understands exactly the
 * subset the bursary playbook uses — headings, paragraphs, pipe tables, ordered
 * and unordered lists, checkboxes, blockquotes, fenced code and horizontal
 * rules — and turns it into the plain HTML that Dompdf can lay out.
 *
 * Keeping the converter small matters: a general Markdown parser can emit
 * nested flex/grid markup, and Dompdf silently collapses those. The output here
 * is deliberately boring: <h2>, <p>, <table>, <ul>, <li>, <pre>.
 */
final class MarkdownToHtml
{
    public function convert(string $markdown): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
        $html = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '') {
                ++$i;
                continue;
            }

            // Fenced code block.
            if (str_starts_with($trimmed, '```')) {
                ++$i;
                $code = [];

                while ($i < $count && !str_starts_with(trim($lines[$i]), '```')) {
                    $code[] = $lines[$i];
                    ++$i;
                }

                ++$i; // closing fence
                $html[] = '<pre class="code">' . $this->escape(implode("\n", $code)) . '</pre>';
                continue;
            }

            // Horizontal rule.
            if (preg_match('/^\s*---+\s*$/', $line) === 1) {
                $html[] = '<hr>';
                ++$i;
                continue;
            }

            // Heading.
            if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $matches) === 1) {
                $level = strlen($matches[1]);
                $class = $level === 2 ? ' class="part"' : '';
                $html[] = sprintf(
                    '<h%d%s>%s</h%d>',
                    $level,
                    $class,
                    $this->inline(trim($matches[2])),
                    $level
                );
                ++$i;
                continue;
            }

            // Pipe table.
            if (str_starts_with($trimmed, '|') && $this->isTableSeparator($lines[$i + 1] ?? '')) {
                [$tableHtml, $i] = $this->table($lines, $i);
                $html[] = $tableHtml;
                continue;
            }

            // Blockquote / callout.
            if (str_starts_with($trimmed, '>')) {
                $quote = [];

                while ($i < $count && str_starts_with(trim($lines[$i]), '>')) {
                    $quote[] = trim((string) preg_replace('/^\s*>\s?/', '', $lines[$i]));
                    ++$i;
                }

                $quote = array_values(array_filter($quote, static fn (string $value): bool => $value !== ''));
                $html[] = '<blockquote><p>' . $this->inline(implode(' ', $quote)) . '</p></blockquote>';
                continue;
            }

            // Ordered or unordered list.
            if (preg_match('/^(\s*)([-*+]|\d+\.)\s+/', $line) === 1) {
                [$listHtml, $i] = $this->list($lines, $i);
                $html[] = $listHtml;
                continue;
            }

            // Paragraph: consume until the next block starts.
            $paragraph = [];

            while ($i < $count && !$this->isBlockStart($lines[$i])) {
                $paragraph[] = trim($lines[$i]);
                ++$i;
            }

            if ($paragraph !== []) {
                $html[] = '<p>' . $this->inline(implode(' ', $paragraph)) . '</p>';
                continue;
            }

            // A block starter we did not recognise: print the line and move on,
            // so a malformed line can never spin this loop forever.
            $html[] = '<p>' . $this->inline($trimmed) . '</p>';
            ++$i;
        }

        return implode("\n", $html);
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: string, 1: int}
     */
    private function table(array $lines, int $start): array
    {
        $rows = [];
        $i = $start;
        $count = count($lines);

        while ($i < $count && str_starts_with(trim($lines[$i]), '|')) {
            $rows[] = $this->splitRow($lines[$i]);
            ++$i;
        }

        $header = array_shift($rows) ?? [];
        $separator = array_shift($rows) ?? [];
        $align = array_map(fn (string $cell): ?string => $this->alignment($cell), $separator);

        $html = '<table class="grid"><thead><tr>';

        foreach ($header as $index => $cell) {
            $html .= '<th' . $this->alignAttribute($align[$index] ?? null) . '>'
                . $this->inline($cell) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';

            foreach ($header as $index => $cell) {
                $html .= '<td' . $this->alignAttribute($align[$index] ?? null) . '>'
                    . $this->inline($row[$index] ?? '') . '</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return [$html, $i];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: string, 1: int}
     */
    private function list(array $lines, int $start): array
    {
        $entries = [];
        $i = $start;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '') {
                break;
            }

            if (preg_match('/^(\s*)([-*+]|\d+\.)\s+(.+)$/', $line, $matches) === 1) {
                $entries[] = [
                    'indent' => strlen($matches[1]),
                    'ordered' => str_ends_with($matches[2], '.'),
                    'text' => $matches[3],
                ];
                ++$i;
                continue;
            }

            // Continuation of the previous item, e.g. a sentence wrapped by the
            // Markdown source. It is indented, so it belongs to the item.
            if ($entries !== [] && preg_match('/^\s+/', $line) === 1) {
                $entries[count($entries) - 1]['text'] .= ' ' . $trimmed;
                ++$i;
                continue;
            }

            break;
        }

        if ($entries === []) {
            return ['', $i];
        }

        [$html] = $this->renderList($entries, 0, $entries[0]['indent']);

        return [$html, $i];
    }

    /**
     * @param list<array{indent: int, ordered: bool, text: string}> $entries
     *
     * @return array{0: string, 1: int}
     */
    private function renderList(array $entries, int $start, int $indent): array
    {
        $tag = $entries[$start]['ordered'] ? 'ol' : 'ul';
        $html = '<' . $tag . '>';
        $i = $start;
        $count = count($entries);

        while ($i < $count && $entries[$i]['indent'] === $indent) {
            $text = $entries[$i]['text'];

            if (preg_match('/^\[ \]\s*(.*)$/', $text, $matches) === 1) {
                $item = '<span class="checkbox">[ ]</span> ' . $this->inline($matches[1]);
            } else {
                $item = $this->inline($text);
            }

            $html .= '<li>' . $item;
            ++$i;

            if ($i < $count && $entries[$i]['indent'] > $indent) {
                [$childHtml, $i] = $this->renderList($entries, $i, $entries[$i]['indent']);
                $html .= $childHtml;
            }

            $html .= '</li>';
        }

        $html .= '</' . $tag . '>';

        return [$html, $i];
    }

    private function isBlockStart(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed === ''
            || str_starts_with($trimmed, '```')
            || str_starts_with($trimmed, '>')
            || str_starts_with($trimmed, '|')
            || preg_match('/^#{1,4}\s+/', $line) === 1
            || preg_match('/^\s*---+\s*$/', $line) === 1
            || preg_match('/^(\s*)([-*+]|\d+\.)\s+/', $line) === 1;
    }

    private function isTableSeparator(string $line): bool
    {
        $line = trim($line);

        if ($line === '' || !str_contains($line, '-')) {
            return false;
        }

        return preg_match('/^\|?[\s:|-]+\|?$/', $line) === 1;
    }

    /** @return list<string> */
    private function splitRow(string $line): array
    {
        $line = trim($line);
        $line = trim($line, '|');

        return array_map(static fn (string $cell): string => trim($cell), explode('|', $line));
    }

    private function alignment(string $cell): ?string
    {
        $cell = trim($cell);
        $left = str_starts_with($cell, ':');
        $right = str_ends_with($cell, ':');

        if ($left && $right) {
            return 'center';
        }

        if ($right) {
            return 'right';
        }

        if ($left) {
            return 'left';
        }

        return null;
    }

    private function alignAttribute(?string $alignment): string
    {
        return match ($alignment) {
            'right' => ' class="num"',
            'center' => ' class="center"',
            default => '',
        };
    }

    private function inline(string $text): string
    {
        // The check-box glyph is not in every Dompdf font; the plain bracket
        // pair is unambiguous and always prints.
        $text = str_replace('☐', '[ ]', $text);
        $text = $this->escape($text);

        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function (array $matches) use (&$codes): string {
            $codes[] = '<code>' . $matches[1] . '</code>';

            return "\x00C" . (count($codes) - 1) . "\x00";
        }, $text) ?? $text;

        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;

        foreach ($codes as $index => $code) {
            $text = str_replace("\x00C" . $index . "\x00", $code, $text);
        }

        return $text;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
