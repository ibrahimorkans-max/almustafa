<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Filament\Resources\Hr\AttendanceLogResource\Pages;
use App\Models\Hr\AttendanceLog;
use App\Models\Hr\AttendanceReview;
use App\Models\Hr\Employee;
use App\Models\StoredFile;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/** Every punch exactly as received, with the verdict and its reasons. Read-only. */
class AttendanceLogResource extends Resource
{
    protected static ?string $model = AttendanceLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Attendance';

    protected static ?string $modelLabel = 'punch';

    protected static ?string $slug = 'hr/attendance';

    protected static ?int $navigationSort = 15;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'valid' => 'success', 'flagged' => 'warning', default => 'danger'
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.person', 'site', 'reviews']))
            ->columns([
                TextColumn::make('occurred_at')->label('Time (PKT)')->dateTime('d M Y H:i', 'Asia/Karachi')->sortable(),
                TextColumn::make('employee.code')->label('Employee')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Name'),
                TextColumn::make('kind')->badge()->color(fn (string $state) => $state === 'in' ? 'info' : 'gray'),
                TextColumn::make('status')->label('Server verdict')->badge()->color(fn (string $state) => static::statusColor($state)),
                TextColumn::make('final')->label('Final')->badge()
                    ->state(fn (AttendanceLog $r) => $r->status === AttendanceLog::FLAGGED && $r->reviews->isEmpty() ? 'awaiting review' : $r->finalStatus())
                    ->color(fn (string $state) => $state === 'awaiting review' ? 'warning' : static::statusColor($state)),
                TextColumn::make('reasons')->label('Why')->wrap()
                    ->formatStateUsing(fn ($state, AttendanceLog $r) => implode('; ', $r->reasonTexts()) ?: '—'),
                TextColumn::make('site.name')->label('Site')->placeholder('—'),
                TextColumn::make('distance_m')->label('Distance')->suffix(' m')->placeholder('—'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Server verdict')->options(['valid' => 'Valid', 'flagged' => 'Flagged', 'rejected' => 'Rejected']),
                Filter::make('awaiting')->label('Awaiting review')->toggle()
                    ->query(fn (Builder $query) => AttendanceLog::whereAwaitingReview($query)),
                SelectFilter::make('kind')->options(['in' => 'In', 'out' => 'Out']),
                SelectFilter::make('employee_id')->label('Employee')->searchable()
                    ->options(fn () => Employee::query()->with('person')->orderBy('code')->get()
                        ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} {$e->name()}"])->all()),
                Filter::make('day')->form([DatePicker::make('day')->label('Day')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['day'] ?? null, function (Builder $q, string $day) {
                        // PKT day → UTC bounds (storage is UTC).
                        $start = CarbonImmutable::parse($day, 'Asia/Karachi')->startOfDay();
                        $q->where('occurred_at', '>=', $start->utc())->where('occurred_at', '<', $start->addDay()->utc());
                    })),
            ])
            ->actions([ViewAction::make()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('employee.code')->label('Employee'),
                TextEntry::make('employee.person.full_name')->label('Name'),
                TextEntry::make('kind')->badge(),
                TextEntry::make('status')->badge()->color(fn (string $state) => static::statusColor($state)),
                TextEntry::make('reasons_text')->label('Why')->columnSpanFull()->listWithLineBreaks()
                    ->state(fn (AttendanceLog $r) => $r->reasonTexts() ?: ['No issues found']),
            ]),
            Section::make('Time')->columns(4)->schema([
                TextEntry::make('occurred_at')->label('Phone time (PKT)')->dateTime('d M Y H:i:s', 'Asia/Karachi'),
                TextEntry::make('estimated_at')->label('Time worked out by the server')->dateTime('d M Y H:i:s', 'Asia/Karachi')->placeholder('not possible'),
                TextEntry::make('received_at')->label('Uploaded')->dateTime('d M Y H:i:s', 'Asia/Karachi'),
                TextEntry::make('time_check')->badge(),
            ]),
            Section::make('Place')->columns(4)->schema([
                TextEntry::make('position')->label('Location')->placeholder('none')
                    ->state(fn (AttendanceLog $r) => $r->latitude !== null ? "{$r->latitude}, {$r->longitude}" : null)
                    ->url(fn (AttendanceLog $r) => $r->latitude !== null
                        ? "https://www.openstreetmap.org/?mlat={$r->latitude}&mlon={$r->longitude}#map=18/{$r->latitude}/{$r->longitude}" : null, true),
                TextEntry::make('accuracy_m')->label('Accuracy')->suffix(' m')->placeholder('—'),
                TextEntry::make('site.name')->label('Nearest site')->placeholder('—'),
                TextEntry::make('distance_m')->label('Distance from its centre')->suffix(' m')->placeholder('—'),
            ]),
            Section::make('Photo and phone')->columns(3)->schema([
                ImageEntry::make('selfie')->label('Photo')->height(220)
                    ->state(fn (AttendanceLog $r) => static::selfieDataUri($r))->placeholder('no photo'),
                TextEntry::make('source'),
                TextEntry::make('signals')->label('Phone integrity signals')->placeholder('none sent (browser)')
                    ->state(fn (AttendanceLog $r) => $r->signals ? json_encode($r->signals, JSON_UNESCAPED_SLASHES) : null),
                TextEntry::make('ip')->label('Uploaded from IP')->placeholder('—'),
            ]),
            Section::make('Review')->visible(fn (AttendanceLog $r) => $r->status === AttendanceLog::FLAGGED || $r->reviews()->exists())->schema([
                TextEntry::make('final')->label('Final status')->badge()
                    ->state(fn (AttendanceLog $r) => $r->status === AttendanceLog::FLAGGED && ! $r->reviews()->exists() ? 'awaiting review' : $r->finalStatus())
                    ->color(fn (string $state) => $state === 'awaiting review' ? 'warning' : static::statusColor($state)),
                TextEntry::make('review_history')->label('Decisions (latest counts)')->listWithLineBreaks()->placeholder('none yet')
                    ->state(fn (AttendanceLog $r) => $r->reviews()->with('reviewer')->get()->map(fn (AttendanceReview $v) => sprintf('%s PKT · %s · %s — %s',
                        $v->reviewed_at->timezone('Asia/Karachi')->format('d M Y H:i'), $v->decision === 'accept' ? 'accepted' : 'rejected',
                        $v->reviewer->username ?? '?', $v->reason))->all()),
            ]),
        ]);
    }

    /** The selfie from private storage, only for people who may see this page; never a public URL. */
    public static function selfieDataUri(AttendanceLog $log): ?string
    {
        if ($log->selfie_sha256 === null) {
            return null;
        }
        $file = StoredFile::query()->where('sha256', $log->selfie_sha256)->first();
        $bytes = $file !== null ? Storage::disk('local')->get($file->path) : null;
        if ($file === null || $bytes === null || ! hash_equals($file->sha256, hash('sha256', $bytes))) {
            return null;
        }

        return 'data:'.$file->mime.';base64,'.base64_encode($bytes);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendanceLogs::route('/'),
            'view' => Pages\ViewAttendanceLog::route('/{record}'),
        ];
    }
}
