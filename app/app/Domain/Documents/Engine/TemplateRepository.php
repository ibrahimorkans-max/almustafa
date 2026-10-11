<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Documents\DocumentTemplate;
use App\Models\Documents\DocumentTemplateVersion;
use App\Models\StoredFile;
use Illuminate\Support\Facades\DB;

/**
 * Templates per document type: four standard ones (one per layout, Classic the default) are created
 * the first time a type is used; the template manager adds more, changes their look as new versions
 * and picks the default. Toggles set here are the defaults; each generation may override them.
 */
final class TemplateRepository
{
    public const TOGGLES = [
        'employer' => 'Employer contributions', 'ytd' => 'Tax year to date', 'leave' => 'Leave balance', 'calendar' => 'Attendance calendar',
        'loan' => 'Advance / loan balance', 'qr' => 'QR verification', 'stamp' => 'Company stamp', 'signatures' => 'Signature blocks',
    ];

    public const DEFAULT_SETTINGS = [
        'header_text' => '', 'footer_text' => '', 'terms' => '', 'show_ntn' => true, 'accent' => null, 'stamp_file_id' => null, 'signature_file_id' => null,
        'toggles' => ['employer' => false, 'ytd' => true, 'leave' => true, 'calendar' => false, 'loan' => true, 'qr' => true, 'stamp' => false, 'signatures' => true],
    ];

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /** The current version of the type's default template, or of the template in the given layout. */
    public function resolve(string $docType, ?string $layout = null, ?string $templateId = null): DocumentTemplateVersion
    {
        $this->installStandard($docType);
        $query = DocumentTemplate::query()->where('doc_type', $docType)->where('status', 'active');
        $template = match (true) {
            $templateId !== null => (clone $query)->whereKey($templateId)->first(),
            $layout !== null => (clone $query)->where('layout', $layout)->orderByDesc('is_default')->orderBy('name')->first(),
            default => (clone $query)->where('is_default', true)->first(),
        } ?? (clone $query)->orderByDesc('is_default')->first() ?? throw new BusinessRuleViolation('No active template for this document.');

        return $template->latest ?? throw new BusinessRuleViolation("Template {$template->name} has no version.");
    }

    /** @param  array<string, mixed>  $settings */
    public function create(string $docType, string $name, string $layout, array $settings, string $note): DocumentTemplate
    {
        $this->require();
        DocumentTypes::get($docType);
        if (! array_key_exists($layout, DocumentTemplate::LAYOUTS)) {
            throw new BusinessRuleViolation('Unknown layout.');
        }
        if (DocumentTemplate::query()->where('doc_type', $docType)->where('name', trim($name))->exists()) {
            throw new BusinessRuleViolation('A template of this document with that name exists.');
        }

        return DB::transaction(function () use ($docType, $name, $layout, $settings, $note): DocumentTemplate {
            $template = DocumentTemplate::query()->create(['doc_type' => $docType, 'name' => trim($name), 'layout' => $layout, 'is_default' => false]);
            DocumentTemplateVersion::query()->create(['template_id' => $template->id, 'version_no' => 1, 'settings' => $this->validated($settings), 'note' => $note, 'created_at' => now()]);

            return $template;
        });
    }

    /** A change of look is a new version; documents already issued keep the version they were made with. */
    /** @param  array<string, mixed>  $settings */
    public function newVersion(DocumentTemplate $template, array $settings, string $note): DocumentTemplateVersion
    {
        $this->require();
        if (mb_strlen(trim($note)) < 3) {
            throw new BusinessRuleViolation('Say what changed.');
        }

        return DB::transaction(function () use ($template, $settings, $note): DocumentTemplateVersion {
            $template = DocumentTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $next = (int) $template->versions()->max('version_no') + 1;
            $version = DocumentTemplateVersion::query()->create([
                'template_id' => $template->id, 'version_no' => $next, 'settings' => $this->validated($settings), 'note' => trim($note), 'created_at' => now(),
            ]);
            $this->audit->record('documents.template_versioned', $template, null, null, ['version' => $next, 'note' => trim($note)]);

            return $version;
        });
    }

    public function makeDefault(DocumentTemplate $template): void
    {
        $this->require();
        if ($template->status !== 'active') {
            throw new BusinessRuleViolation('Only an active template can be the default.');
        }
        DB::transaction(function () use ($template): void {
            DocumentTemplate::query()->where('doc_type', $template->doc_type)->where('is_default', true)->get()
                ->each(fn (DocumentTemplate $t) => $t->forceFill(['is_default' => false])->save());
            $template->forceFill(['is_default' => true])->save();
        });
    }

    public function installStandard(string $docType): void
    {
        if (DocumentTemplate::query()->where('doc_type', $docType)->exists()) {
            return;
        }
        DB::transaction(function () use ($docType): void {
            foreach (DocumentTemplate::LAYOUTS as $layout => $label) {
                $template = DocumentTemplate::query()->create(['doc_type' => $docType, 'name' => 'Standard '.strtolower(strtok($label, ' ') ?: $label),
                    'layout' => $layout, 'is_default' => $layout === 'classic']);
                DocumentTemplateVersion::query()->create(['template_id' => $template->id, 'version_no' => 1,
                    'settings' => self::DEFAULT_SETTINGS, 'note' => 'Standard template', 'created_at' => now()]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function validated(array $settings): array
    {
        $out = self::DEFAULT_SETTINGS;
        foreach (['header_text' => 200, 'footer_text' => 300, 'terms' => 1000] as $key => $max) {
            $v = trim((string) ($settings[$key] ?? ''));
            if (mb_strlen($v) > $max) {
                throw new BusinessRuleViolation("The {$key} is longer than {$max} characters.");
            }
            $out[$key] = $v;
        }
        $out['show_ntn'] = (bool) ($settings['show_ntn'] ?? true);
        $accent = $settings['accent'] ?? null;
        if ($accent !== null && $accent !== '' && ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $accent)) {
            throw new BusinessRuleViolation('The colour must look like #1F5F3A.');
        }
        $out['accent'] = $accent ?: null;
        foreach (['stamp_file_id', 'signature_file_id'] as $key) {
            $id = $settings[$key] ?? null;
            if ($id !== null && $id !== '') {
                $file = StoredFile::query()->find($id);
                if ($file === null || ! in_array($file->mime, ['image/png', 'image/jpeg'], true)) {
                    throw new BusinessRuleViolation('The stamp and signature must be PNG or JPEG images.');
                }
            }
            $out[$key] = $id ?: null;
        }
        foreach (array_keys(self::TOGGLES) as $t) {
            $out['toggles'][$t] = (bool) ($settings['toggles'][$t] ?? self::DEFAULT_SETTINGS['toggles'][$t]);
        }

        return $out;
    }

    private function require(): void
    {
        if (! ($this->actor->account()?->hasPermission('hr.documents.template.manage') ?? false)) {
            throw new BusinessRuleViolation('Managing document templates needs the permission hr.documents.template.manage.');
        }
    }
}
