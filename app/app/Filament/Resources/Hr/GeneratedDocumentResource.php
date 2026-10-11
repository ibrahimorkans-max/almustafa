<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Documents\Engine\DocumentPresenter;
use App\Domain\Documents\Engine\DocumentRenderer;
use App\Domain\Documents\Engine\DocumentService;
use App\Domain\Documents\Engine\DocumentSharing;
use App\Domain\Documents\Engine\DocumentTypes;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\GeneratedDocumentResource\Pages;
use App\Filament\Resources\Hr\GeneratedDocumentResource\RelationManagers;
use App\Filament\Support\DocumentActions;
use App\Models\Documents\DocumentAccessLog;
use App\Models\Documents\DocumentShare;
use App\Models\Documents\GeneratedDocument;
use App\Models\LoginAccount;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Every HR document issued: download the original, reprint (DUPLICATE) in any layout, correct by revision, see who opened it. */
class GeneratedDocumentResource extends Resource
{
    protected static ?string $model = GeneratedDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $modelLabel = 'document';

    protected static ?string $slug = 'hr/documents';

    protected static ?int $navigationSort = 50;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('employee.person')->withCount(['accessLog as reprints' => fn (Builder $q) => $q->where('action', 'reprint')]))
            ->columns([
                TextColumn::make('created_at')->label('Issued')->dateTime('d M Y H:i', 'Asia/Karachi')->sortable(),
                TextColumn::make('doc_type')->label('Document')->formatStateUsing(fn (string $state) => DocumentTypes::options()[$state] ?? $state),
                TextColumn::make('number')->formatStateUsing(fn (GeneratedDocument $record) => $record->displayNumber())->searchable(),
                TextColumn::make('employee.code')->label('Employee')->placeholder('—')->searchable(),
                TextColumn::make('employee.person.full_name')->label('Name')->placeholder('—'),
                TextColumn::make('language')->formatStateUsing(fn (string $state) => DocumentPresenter::LANGUAGES[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reprints')->alignEnd(),
                TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (GeneratedDocument $r) => $r->isCurrent() ? 'valid' : 'replaced')
                    ->color(fn (string $state) => $state === 'valid' ? 'success' : 'gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('doc_type')->label('Document')->options(DocumentTypes::options())])
            ->actions([
                ViewAction::make(),
                ActionGroup::make([
                    Action::make('original')->label('Download original')->icon('heroicon-o-arrow-down-tray')
                        ->action(function (GeneratedDocument $record) {
                            try {
                                return DocumentActions::stream(app(DocumentService::class)->original($record));
                            } catch (BusinessRuleViolation $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();

                                return null;
                            }
                        }),
                    Action::make('reprint')->label('Reprint / other format')->icon('heroicon-o-printer')
                        ->form(fn (GeneratedDocument $record) => DocumentActions::form($record->doc_type, extra: true))
                        ->fillForm(fn (GeneratedDocument $record) => ['layout' => $record->layout, 'language' => $record->language, 'paper' => $record->paper,
                            'format' => 'pdf', 'digits' => $record->options['digits'] ?? 'western', 'password' => 'none', 'unmask' => 0,
                            'toggles' => array_keys(array_filter((array) ($record->options['toggles'] ?? [])))])
                        ->action(fn (GeneratedDocument $record, array $data) => DocumentActions::reprint($record, $data)),
                    Action::make('share')->label('Send / share')->icon('heroicon-o-paper-airplane')
                        ->visible(fn (GeneratedDocument $r) => $r->isCurrent() && DocumentActions::allowed('hr.documents.share'))
                        ->form([
                            Radio::make('channel')->options(DocumentShare::CHANNELS)->default('whatsapp')->required()->inline()->live(),
                            TextInput::make('recipient')->label(fn (Get $get) => $get('channel') === 'email' ? 'E-mail address' : 'Mobile number')
                                ->visible(fn (Get $get) => $get('channel') !== 'link')->required(fn (Get $get) => $get('channel') !== 'link')
                                ->default(fn (GeneratedDocument $record) => $record->employee?->person?->phone),
                            Select::make('hours')->label('Link open for')->default(72)->required()
                                ->options([24 => '1 day', 72 => '3 days', 168 => '7 days', 720 => '30 days']),
                            TextInput::make('max_opens')->label('Most openings (empty = any)')->integer()->minValue(1)->maxValue(100),
                            Toggle::make('protect')->label('Open only with the last 5 digits of the employee\'s CNIC')
                                ->default(fn (GeneratedDocument $record) => $record->employee_id !== null),
                        ])
                        ->action(function (GeneratedDocument $record, array $data): void {
                            try {
                                $r = app(DocumentSharing::class)->share($record, $data['channel'], $data['recipient'] ?? null, (int) $data['hours'],
                                    filled($data['max_opens'] ?? null) ? (int) $data['max_opens'] : null, (bool) ($data['protect'] ?? false));
                                $n = Notification::make()->success()->persistent()->title(match ($data['channel']) {
                                    'email' => 'Sent by e-mail.', 'sms' => 'SMS text ready (no SMS provider configured: it is in the log).', default => 'Link ready.',
                                })->body($r['message']);
                                if ($r['send_url'] !== null) {
                                    $n->actions([NotificationAction::make('open')->label('Send on WhatsApp')->url($r['send_url'], shouldOpenInNewTab: true)]);
                                }
                                $n->send();
                            } catch (BusinessRuleViolation $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        }),
                    Action::make('revise')->label('Issue correction')->icon('heroicon-o-pencil-square')->color('warning')
                        ->visible(fn (GeneratedDocument $r) => $r->isCurrent())
                        ->modalDescription('Builds the document again from its record. If anything changed, a new revision is issued and this one is marked replaced (it stays visible).')
                        ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                        ->action(function (GeneratedDocument $record, array $data): void {
                            try {
                                $new = app(DocumentService::class)->revise($record, $data['reason']);
                                Notification::make()->title("Issued {$new->displayNumber()}.")->success()->send();
                            } catch (BusinessRuleViolation $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('doc_type')->label('Document')->formatStateUsing(fn (string $state) => DocumentTypes::options()[$state] ?? $state),
                TextEntry::make('number')->formatStateUsing(fn (GeneratedDocument $record) => $record->displayNumber()),
                TextEntry::make('created_at')->label('Issued')->dateTime('d M Y H:i', 'Asia/Karachi'),
                TextEntry::make('employee.code')->label('Employee')->placeholder('—'),
                TextEntry::make('layout'),
                TextEntry::make('language')->formatStateUsing(fn (string $state) => DocumentPresenter::LANGUAGES[$state] ?? $state),
                TextEntry::make('paper')->formatStateUsing(fn (string $state) => DocumentRenderer::PAPERS[$state] ?? $state),
                TextEntry::make('template')->label('Template')->state(fn (GeneratedDocument $r) => $r->templateVersion->template->name.' v'.$r->templateVersion->version_no),
                TextEntry::make('verify_code')->label('Verification code')->fontFamily('mono'),
                TextEntry::make('sha256')->label('SHA-256 of the original')->fontFamily('mono')->columnSpan(3),
                TextEntry::make('reason')->label('Correction reason')->placeholder('—')->columnSpan(2),
                TextEntry::make('status')->state(fn (GeneratedDocument $r) => $r->isCurrent() ? 'Valid' : 'Replaced by '.$r->supersededBy?->displayNumber()),
            ]),
            Section::make('Access log')->schema([
                RepeatableEntry::make('log')->hiddenLabel()->columns(4)
                    ->state(fn (GeneratedDocument $r) => DocumentAccessLog::query()->where('generated_document_id', $r->id)->orderByDesc('created_at')->limit(100)->get()
                        ->map(fn (DocumentAccessLog $l) => [
                            'when' => $l->created_at->timezone('Asia/Karachi')->format('d M Y H:i'), 'action' => DocumentAccessLog::ACTIONS[$l->action] ?? $l->action,
                            'who' => $l->account_id !== null ? (LoginAccount::query()->whereKey($l->account_id)->value('username') ?? '—') : ($l->share_id !== null ? 'Shared link' : '—'),
                            'detail' => collect($l->meta ?? [])->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? var_export($v, true) : json_encode($v)))->implode(', '),
                        ])->all())
                    ->schema([TextEntry::make('when'), TextEntry::make('action'), TextEntry::make('who'), TextEntry::make('detail')->placeholder('—')]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\SharesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGeneratedDocuments::route('/'),
            'view' => Pages\ViewGeneratedDocument::route('/{record}'),
        ];
    }
}
