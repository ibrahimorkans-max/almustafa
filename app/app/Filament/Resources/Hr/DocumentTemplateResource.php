<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr;

use App\Domain\Documents\Engine\DocumentTypes;
use App\Domain\Documents\Engine\TemplateRepository;
use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\DocumentTemplateResource\Pages;
use App\Models\Documents\DocumentTemplate;
use App\Models\Documents\DocumentTemplateVersion;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** Document templates: four layouts per document, the look kept in versions, one default per document. */
class DocumentTemplateResource extends Resource
{
    protected static ?string $model = DocumentTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $navigationGroup = HrScope::GROUP;

    protected static ?string $navigationLabel = 'Document templates';

    protected static ?string $slug = 'hr/document-templates';

    protected static ?int $navigationSort = 73;

    /** @return list<Component> */
    public static function settingsForm(): array
    {
        $image = fn (string $name, string $label) => FileUpload::make($name)->label($label)->storeFiles(false)->image()
            ->acceptedFileTypes(['image/png', 'image/jpeg'])->maxSize(1024)->helperText('PNG with a transparent background prints best. Leave empty to keep the current one.');

        return [
            TextInput::make('header_text')->label('Line under the title')->maxLength(200),
            Textarea::make('terms')->label('Terms / notes printed at the end')->maxLength(1000)->rows(3),
            TextInput::make('footer_text')->label('Footer line')->maxLength(300),
            ColorPicker::make('accent')->label('Accent colour (empty = brand green)')->regex('/^#[0-9a-fA-F]{6}$/'),
            Toggle::make('show_ntn')->label('Show NTN / STRN in the letterhead')->default(true),
            CheckboxList::make('toggles')->label('Shown by default')->columns(2)->options(TemplateRepository::TOGGLES),
            $image('stamp', 'Company stamp'),
            $image('signature', 'Signature image (authorised signatory)'),
            Textarea::make('note')->label('What changed')->required()->minLength(3)->maxLength(300),
        ];
    }

    /**
     * Form state → stored settings; uploads go to the file store, an empty upload keeps the previous image.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    public static function settings(array $data, array $previous = []): array
    {
        $file = function (mixed $upload, ?string $keep): ?string {
            $upload = is_array($upload) ? (array_values($upload)[0] ?? null) : $upload;
            if ($upload === null || $upload === '') {
                return $keep;
            }
            $bytes = $upload instanceof TemporaryUploadedFile ? (string) $upload->get() : (string) file_get_contents((string) $upload);

            return app(FileStore::class)->put($bytes)->id;
        };
        $toggles = array_fill_keys(array_keys(TemplateRepository::TOGGLES), false);
        foreach ((array) ($data['toggles'] ?? []) as $k) {
            $toggles[$k] = true;
        }

        return [
            'header_text' => $data['header_text'] ?? '', 'footer_text' => $data['footer_text'] ?? '', 'terms' => $data['terms'] ?? '',
            'accent' => $data['accent'] ?? null, 'show_ntn' => (bool) ($data['show_ntn'] ?? true), 'toggles' => $toggles,
            'stamp_file_id' => $file($data['stamp'] ?? null, $previous['stamp_file_id'] ?? null),
            'signature_file_id' => $file($data['signature'] ?? null, $previous['signature_file_id'] ?? null),
        ];
    }

    public static function table(Table $table): Table
    {
        $repo = fn () => app(TemplateRepository::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('latest'))
            ->columns([
                TextColumn::make('doc_type')->label('Document')->formatStateUsing(fn (string $state) => DocumentTypes::options()[$state] ?? $state)->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('layout')->formatStateUsing(fn (string $state) => DocumentTemplate::LAYOUTS[$state] ?? $state),
                TextColumn::make('latest.version_no')->label('Version'),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('doc_type')
            ->filters([SelectFilter::make('doc_type')->label('Document')->options(DocumentTypes::options())])
            ->actions([
                Action::make('version')->label('Change look')->icon('heroicon-o-paint-brush')
                    ->form(self::settingsForm())
                    ->fillForm(fn (DocumentTemplate $record) => ($s = $record->latest->settings ?? TemplateRepository::DEFAULT_SETTINGS) + [
                        'toggles' => array_keys(array_filter((array) ($s['toggles'] ?? []))), 'note' => null,
                    ])
                    ->action(function (DocumentTemplate $record, array $data) use ($repo): void {
                        try {
                            $v = $repo()->newVersion($record, self::settings($data, $record->latest->settings ?? []), (string) $data['note']);
                            Notification::make()->title("Version {$v->version_no} saved. Documents already issued keep their version.")->success()->send();
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('default')->label('Make default')->icon('heroicon-o-star')->color('gray')
                    ->visible(fn (DocumentTemplate $r) => ! $r->is_default && $r->status === 'active')
                    ->action(function (DocumentTemplate $record) use ($repo): void {
                        try {
                            $repo()->makeDefault($record);
                            Notification::make()->title('Default changed.')->success()->send();
                        } catch (BusinessRuleViolation $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('versions')->label('History')->icon('heroicon-o-clock')->color('gray')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (DocumentTemplate $record) => view('filament.documents.template-versions', [
                        'versions' => DocumentTemplateVersion::query()->where('template_id', $record->id)->orderByDesc('version_no')->get(),
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDocumentTemplates::route('/')];
    }
}
