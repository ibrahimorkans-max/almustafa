<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gate;

use App\Domain\Gate\WeighmentChecks;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\WeighmentCheckResource\Pages;
use App\Models\Gate\Weighment;
use App\Models\Gate\WeighmentCheck;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Weight checks: every recorded weight with how it was taken (gate agent with photo, Live weight
 * screen, approved manual weight), its flags and stability evidence. The mill's Super Admin (or the
 * Owner) marks each one OK or queries it; nobody checks a weight they recorded. Unchecked weights
 * are counted on the menu.
 */
class WeighmentCheckResource extends Resource
{
    protected static ?string $model = Weighment::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Gate & weighbridge';

    protected static ?string $navigationLabel = 'Weight checks';

    protected static ?string $modelLabel = 'weight';

    protected static ?string $slug = 'weight-checks';

    protected static ?int $navigationSort = 13;

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('weighment.review');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $count = Weighment::query()->whereDoesntHave('checks')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Builder<Weighment> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['gateEntry', 'scale', 'creator', 'checks.checker']);
    }

    public static function table(Table $table): Table
    {
        $pkt = fn ($d) => $d?->timezone('Asia/Karachi')->format('d M Y H:i:s');

        return $table
            ->columns([
                TextColumn::make('gateEntry.number')->label('Gate entry')->searchable()
                    ->description(fn (Weighment $w) => $w->gateEntry?->registration_no),
                TextColumn::make('kind')->badge()->color(fn (string $state) => $state === Weighment::GROSS ? 'info' : 'gray'),
                TextColumn::make('weight_kg')->label('Weight (kg)')->numeric(0)->alignEnd(),
                TextColumn::make('source')->label('Taken by')
                    ->state(fn (Weighment $w) => self::howTaken($w))
                    ->description(fn (Weighment $w) => $w->creator?->username),
                TextColumn::make('flags')->badge()->color('warning')->placeholder('—')
                    ->formatStateUsing(fn (string $state) => GateEntryResource::FLAG_LABELS[$state] ?? $state),
                TextColumn::make('captured_at')->label('At')->formatStateUsing(fn ($state, Weighment $w) => $pkt($w->captured_at))->sortable(),
                TextColumn::make('check')->label('Check')->badge()
                    ->state(fn (Weighment $w) => match ($w->lastCheck()?->decision) {
                        WeighmentCheck::OK => 'OK', WeighmentCheck::QUERY => 'Queried', default => 'Not checked',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'OK' => 'success', 'Queried' => 'danger', default => 'warning'
                    })
                    ->description(fn (Weighment $w) => $w->lastCheck() !== null
                        ? trim($w->lastCheck()->checker->username.' · '.$pkt($w->lastCheck()->checked_at).($w->lastCheck()->note !== null ? ' · '.$w->lastCheck()->note : ''))
                        : null),
            ])
            ->defaultSort('captured_at', 'desc')
            ->filters([
                SelectFilter::make('state')->label('Show')->default('unchecked')
                    ->options(['unchecked' => 'Not checked yet', 'queried' => 'Queried', 'all' => 'All'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'unchecked') {
                        'unchecked' => $query->whereDoesntHave('checks'),
                        // The latest check is a query: a query with no later check after it.
                        'queried' => $query->whereHas('checks', fn (Builder $q) => $q->where('decision', WeighmentCheck::QUERY)
                            ->whereNotExists(fn ($later) => $later->from('weighment_checks as later')
                                ->whereColumn('later.weighment_id', 'weighment_checks.weighment_id')
                                ->whereColumn('later.checked_at', '>', 'weighment_checks.checked_at'))),
                        default => $query,
                    }),
                SelectFilter::make('kind')->options([Weighment::GROSS => 'Gross', Weighment::TARE => 'Tare']),
            ])
            ->actions([
                Action::make('evidence')->label('Evidence')->icon('heroicon-o-magnifying-glass')->color('gray')
                    ->modalHeading(fn (Weighment $w) => 'Weight '.number_format((float) $w->weight_kg, 0).' kg · '.$w->gateEntry?->registration_no)
                    ->modalContent(fn (Weighment $w) => new HtmlString(self::evidenceHtml($w)))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('ok')->label('OK')->icon('heroicon-o-check')->color('success')
                    ->form([Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(500)])
                    ->action(fn (Weighment $w, array $data) => self::decide($w, WeighmentCheck::OK, $data['note'] ?? null)),
                Action::make('query')->label('Query')->icon('heroicon-o-exclamation-triangle')->color('danger')
                    ->form([Textarea::make('note')->label('What is wrong?')->required()->minLength(5)->rows(3)->maxLength(500)])
                    ->action(fn (Weighment $w, array $data) => self::decide($w, WeighmentCheck::QUERY, $data['note'] ?? null)),
            ])
            ->bulkActions([]);
    }

    public static function howTaken(Weighment $w): string
    {
        return match (true) {
            $w->source === Weighment::SOURCE_MANUAL => 'Manual weight (approved)',
            in_array('live_screen', (array) $w->flags, true) => 'Live weight screen',
            default => 'Gate agent'.($w->photo_sha256 !== null ? ' with photo' : ''),
        };
    }

    private static function decide(Weighment $w, string $decision, ?string $note): void
    {
        try {
            app(WeighmentChecks::class)->decide($w, $decision, $note);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($decision === WeighmentCheck::OK ? 'Marked OK.' : 'Queried.')->success()->send();
    }

    private static function evidenceHtml(Weighment $w): string
    {
        $e = fn (?string $s) => e((string) $s);
        $readings = (array) ($w->readings ?? []);
        $samples = array_map('strval', (array) ($readings['samples'] ?? []));
        $rows = [
            'Gate entry' => $w->gateEntry?->number.' · '.$w->gateEntry?->registration_no,
            'Kind' => ucfirst($w->kind),
            'Weight' => number_format((float) $w->weight_kg, 0).' kg',
            'Weighbridge' => $w->scale !== null ? $w->scale->code.' · '.$w->scale->name : '—',
            'Taken by' => self::howTaken($w).($w->creator !== null ? ' · '.$w->creator->username : ''),
            'At' => $w->captured_at->timezone('Asia/Karachi')->format('d M Y H:i:s').' PKT',
            'Readings' => $samples === [] ? 'none sent' : implode(', ', $samples).(isset($readings['window_ms']) ? ' (over '.round(((int) $readings['window_ms']) / 1000, 1).' s)' : ''),
            'Photo' => $w->photo_sha256 !== null ? 'yes (SHA-256 '.substr($w->photo_sha256, 0, 16).'…)' : 'no',
            'Flags' => implode(', ', array_map(fn (string $f) => GateEntryResource::FLAG_LABELS[$f] ?? $f, (array) $w->flags)) ?: 'none',
            'Reason' => $w->reason ?? '—',
        ];
        $html = '<dl class="grid grid-cols-3 gap-x-4 gap-y-2 text-sm">';
        foreach ($rows as $label => $value) {
            $html .= '<dt class="font-medium text-gray-500">'.$e($label).'</dt><dd class="col-span-2">'.$e($value).'</dd>';
        }
        $html .= '</dl>';
        $history = $w->checks()->with('checker')->orderBy('checked_at')->get();
        if ($history->isNotEmpty()) {
            $html .= '<p class="mt-4 text-sm font-medium">Checks</p><ul class="mt-1 list-disc pl-5 text-sm">';
            foreach ($history as $c) {
                $html .= '<li>'.$e(strtoupper($c->decision).' by '.$c->checker->username.', '.$c->checked_at->timezone('Asia/Karachi')->format('d M Y H:i').($c->note !== null ? ': '.$c->note : '')).'</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWeighmentChecks::route('/')];
    }
}
