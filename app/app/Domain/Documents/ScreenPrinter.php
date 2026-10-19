<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Accounts\AccountsDocuments;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Sales\SalesDocuments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PurchaseBill;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Sales\SalesBill;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

/**
 * The "Print" button on every screen:
 *  - a list prints exactly what is on the screen: its columns and every record that matches the
 *    current search and filters, in the current order (up to 2,000 rows);
 *  - a record prints its own paper when it has one (weighment slip, purchase bill, payment voucher,
 *    sales invoice, voucher, sauda, …) and otherwise a record sheet of what the screen shows.
 */
final class ScreenPrinter
{
    private const MAX_ROWS = 2000;

    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly TenantContext $tenant,
    ) {}

    /** @param  HasTable&Component  $page */
    public function table(object $page, string $title): string
    {
        $table = $page->getTable();
        $columns = array_values(array_filter($table->getVisibleColumns(), fn (Column $c) => filled(strip_tags((string) $c->getLabel()))));
        $records = $page->getFilteredSortedTableQuery()->limit(self::MAX_ROWS)->get();
        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($columns as $column) {
                $row[] = $this->columnText($column, $record);
            }
            $rows[] = $row;
        }
        $search = method_exists($page, 'getTableSearch') ? (string) $page->getTableSearch() : '';
        $filters = [];
        foreach ($table->getFilters() as $filter) {
            foreach ($filter->getIndicators() as $indicator) {
                $filters[] = strip_tags((string) $indicator->getLabel());
            }
        }

        return $this->render([
            'title' => $title,
            'lines' => array_values(array_filter([
                $search !== '' ? 'Search: '.$search : null,
                $filters !== [] ? 'Filters: '.implode(', ', $filters) : null,
                count($rows).' record(s)'.(count($rows) >= self::MAX_ROWS ? ' (first '.self::MAX_ROWS.')' : ''),
            ])),
            'columns' => array_map(fn (Column $c) => strip_tags((string) $c->getLabel()), $columns),
            'rows' => $rows,
            'fields' => [],
        ], count($columns) > 6);
    }

    /** @param  ViewRecord|EditRecord  $page */
    public function record(object $page, string $title): string
    {
        $record = $page->getRecord();
        $own = $this->ownPaper($record);
        if ($own !== null) {
            return $own;
        }

        $fields = [];
        if ($page instanceof ViewRecord && ($infolist = $page->getInfolist('infolist')) !== null) {
            foreach ($infolist->getFlatComponents() as $component) {
                if ($component instanceof Entry && filled($component->getLabel())) {
                    $fields[] = [strip_tags((string) $component->getLabel()), $this->entryText($component)];
                }
            }
        }
        if ($fields === [] && method_exists($page, 'getForm') && ($form = $page->getForm('form')) !== null) {
            foreach ($form->getFlatFields() as $field) {
                $fields[] = [strip_tags((string) $field->getLabel()), $this->fieldText($field)];
            }
        }
        if ($fields === []) {
            foreach ($record->attributesToArray() as $key => $value) {
                if (! str_ends_with($key, '_id') && $key !== 'id' && ! is_array($value)) {
                    $fields[] = [ucfirst(str_replace('_', ' ', $key)), $this->plain($value)];
                }
            }
        }

        return $this->render(['title' => $title, 'lines' => [], 'columns' => [], 'rows' => [], 'fields' => $fields], false, $record);
    }

    /**
     * Any record outside a screen (the assistant's Print links): its own paper, or a sheet of its fields.
     *
     * @param  list<array{0: string, 1: string}>  $fields
     */
    public function sheet(Model $record, string $title, array $fields): string
    {
        return $this->ownPaper($record)
            ?? $this->render(['title' => $title, 'lines' => [], 'columns' => [], 'rows' => [], 'fields' => $fields], false, $record);
    }

    /** The record's own printed paper, when it has one and it can be printed now. */
    public function ownPaper(Model $record): ?string
    {
        try {
            foreach (RecordDocuments::TYPES as $type => [$model]) {
                if ($record instanceof $model) {
                    return app(RecordDocuments::class)->pdf($type, $record);
                }
            }

            return match (true) {
                $record instanceof GateEntry && $record->gross_kg !== null && $record->tare_kg !== null => app(SlipRenderer::class)->pdf($record),
                $record instanceof PurchaseBill && $record->status === PurchaseBill::POSTED => app(AccountsDocuments::class)->purchaseBillPdf($record),
                $record instanceof Payment && $record->status === Payment::APPROVED => app(AccountsDocuments::class)->paymentVoucherPdf($record),
                $record instanceof SalesBill && $record->status === SalesBill::POSTED => app(SalesDocuments::class)->invoicePdf($record),
                default => null,
            };
        } catch (BusinessRuleViolation) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function render(array $data, bool $wide, ?Model $record = null): string
    {
        $entityId = $record?->getAttribute('entity_id') ?? $this->tenant->entityId();
        $entity = $entityId === null ? null : $this->tenant->runAsSystem(fn () => Entity::query()->find($entityId));
        if ($entity === null) {
            $me = auth('web')->user();
            $entity = $me instanceof LoginAccount ? $me->entity : null;
        }

        return $this->pdf->render('reports.screen', ['sheet' => $data], $entity, $wide ? 'A4-L' : 'A4');
    }

    private function columnText(Column $column, Model $record): string
    {
        try {
            $column->record($record);
            $state = $column->getState();
            if ($column instanceof IconColumn) {
                return is_bool($state) ? ($state ? 'Yes' : 'No') : $this->plain($state);
            }
            if ($column instanceof TextColumn) {
                $items = is_array($state) ? $state : ($state instanceof Collection ? $state->all() : [$state]);

                return implode(', ', array_filter(array_map(fn ($s) => $this->plain($column->formatState($s)), $items), fn ($s) => $s !== ''));
            }

            return $this->plain($state);
        } catch (Throwable) {
            return '';
        }
    }

    private function entryText(Entry $entry): string
    {
        try {
            $state = $entry->getState();
            if ($entry instanceof TextEntry) {
                $items = is_array($state) ? $state : ($state instanceof Collection ? $state->all() : [$state]);

                return implode(', ', array_filter(array_map(fn ($s) => $this->plain($entry->formatState($s)), $items), fn ($s) => $s !== ''));
            }

            return $this->plain($state);
        } catch (Throwable) {
            return '';
        }
    }

    private function fieldText(Field $field): string
    {
        try {
            $state = $field->getState();
            if ($field instanceof Select) {
                $options = $field->getOptions();
                $state = is_array($state) ? array_map(fn ($s) => $options[$s] ?? $s, $state) : ($options[$state] ?? $state);
            }

            return $this->plain($state);
        } catch (Throwable) {
            return '';
        }
    }

    private function plain(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof CarbonInterface => $value->timezone('Asia/Karachi')->format('d M Y H:i'),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof Htmlable => trim(html_entity_decode(strip_tags($value->toHtml()))),
            is_array($value) => implode(', ', array_map(fn ($v) => $this->plain($v), $value)),
            is_scalar($value) || $value instanceof \Stringable => trim(html_entity_decode(strip_tags((string) $value))),
            default => '',
        };
    }
}
