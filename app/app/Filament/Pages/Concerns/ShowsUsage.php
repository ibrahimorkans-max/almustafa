<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Usage\UsageReport;
use App\Models\Entity;
use Filament\Actions\Action;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The usage screens' filters (period, channel, company, mill, provider), their figures and the Excel / PDF export.
 * The SaaS owner's screen reads every company; a mill's screen only its own company (row security and the filter).
 */
trait ShowsUsage
{
    public string $period = '30d';

    public ?string $from = null;

    public ?string $to = null;

    public ?string $channel = null;

    public ?string $customer = null;

    public ?string $entity = null;

    public ?string $provider = null;

    abstract public function forPlatform(): bool;

    public function report(): UsageReport
    {
        $filters = ['period' => array_key_exists($this->period, UsageReport::PERIODS) ? $this->period : '30d',
            'from' => $this->dateOrNull($this->from), 'to' => $this->dateOrNull($this->to),
            'channel' => $this->channel ?: null, 'entity' => $this->entity ?: null, 'provider' => $this->provider ?: null,
            'customer' => $this->forPlatform() ? ($this->customer ?: null) : app(TenantContext::class)->customerId()];

        return new UsageReport($filters, $this->forPlatform());
    }

    /** @return array<string, string> the mills that may be chosen */
    public function millOptions(): array
    {
        $read = fn () => Entity::query()->when(! $this->forPlatform(), fn ($q) => $q->where('customer_id', app(TenantContext::class)->customerId()))
            ->when($this->forPlatform() && $this->customer, fn ($q) => $q->where('customer_id', $this->customer))
            ->orderBy('name')->pluck('name', 'id')->all();

        return $this->forPlatform() ? app(TenantContext::class)->runAsSystem($read) : $read();
    }

    public function showCompany(string $customerId): void
    {
        $this->customer = $customerId;
        $this->entity = null;
    }

    public function exportExcelAction(): Action
    {
        return Action::make('exportExcel')->label('Excel')->icon('heroicon-o-table-cells')->color('gray')
            ->action(function (): StreamedResponse {
                app(AuditLogger::class)->record('usage.exported', null, null, null, ['format' => 'xlsx', 'period' => $this->period]);
                $bytes = $this->report()->excel();

                return response()->streamDownload(fn () => print $bytes, 'service-usage-'.now('Asia/Karachi')->format('Y-m-d').'.xlsx',
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
            });
    }

    public function exportPdfAction(): Action
    {
        return Action::make('exportPdf')->label('PDF')->icon('heroicon-o-document-arrow-down')->color('gray')
            ->action(function (): StreamedResponse {
                app(AuditLogger::class)->record('usage.exported', null, null, null, ['format' => 'pdf', 'period' => $this->period]);
                $r = $this->report();
                [$from, $to] = $r->range();
                $entity = app(TenantContext::class)->entityId() !== null ? app(TenantContext::class)->runAsSystem(fn () => Entity::query()->find(app(TenantContext::class)->entityId())) : null;
                $pdf = app(PdfRenderer::class)->render('pdf.service-usage', [
                    'title' => 'Service usage', 'from' => $from->timezone('Asia/Karachi'), 'to' => $to->timezone('Asia/Karachi'),
                    'totals' => $r->totals(), 'byMill' => $r->by('entity'), 'byProvider' => $r->by('provider'), 'byModule' => $r->by('module'),
                    'records' => $r->records(300), 'platform' => $this->forPlatform(),
                ], $entity, 'A4');

                return response()->streamDownload(fn () => print $pdf, 'service-usage-'.now('Asia/Karachi')->format('Y-m-d').'.pdf', ['Content-Type' => 'application/pdf']);
            });
    }

    private function dateOrNull(?string $d): ?string
    {
        return $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : null;
    }
}
