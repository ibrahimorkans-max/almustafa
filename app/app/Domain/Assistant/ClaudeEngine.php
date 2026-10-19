<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use Closure;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AI mode: the Anthropic Messages API with the assistant's tools. Claude reads the question,
 * calls read-only tools as often as it needs (they run here, as the person), and answers. When it
 * wants a tool that changes data, the loop stops and the person is shown the change to confirm.
 * Only the question, the earlier turns and the tool results are sent; nothing else from the books.
 */
final class ClaudeEngine
{
    private const MAX_STEPS = 6;

    public function configured(): bool
    {
        return (bool) config('ricemill.assistant.enabled') && filled(config('ricemill.assistant.api_key'));
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array<string, Tool>  $tools
     * @param  Closure(Tool, array<string, mixed>): ToolResult  $runRead
     */
    public function ask(LoginAccount $me, string $message, array $history, array $tools, Closure $runRead): Reply
    {
        $messages = [];
        foreach (array_slice($history, -10) as $turn) {
            if (in_array($turn['role'], ['user', 'assistant'], true) && $turn['content'] !== '') {
                $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $message];
        $definitions = array_values(array_map(fn (Tool $t) => $t->definition(), $tools));
        $links = [];
        $table = null;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = $this->call($this->system($me), $messages, $definitions);
            $content = (array) ($response['content'] ?? []);
            $text = trim(implode("\n", array_map(fn ($b) => $b['text'] ?? '', array_filter($content, fn ($b) => ($b['type'] ?? '') === 'text'))));
            $uses = array_values(array_filter($content, fn ($b) => ($b['type'] ?? '') === 'tool_use'));
            if (($response['stop_reason'] ?? '') !== 'tool_use' || $uses === []) {
                return new Reply($text !== '' ? $text : 'Done.', $links, $table);
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $results = [];
            foreach ($uses as $use) {
                $tool = $tools[$use['name']] ?? null;
                $input = (array) ($use['input'] ?? []);
                if ($tool !== null && $tool->writes()) {
                    return Reply::confirm($tool->summary($input), $tool->name(), $input, $text !== '' ? $text : 'Please confirm:');
                }
                $result = $tool === null ? ToolResult::fail('That tool is not available to this person.') : $runRead($tool, $input);
                $links = array_merge($links, $result->links);
                $table = $result->table ?? $table;
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $result->forModel(), 'is_error' => ! $result->ok];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return new Reply('That needed too many steps; please ask in smaller parts.', $links, $table);
    }

    private function system(LoginAccount $me): string
    {
        $mill = app(TenantContext::class)->entityId();
        $millName = $mill === null ? 'all mills' : ((string) (Entity::query()->whereKey($mill)->value('name') ?? 'the mill'));

        return "You are the assistant inside the ERP of {$millName}, a rice mill in Pakistan. You talk with {$me->username}"
            .' (role: '.($me->role->name ?? 'staff').'). Use the tools to answer and to act; never invent figures. '
            .'Money is in Pakistani rupees written like Rs 1,25,000; weights in kg and maund (1 maund = 40 kg). '
            .'Reply briefly, in the language the person used (English or Roman Urdu). '
            .'For anything that changes data call the tool: the person will be asked to confirm, so do not ask for confirmation yourself. '
            .'If no tool fits, use open_screen to give the right screen. Today is '.now('Asia/Karachi')->format('d M Y').'.';
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    private function call(string $system, array $messages, array $tools): array
    {
        $response = Http::withHeaders([
            'x-api-key' => (string) config('ricemill.assistant.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(45)->post((string) config('ricemill.assistant.api_url'), [
            'model' => (string) config('ricemill.assistant.model'),
            'max_tokens' => 1024,
            'system' => $system,
            'tools' => $tools,
            'messages' => $messages,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Assistant AI request failed: HTTP '.$response->status());
        }

        return (array) $response->json();
    }
}
