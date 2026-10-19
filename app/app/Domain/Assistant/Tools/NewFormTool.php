<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Screens;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Models\LoginAccount;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Livewire\Mechanisms\ComponentRegistry;
use Throwable;

/**
 * Opens the "new" form of ANY screen already filled in from what the person said (a new party,
 * product, employee, sauda, payment, expense, requisition, leave…). The person checks the form and
 * presses Create, so every rule of that screen applies. Without values it lists the form's fields.
 */
final class NewFormTool extends Tool
{
    public function name(): string
    {
        return 'new_form';
    }

    public function description(): string
    {
        return 'Create anything the screens can create (party, product, vehicle, driver, employee, sauda, payment, expense, requisition, leave, price list, claim, '
            .'production batch, dispatch order, user…): first call it with only "screen" to get the form\'s fields and choices, then with "values" (field name => value) '
            .'to get a link to the form already filled in; the person checks it and presses Create.';
    }

    public function properties(): array
    {
        return [
            'screen' => ['type' => 'string', 'description' => 'What to create, e.g. "party", "employee", "sauda".'],
            'values' => ['type' => 'object', 'description' => 'Field name => value, using the field names this tool returned. For choices use the option key.'],
        ];
    }

    public function required(): array
    {
        return ['screen'];
    }

    public function patterns(): array
    {
        return ['/^(?:new|create|add)\s+(?<screen>[\w ]{3,40})$/i'];
    }

    public function examples(): array
    {
        return ['new party', 'create employee'];
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $resource = Screens::find((string) $input['screen']);
        if ($resource === null || ! $resource::hasPage('create') || ! $resource::canCreate()) {
            return ToolResult::fail('There is no "new" form for "'.$input['screen'].'" that you may use.');
        }
        $label = (string) $resource::getModelLabel();
        $values = array_filter((array) ($input['values'] ?? []), fn ($v) => $v !== null && $v !== '');
        if ($values !== []) {
            return new ToolResult("The new {$label} form is filled in. Check it and press Create.", [
                ['label' => 'Open the filled form: new '.$label, 'url' => $resource::getUrl('create').'?'.http_build_query(['prefill' => $values])],
            ]);
        }

        return new ToolResult("Fields of the new {$label} form (* = required):", [['label' => 'New '.$label, 'url' => $resource::getUrl('create')]],
            ['columns' => ['Field name', 'Label', 'Choices'], 'rows' => $this->fields($resource)]);
    }

    /** @return list<list<string>> */
    private function fields(string $resource): array
    {
        try {
            $class = $resource::getPages()['create']->getPage();
            $page = app(ComponentRegistry::class)->new($class);
            if (method_exists($page, 'bootedInteractsWithForms')) {
                $page->bootedInteractsWithForms();
            }
            $page->mount();
            $rows = [];
            foreach ($page->form->getFlatFields() as $name => $field) {
                /** @var Field $field */
                $choices = '';
                if ($field instanceof Select) {
                    $options = $field->getOptions();
                    $choices = implode(', ', array_slice(array_map(fn ($k, $v) => $k.' = '.strip_tags((string) $v), array_keys($options), $options), 0, 12)).(count($options) > 12 ? ' …' : '');
                }
                $rows[] = [$name, strip_tags((string) $field->getLabel()).($field->isRequired() ? ' *' : ''), $choices];
            }

            return $rows;
        } catch (Throwable $e) {
            report($e);

            return [['—', 'Open the form to see its fields.', '']];
        }
    }
}
