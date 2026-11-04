<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Assistant\Tools\BusinessSummaryTool;
use App\Domain\Assistant\Tools\CheckWeightTool;
use App\Domain\Assistant\Tools\CreatePartyTool;
use App\Domain\Assistant\Tools\CreateSaudaTool;
use App\Domain\Assistant\Tools\DecideApprovalTool;
use App\Domain\Assistant\Tools\FindPartyTool;
use App\Domain\Assistant\Tools\GateStepTool;
use App\Domain\Assistant\Tools\ListRecordsTool;
use App\Domain\Assistant\Tools\LiveWeightTool;
use App\Domain\Assistant\Tools\NewFormTool;
use App\Domain\Assistant\Tools\OpenScreenTool;
use App\Domain\Assistant\Tools\PendingApprovalsTool;
use App\Domain\Assistant\Tools\RecordDailyWagesTool;
use App\Domain\Assistant\Tools\RecordExpenseTool;
use App\Domain\Assistant\Tools\RecordLabTestTool;
use App\Domain\Assistant\Tools\RecordWeightTool;
use App\Domain\Assistant\Tools\RegisterArrivalTool;
use App\Domain\Assistant\Tools\RequestPaymentTool;
use App\Domain\Assistant\Tools\ShowRecordTool;
use App\Domain\Assistant\Tools\StockTool;
use App\Domain\Assistant\Tools\VehiclesInsideTool;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Support\QuickLabTest;
use App\Models\LoginAccount;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The action chatbot. Two ways of understanding the person:
 *  - AI mode (Claude, when an Anthropic API key is configured): normal sentences, English or Roman Urdu;
 *  - command mode (offline, no key needed): short commands such as "stock", "profit this month",
 *    "arrived MNA-21-1234 sauda 0001", "approve 01a2b3c4", "open payments".
 * Either way the same tools run as the signed-in person with their rights; anything that changes
 * data is shown first and runs only after the person presses Confirm. Every action is audited.
 */
final class Assistant
{
    /** @var list<class-string<Tool>> */
    public const TOOLS = [
        ListRecordsTool::class, ShowRecordTool::class, NewFormTool::class,
        BusinessSummaryTool::class, StockTool::class, VehiclesInsideTool::class, LiveWeightTool::class, PendingApprovalsTool::class,
        FindPartyTool::class, OpenScreenTool::class,
        RegisterArrivalTool::class, RecordWeightTool::class, CheckWeightTool::class, DecideApprovalTool::class, RecordExpenseTool::class,
        CreatePartyTool::class, GateStepTool::class, RecordLabTestTool::class, CreateSaudaTool::class, RequestPaymentTool::class, RecordDailyWagesTool::class,
    ];

    /** The features list (Assistant → Features): each group and the tools in it, in order. */
    public const GROUPS = [
        'Add records quickly' => ['create_party', 'register_vehicle_arrival', 'create_purchase_sauda', 'record_expense', 'request_payment', 'record_daily_wages'],
        'Gate and weighing' => ['record_lab_test', 'vehicles_inside', 'live_weight', 'record_weight', 'check_weight', 'gate_step'],
        'Business and stock' => ['business_summary', 'stock_balance', 'find_party'],
        'Approvals' => ['pending_approvals', 'decide_approval'],
        'Any screen' => ['list_records', 'show_record', 'new_form', 'open_screen'],
    ];

    /** Short names of the features (a quick form's button is its name). */
    public const TITLES = [
        'vehicles_inside' => 'Vehicles inside', 'live_weight' => 'Live weight', 'record_weight' => 'Record a weight',
        'check_weight' => 'Check a weight', 'gate_step' => 'Next gate step', 'business_summary' => 'Profit & business',
        'stock_balance' => 'Stock', 'find_party' => 'Find a party', 'pending_approvals' => 'Pending approvals',
        'decide_approval' => 'Approve or reject', 'list_records' => 'List records', 'show_record' => 'Show a record',
        'new_form' => 'Open a new form', 'open_screen' => 'Open a screen',
    ];

    public function __construct(
        private readonly ClaudeEngine $claude,
        private readonly AuditLogger $audit,
    ) {}

    public function aiEnabled(): bool
    {
        return $this->claude->configured();
    }

    /** @return array<string, Tool> name => tool, only those this person may use */
    public function tools(LoginAccount $me): array
    {
        $out = [];
        foreach (self::TOOLS as $class) {
            $tool = app($class);
            if ($tool->availableTo($me)) {
                $out[$tool->name()] = $tool;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{role: string, content: string}>  $history  earlier turns (text only)
     */
    public function ask(LoginAccount $me, string $message, array $history = []): Reply
    {
        $message = trim($message);
        if ($message === '') {
            return new Reply('Type what you want to do or know.');
        }
        if (preg_match('/^(help|\?|madad|commands?)$/i', $message)) {
            return $this->help($me);
        }
        $tools = $this->tools($me);
        // A quick button's message with its blanks filled: read here, with or without AI.
        $quick = QuickForms::parse($message);
        if ($quick !== null) {
            $tool = $tools[$quick['tool']] ?? null;
            if ($tool === null) {
                return new Reply('That is not available to you.');
            }
            if ($quick['blank'] !== []) {
                return new Reply('Still blank: '.implode(', ', $quick['blank']).'. Fill them in and send again.');
            }

            return Reply::confirm($tool->summary($quick['input']), $tool->name(), $quick['input']);
        }
        if ($this->aiEnabled()) {
            try {
                return $this->claude->ask($me, $message, $history, $tools, fn (Tool $t, array $input) => $this->runRead($t, $input, $me));
            } catch (Throwable $e) {
                report($e);
                // The AI service failed: fall back to the commands, and say so.
                $reply = $this->command($me, $message, $tools);

                return new Reply('(AI is not reachable right now; using commands.) '.$reply->text, $reply->links, $reply->table, $reply->pending);
            }
        }

        return $this->command($me, $message, $tools);
    }

    /**
     * The quick buttons this person may use.
     *
     * @return array<string, array{button: string, icon: string}>
     */
    public function quickForms(LoginAccount $me): array
    {
        $tools = $this->tools($me);
        $out = [];
        foreach (QuickForms::FORMS as $key => $form) {
            if (isset($tools[$form['tool']])) {
                $out[$key] = ['button' => $form['button'], 'icon' => $form['icon']];
            }
        }

        return $out;
    }

    /**
     * Everything this person can do with the assistant, grouped: each feature with what it does, examples to try
     * (pressing one puts it in the box), its quick button if it has one, and whether it changes data (Confirm
     * first). Then the screens they may open.
     *
     * @return list<array{group: string, items: list<array{title: string, description: string, examples: list<string>, quick: string|null, writes: bool}>}>
     */
    public function features(LoginAccount $me): array
    {
        $tools = $this->tools($me);
        $quickOf = [];
        foreach (QuickForms::FORMS as $key => $form) {
            $quickOf[$form['tool']] = $key;
        }
        $out = [];
        $listed = [];
        foreach ([...self::GROUPS, 'More' => array_keys($tools)] as $group => $names) {
            $items = [];
            foreach ($names as $name) {
                $tool = $tools[$name] ?? null;
                if ($tool === null || isset($listed[$name])) {
                    continue;
                }
                $listed[$name] = true;
                $items[] = [
                    'title' => isset($quickOf[$name]) ? QuickForms::FORMS[$quickOf[$name]]['button'] : (self::TITLES[$name] ?? ucfirst(str_replace('_', ' ', $name))),
                    'description' => $tool->description(), 'examples' => $tool->examples(),
                    'quick' => $quickOf[$name] ?? null, 'writes' => $tool->writes(),
                ];
            }
            if ($items !== []) {
                $out[] = ['group' => $group, 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * The screens this person may open, by menu group, for the features list.
     *
     * @return array<string, list<array{label: string, url: string}>>
     */
    public function screens(): array
    {
        $out = [];
        foreach (Screens::resources() as $resource) {
            try {
                $out[(string) ($resource::getNavigationGroup() ?? 'Other')][] = ['label' => (string) $resource::getPluralModelLabel(), 'url' => $resource::getUrl()];
            } catch (Throwable) {
                continue;
            }
        }
        foreach ($out as &$links) {
            usort($links, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));
        }
        ksort($out);

        return $out;
    }

    /**
     * A quick button pressed: what must exist first, and the message with blanks (null when something needed is
     * missing: the reply links to where it is added).
     *
     * @return array{reply: Reply, template: string|null}
     */
    public function quickStart(LoginAccount $me, string $key): array
    {
        $form = QuickForms::FORMS[$key] ?? null;
        if ($form === null || ! isset($this->tools($me)[$form['tool']])) {
            return ['reply' => new Reply('That is not available to you.'), 'template' => null];
        }
        $checks = Prerequisites::for($form['record']);
        $ready = Prerequisites::ready($checks);
        $text = $form['title'].($checks === [] ? '' : ' — before you start:'."\n".Prerequisites::text($checks))."\n\n"
            .($ready ? 'Fill the blanks (____) in the box below and press Send. Tab jumps to the next blank.'
                : 'Add what is missing (✗) first, then press the button again.');

        // The lab test: the vehicles waiting for it, to pick one.
        $table = null;
        if ($key === 'lab' && $ready) {
            $rows = array_map(fn (string $label) => [$label], array_values(QuickLabTest::waiting()));
            $table = ['columns' => ['Waiting for the lab (type its number or token)'], 'rows' => array_slice($rows, 0, 30)];
        }

        return ['reply' => new Reply($text, Prerequisites::links($checks), $table), 'template' => $ready ? QuickForms::template($key) : null];
    }

    /**
     * Runs a confirmed change.
     *
     * @param  array{tool: string, input: array<string, mixed>}  $pending
     */
    public function confirm(LoginAccount $me, array $pending): Reply
    {
        $tool = $this->tools($me)[$pending['tool']] ?? null;
        if ($tool === null || ! $tool->writes()) {
            return new Reply('That action is not available to you.');
        }
        $result = $this->execute($tool, $pending['input'], $me);
        $this->audit->record('assistant.action', null, null, null, ['tool' => $tool->name(), 'input' => $pending['input'], 'ok' => $result->ok]);

        return new Reply($result->text, $result->links, $result->table);
    }

    /** @param  array<string, mixed>  $input */
    public function runRead(Tool $tool, array $input, LoginAccount $me): ToolResult
    {
        return $tool->writes() ? ToolResult::fail('Changes need confirmation.') : $this->execute($tool, $input, $me);
    }

    /** @param  array<string, mixed>  $input */
    private function execute(Tool $tool, array $input, LoginAccount $me): ToolResult
    {
        foreach ($tool->required() as $field) {
            if (blank($input[$field] ?? null)) {
                return ToolResult::fail("I need the {$field} for that.");
            }
        }
        try {
            return $tool->run($input, $me);
        } catch (BusinessRuleViolation|ApprovalDenied $e) {
            return ToolResult::fail($e->getMessage());
        } catch (ValidationException $e) {
            return ToolResult::fail(implode(' ', $e->validator->errors()->all()));
        }
    }

    /** @param  array<string, Tool>  $tools */
    private function command(LoginAccount $me, string $message, array $tools): Reply
    {
        // Change-tools first, so "approve …" is not read as "approvals".
        $ordered = array_merge(array_filter($tools, fn (Tool $t) => $t->writes()), array_filter($tools, fn (Tool $t) => ! $t->writes()));
        foreach ($ordered as $tool) {
            foreach ($tool->patterns() as $pattern) {
                if (preg_match($pattern, $message, $m)) {
                    $input = array_filter(array_map('trim', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)), fn ($v) => $v !== '');
                    if ($tool->writes()) {
                        return Reply::confirm($tool->summary($input), $tool->name(), $input);
                    }
                    $result = $this->execute($tool, $input, $me);

                    return new Reply($result->text, $result->links, $result->table);
                }
            }
        }
        // Nothing matched: maybe it names a screen.
        $screens = $this->execute(app(OpenScreenTool::class), ['words' => $message], $me);
        if ($screens->links !== []) {
            return new Reply('I did not understand that as a command. These screens may help (say "help" for commands):', $screens->links);
        }

        return $this->help($me, 'I did not understand that. ');
    }

    private function help(LoginAccount $me, string $prefix = ''): Reply
    {
        $rows = [];
        foreach ($this->tools($me) as $tool) {
            $rows[] = [$tool->examples()[0] ?? $tool->name(), $tool->description()];
        }

        return new Reply($prefix.($this->aiEnabled()
            ? 'Ask in your own words, in English or Roman Urdu. I can do these things for you (changes are confirmed first):'
            : 'Type one of these commands (changes are confirmed first):'), table: ['columns' => ['Try', 'What it does'], 'rows' => $rows]);
    }
}
