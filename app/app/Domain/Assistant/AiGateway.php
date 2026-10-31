<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One way to ask any AI provider (Administration → AI assistant): Anthropic in its own format, and every other
 * provider (OpenRouter, OpenAI, Google Gemini, Groq, DeepSeek, Mistral, or any OpenAI-compatible address) through
 * the OpenAI "chat completions" format. The callers (the Assistant, the roznamcha reader, the key check) speak
 * Anthropic's message format; this turns it into the provider's and the answer back:
 *
 *  - text, images (base64) and PDFs (as a file) in a message;
 *  - tools: Anthropic's {name, description, input_schema} ⇄ OpenAI's functions; tool_use ⇄ tool_calls;
 *    tool_result ⇄ a "tool" message.
 *
 * The answer always comes back as Anthropic's {content: [text / tool_use blocks], stop_reason}.
 */
final class AiGateway
{
    /**
     * @param  array{provider: string, api_key: string, model: string, api_url: string}  $ai
     * @param  list<array<string, mixed>>  $messages  Anthropic-format messages
     * @param  list<array<string, mixed>>  $tools  Anthropic-format tools
     */
    public function send(array $ai, ?string $system, array $messages, array $tools = [], int $maxTokens = 1024, int $timeout = 45): Response
    {
        if ($ai['provider'] === 'anthropic') {
            return Http::withHeaders(['x-api-key' => $ai['api_key'], 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout($timeout)
                ->post($ai['api_url'], array_filter(['model' => $ai['model'], 'max_tokens' => $maxTokens, 'system' => $system,
                    'tools' => $tools !== [] ? $tools : null, 'messages' => $messages], fn ($v) => $v !== null));
        }

        $body = ['model' => $ai['model'], 'max_tokens' => $maxTokens, 'messages' => self::toOpenAi($system, $messages)];
        if ($tools !== []) {
            $body['tools'] = array_map(fn (array $t) => ['type' => 'function', 'function' => [
                'name' => $t['name'], 'description' => $t['description'] ?? '', 'parameters' => $t['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass],
            ]], $tools);
        }
        $headers = $ai['provider'] === 'openrouter' ? ['HTTP-Referer' => (string) config('app.url'), 'X-Title' => (string) config('ricemill.product.name', 'MillNexa')] : [];

        return Http::withToken($ai['api_key'])->withHeaders($headers)->acceptJson()->timeout($timeout)->post($ai['api_url'], $body);
    }

    /**
     * The answer as Anthropic's {content, stop_reason}, whichever provider gave it.
     *
     * @param  array{provider: string}  $ai
     * @return array{content: list<array<string, mixed>>, stop_reason: string}
     */
    public static function answer(array $ai, Response $response): array
    {
        $json = (array) $response->json();
        if ($ai['provider'] === 'anthropic') {
            return ['content' => array_values((array) ($json['content'] ?? [])), 'stop_reason' => (string) ($json['stop_reason'] ?? 'end_turn')];
        }
        $message = (array) ($json['choices'][0]['message'] ?? []);
        $content = [];
        $text = $message['content'] ?? '';
        if (is_array($text)) {   // some providers give parts
            $text = implode('', array_map(fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : (string) $p, $text));
        }
        if (trim((string) $text) !== '') {
            $content[] = ['type' => 'text', 'text' => (string) $text];
        }
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $input = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $content[] = ['type' => 'tool_use', 'id' => (string) ($call['id'] ?? uniqid('call_')), 'name' => (string) ($call['function']['name'] ?? ''),
                'input' => is_array($input) ? $input : []];
        }
        $finish = (string) ($json['choices'][0]['finish_reason'] ?? 'stop');

        return ['content' => $content, 'stop_reason' => $finish === 'tool_calls' || (($message['tool_calls'] ?? []) !== [] && $finish !== 'length') ? 'tool_use' : 'end_turn'];
    }

    /** The provider's own words when it refused. */
    public static function error(Response $response): string
    {
        $e = $response->json('error');

        return mb_substr((string) (is_array($e) ? ($e['message'] ?? json_encode($e)) : ($e ?? $response->json('message') ?? 'HTTP '.$response->status())), 0, 200);
    }

    /**
     * Anthropic-format messages → OpenAI chat messages.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private static function toOpenAi(?string $system, array $messages): array
    {
        $out = $system !== null && $system !== '' ? [['role' => 'system', 'content' => $system]] : [];
        foreach ($messages as $m) {
            $role = (string) $m['role'];
            $content = $m['content'];
            if (is_string($content)) {
                $out[] = ['role' => $role, 'content' => $content];

                continue;
            }
            $parts = [];
            $calls = [];
            foreach ((array) $content as $block) {
                switch ($block['type'] ?? '') {
                    case 'text':
                        $parts[] = ['type' => 'text', 'text' => (string) $block['text']];
                        break;
                    case 'image':
                        $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$block['source']['media_type'].';base64,'.$block['source']['data']]];
                        break;
                    case 'document':
                        $parts[] = ['type' => 'file', 'file' => ['filename' => 'page.pdf', 'file_data' => 'data:application/pdf;base64,'.$block['source']['data']]];
                        break;
                    case 'tool_use':
                        $calls[] = ['id' => (string) $block['id'], 'type' => 'function', 'function' => [
                            'name' => (string) $block['name'], 'arguments' => (string) json_encode((object) ($block['input'] ?? [])),
                        ]];
                        break;
                    case 'tool_result':
                        $result = $block['content'] ?? '';
                        $out[] = ['role' => 'tool', 'tool_call_id' => (string) $block['tool_use_id'], 'content' => is_string($result) ? $result : (string) json_encode($result)];
                        break;
                }
            }
            if ($role === 'assistant') {
                $text = implode("\n", array_map(fn ($p) => $p['text'] ?? '', array_filter($parts, fn ($p) => $p['type'] === 'text')));
                $out[] = ['role' => 'assistant', 'content' => $text !== '' ? $text : null] + ($calls !== [] ? ['tool_calls' => $calls] : []);
            } elseif ($parts !== []) {
                // Text only: plain text (every provider takes it); with an image or a file: the parts.
                $onlyText = array_filter($parts, fn ($p) => $p['type'] !== 'text') === [];
                $out[] = ['role' => $role, 'content' => $onlyText ? implode("\n", array_column($parts, 'text')) : $parts];
            }
        }

        return $out;
    }
}
