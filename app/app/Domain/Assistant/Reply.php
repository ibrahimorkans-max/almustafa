<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

/** The assistant's answer: text, links, an optional table, and a change waiting for Confirm. */
final class Reply
{
    /**
     * @param  list<array{label: string, url: string}>  $links
     * @param  array{columns: list<string>, rows: list<list<string>>}|null  $table
     * @param  array{tool: string, input: array<string, mixed>, summary: string}|null  $pending
     */
    public function __construct(
        public readonly string $text,
        public readonly array $links = [],
        public readonly ?array $table = null,
        public readonly ?array $pending = null,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function confirm(string $summary, string $tool, array $input, string $lead = 'Please confirm:'): self
    {
        return new self($lead, pending: ['tool' => $tool, 'input' => $input, 'summary' => $summary]);
    }

    /** @return array{text: string, links: list<array{label: string, url: string}>, table: array<string, mixed>|null, pending: array<string, mixed>|null} */
    public function toArray(): array
    {
        return ['text' => $this->text, 'links' => $this->links, 'table' => $this->table, 'pending' => $this->pending];
    }
}
