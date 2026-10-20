<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Files\Attachments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Attachment;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The "Attach file" button and the "Attachments" section of a record's page (App\Domain\Files\Attachments).
 */
final class AttachmentsPanel
{
    public static function action(): Action
    {
        return Action::make('attach')->label('Attach file')->icon('heroicon-o-paper-clip')->color('gray')
            ->modalDescription('A photo (JPEG, PNG) or a PDF, up to 5 MB. Attached files cannot be changed or removed.')
            ->form(fn (Model $record) => [
                Select::make('kind')->label('Document')->required()->options(Attachments::kindsFor($record)),
                FileUpload::make('file')->required()->storeFiles(false)->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(5120),
                TextInput::make('note')->maxLength(300),
            ])
            ->action(function (Model $record, array $data): void {
                $file = $data['file'] ?? null;
                if (is_array($file)) {
                    $file = reset($file) ?: null;
                }
                try {
                    if (! $file instanceof TemporaryUploadedFile) {
                        throw new BusinessRuleViolation('Choose a file.');
                    }
                    app(Attachments::class)->attach($record, (string) $data['kind'], (string) $file->get(), $data['note'] ?? null);
                } catch (BusinessRuleViolation $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('File attached')->success()->send();
            });
    }

    public static function section(): Section
    {
        return Section::make('Attachments')->collapsible()->schema([
            TextEntry::make('attachments_list')->hiddenLabel()->html()
                ->state(function (Model $record): string {
                    $items = app(Attachments::class)->of($record);
                    if ($items->isEmpty()) {
                        return '<span class="text-sm text-gray-500">No files attached.</span>';
                    }

                    return '<ul class="space-y-1 text-sm">'.$items->map(fn (Attachment $a) => '<li><a class="text-primary-600 hover:underline" target="_blank" href="'
                        .e(route('filament.admin.attachment', ['id' => $a->id])).'">'.e(Attachments::KINDS[$a->kind] ?? $a->kind).'</a>'
                        .($a->note ? ' — '.e($a->note) : '').' <span class="text-xs text-gray-500">('.e($a->uploader->username ?? '—').', '
                        .e($a->created_at->timezone('Asia/Karachi')->format('d M Y H:i')).', '.number_format($a->file->size_bytes / 1024, 0).' KB)</span></li>')
                        ->implode('').'</ul>';
                }),
        ]);
    }
}
