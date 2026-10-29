<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Masters\CodeDirectory;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Support\ExportGuard;
use App\Models\CodeDirectoryEntry;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Support\Export\XlsxWriter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Masters → Code directory: every code the system made (parties, products, varieties, vehicles,
 * saudas, gate tokens, stock entries, batches, claims, expenses, vouchers), searchable, filtered by
 * module, each opening its record. Read-only: codes are changed nowhere, here least of all.
 */
class CodeDirectoryPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationGroup = 'Masters';

    protected static ?string $navigationLabel = 'Code directory';

    protected static ?string $title = 'Code directory';

    protected static ?string $slug = 'code-directory';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.code-directory';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && ($user->hasPermission('master.view') || $user->hasPermission('master.manage'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CodeDirectory::query())
            ->columns([
                TextColumn::make('code')->label('Code')->fontFamily('mono')->weight('bold')->searchable()->sortable()->copyable(),
                TextColumn::make('module')->label('Module')->badge()->formatStateUsing(fn (string $state) => CodeDirectory::MODULES[$state] ?? $state)->sortable(),
                TextColumn::make('name')->label('Record')
                    ->searchable(query: fn (Builder $query, string $search) => $query->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
                    ->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('Created')->dateTime('d M Y H:i', 'Asia/Karachi')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('module')->options(CodeDirectory::MODULES)->multiple()])
            ->recordUrl(fn (CodeDirectoryEntry $record) => CodeDirectory::url($record))
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('excel')->visible(fn () => ExportGuard::allowed())->label('Excel')->icon('heroicon-o-table-cells')->color('gray')->action(fn () => $this->export('xlsx')),
            Action::make('pdf')->visible(fn () => ExportGuard::allowed())->label('PDF')->icon('heroicon-o-document-arrow-down')->color('gray')->action(fn () => $this->export('pdf')),
        ];
    }

    /** The list as filtered and searched on screen (up to 5,000 rows). */
    public function export(string $format): StreamedResponse
    {
        $rows = [];
        foreach ($this->getFilteredSortedTableQuery()->limit(5000)->get() as $e) {
            if ($e instanceof CodeDirectoryEntry) {
                $rows[] = [$e->code, $e->moduleLabel(), (string) $e->name, $e->created_at?->timezone('Asia/Karachi')->format('d M Y H:i') ?? ''];
            }
        }
        app(AuditLogger::class)->record('report.code_directory.downloaded', null, null, null, ['format' => $format, 'rows' => count($rows)]);
        $header = ['Code', 'Module', 'Record', 'Created'];
        if ($format === 'xlsx') {
            $bytes = XlsxWriter::build('Code directory', $header, $rows);

            return response()->streamDownload(fn () => print $bytes, 'code-directory.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }
        $tenant = app(TenantContext::class);
        $user = Filament::auth()->user();
        $entityId = $tenant->entityId() ?? ($user instanceof LoginAccount ? $user->entity_id : null);
        $entity = $tenant->runAsSystem(fn () => Entity::query()->find($entityId));
        $pdf = app(PdfRenderer::class)->render('reports.screen', [
            'sheet' => ['title' => 'Code directory', 'lines' => [count($rows).' codes'], 'columns' => $header, 'rows' => $rows, 'fields' => []],
            'printedAt' => now('Asia/Karachi'), 'printedBy' => $user instanceof LoginAccount ? $user->username : null,
        ], $entity);

        return response()->streamDownload(fn () => print $pdf, 'code-directory.pdf', ['Content-Type' => 'application/pdf']);
    }
}
