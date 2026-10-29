<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Documents\Engine\DocumentPresenter;
use App\Domain\Documents\Engine\DocumentRenderer;
use App\Domain\Documents\Engine\DocumentService;
use App\Domain\Documents\Engine\DocumentTypes;
use App\Domain\Documents\Engine\TemplateRepository;
use App\Domain\Documents\PrintDelivery;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Documents\DocumentTemplate;
use App\Models\Documents\GeneratedDocument;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "print / download" buttons on records (payslip, voucher, receipt, certificate …). A final
 * record is issued (once) and its original handed out; asking for another layout, language, paper
 * or format gives a counted DUPLICATE. A record that is not final yet gives a DRAFT preview.
 */
final class DocumentActions
{
    /** Print opens the print dialog (PrintDelivery); a file is saved only when asked for. */
    public const DELIVERY = ['print' => 'Print now (print dialog)', 'download' => 'Download the file'];

    public static function allowed(string $permission = 'hr.documents.generate'): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission($permission);
    }

    /** @return list<Component> */
    public static function form(string $type, bool $extra = false): array
    {
        $t = DocumentTypes::get($type);
        $fields = [
            Select::make('layout')->placeholder('Template default')->options(DocumentTemplate::LAYOUTS),
            Select::make('language')->options(DocumentPresenter::LANGUAGES)->default('bilingual')->required(),
            Select::make('paper')->options(DocumentRenderer::PAPERS)->default($t->defaultPaper())->required(),
            Select::make('format')->options(DocumentRenderer::FORMATS)->default('pdf')->required(),
            Select::make('digits')->options(['western' => 'Western (123)', 'urdu' => 'Urdu (۱۲۳)'])->default('western')->required(),
            Select::make('deliver')->label('Output')->options(self::DELIVERY)->default('print')->required(),
        ];
        if (in_array($type, ['salary_certificate', 'employment_letter'], true)) {
            $fields[] = TextInput::make('purpose')->label('Purpose (printed)')->maxLength(120)->placeholder('e.g. bank account opening');
            $fields[] = Select::make('unmask')->label('CNIC')->options([0 => 'Masked', 1 => 'Full (audited)'])->default(0)
                ->visible(fn () => self::allowed('hr.employee.sensitive.view'));
        }
        if ($extra) {
            $fields[] = CheckboxList::make('toggles')->label('Show')->columns(2)->options(TemplateRepository::TOGGLES);
            $fields[] = Select::make('password')->label('Protect the PDF with')->options(['none' => 'No password', 'cnic5' => 'Last 5 digits of the CNIC', 'pin' => 'A PIN'])->default('none')->live();
            $fields[] = TextInput::make('pin')->label('PIN')->password()->revealable()->regex('/^\d{4,12}$/')->visible(fn ($get) => $get('password') === 'pin');
            $fields[] = Select::make('unmask')->label('CNIC / bank account')->options([0 => 'Masked', 1 => 'Full (audited)'])->default(0)
                ->visible(fn () => self::allowed('hr.employee.sensitive.view'));
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $data */
    public static function run(string $type, string $sourceId, array $data): ?StreamedResponse
    {
        $svc = app(DocumentService::class);
        $t = DocumentTypes::get($type);
        $options = self::options($data);
        try {
            $source = $t->sourceClass()::query()->findOrFail($sourceId);
            if (! $t->isFinal($source)) {
                $out = $svc->preview($type, $sourceId, $options);
                Notification::make()->title('Draft preview — not final yet, so not issued.')->warning()->send();

                return self::stream($out, $data['deliver'] ?? 'download');
            }
            $doc = $svc->issue($type, $sourceId, $options);

            // Just issued with these options, or asked for as issued: the original; otherwise a counted DUPLICATE.
            return self::stream($doc->wasRecentlyCreated || $svc->asIssued($doc, $options) ? $svc->original($doc) : $svc->reprint($doc, $options), $data['deliver'] ?? 'download');
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function reprint(GeneratedDocument $doc, array $data): ?StreamedResponse
    {
        try {
            return self::stream(app(DocumentService::class)->reprint($doc, self::options($data)), $data['deliver'] ?? 'download');
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }
    }

    /**
     * A PDF or printable page asked to print goes to the print dialog of the page; anything else (and a
     * download asked for) is saved as a file.
     *
     * @param  array{bytes: string, mime: string, name: string}  $out
     */
    public static function stream(array $out, string $deliver = 'download'): ?StreamedResponse
    {
        $page = Livewire::current();
        if ($deliver === 'print' && $page !== null && in_array($out['mime'], ['application/pdf', 'text/html'], true)) {
            $page->dispatch('rm-print', url: PrintDelivery::stash($out['bytes'], $out['name'], $out['mime']));

            return null;
        }

        return response()->streamDownload(fn () => print $out['bytes'], $out['name'], ['Content-Type' => $out['mime']]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function options(array $data): array
    {
        $o = array_filter([
            'layout' => $data['layout'] ?? null, 'language' => $data['language'] ?? 'bilingual', 'paper' => $data['paper'] ?? null,
            'format' => $data['format'] ?? 'pdf', 'digits' => $data['digits'] ?? 'western',
            'password' => $data['password'] ?? null, 'pin' => $data['pin'] ?? null, 'unmask' => (bool) ($data['unmask'] ?? false),
        ], fn ($v) => $v !== null && $v !== '');
        if (array_key_exists('toggles', $data) && is_array($data['toggles'])) {
            $o['toggles'] = array_fill_keys(array_keys(TemplateRepository::TOGGLES), false);
            foreach ($data['toggles'] as $k) {
                $o['toggles'][$k] = true;
            }
        }
        if (filled($data['purpose'] ?? null)) {
            $o['type'] = ['purpose' => (string) $data['purpose']];
        }

        return $o;
    }
}
