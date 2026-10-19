<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Models\LoginAccount;

/**
 * One thing the assistant can do. The same tool serves the AI mode (Claude chooses it from the
 * description and schema) and the offline command mode (a pattern on the typed words). A tool
 * always runs as the signed-in person: the domain services check their rights again, inside the
 * working mill. Tools that change something are confirmed by the person first.
 */
abstract class Tool
{
    abstract public function name(): string;

    /** What it does, for Claude and for the help list. */
    abstract public function description(): string;

    /** @return array<string, array<string, mixed>> JSON-schema properties of the input */
    public function properties(): array
    {
        return [];
    }

    /** @return list<string> */
    public function required(): array
    {
        return [];
    }

    /** The permission needed to use it at all; null = everyone signed in. */
    public function permission(): ?string
    {
        return null;
    }

    /** True when it changes data: then the person confirms it first. */
    public function writes(): bool
    {
        return false;
    }

    /**
     * Offline command mode: regular expressions (case-insensitive) on the typed text; named groups
     * become the input. An example for the help list is the first entry of examples().
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return [];
    }

    /** @return list<string> */
    public function examples(): array
    {
        return [];
    }

    /**
     * One line for the confirmation card: what will happen.
     *
     * @param  array<string, mixed>  $input
     */
    public function summary(array $input): string
    {
        return $this->description();
    }

    /** @param  array<string, mixed>  $input */
    abstract public function run(array $input, LoginAccount $me): ToolResult;

    public function availableTo(LoginAccount $me): bool
    {
        return $this->permission() === null || $me->hasPermission($this->permission());
    }

    /** @return array{name: string, description: string, input_schema: array<string, mixed>} */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description().($this->writes() ? ' This changes data: the person is asked to confirm before it runs.' : ''),
            'input_schema' => ['type' => 'object', 'properties' => (object) $this->properties(), 'required' => $this->required()],
        ];
    }
}
