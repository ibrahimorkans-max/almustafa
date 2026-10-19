<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

/** What a tool returns: words for the person, and optionally links and a small table. */
final class ToolResult
{
    /**
     * @param  list<array{label: string, url: string}>  $links
     * @param  array{columns: list<string>, rows: list<list<string>>}|null  $table
     */
    public function __construct(
        public readonly string $text,
        public readonly array $links = [],
        public readonly ?array $table = null,
        public readonly bool $ok = true,
    ) {}

    public static function fail(string $text): self
    {
        return new self($text, ok: false);
    }

    /** Plain text for Claude (the tool_result content). */
    public function forModel(): string
    {
        $out = $this->text;
        if ($this->table !== null) {
            $out .= "\n".implode(' | ', $this->table['columns']);
            foreach ($this->table['rows'] as $row) {
                $out .= "\n".implode(' | ', $row);
            }
        }
        foreach ($this->links as $l) {
            $out .= "\n[{$l['label']}] {$l['url']}";
        }

        return $out;
    }
}
