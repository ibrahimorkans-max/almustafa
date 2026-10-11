<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

use App\Domain\Documents\PdfRenderer;
use App\Models\Entity;
use App\Models\Hr\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Salary certificate on request (bank, embassy, rental …): the employee's service and current
 * monthly gross from the salary structure in force. A new numbered certificate each time it is asked.
 */
class SalaryCertificateDocument extends BaseDocument
{
    public function key(): string
    {
        return 'salary_certificate';
    }

    public function label(): string
    {
        return 'Salary certificate';
    }

    public function sourceClass(): string
    {
        return Employee::class;
    }

    public function isFinal(Model $source): bool
    {
        /** @var Employee $source */
        return $source->status !== Employee::EXITED;
    }

    public function number(Model $source): ?string
    {
        return null;
    }

    public function series(): string
    {
        return 'CERT';
    }

    public function oncePerSource(): bool
    {
        return false;
    }

    public function employeeId(Model $source): ?string
    {
        return (string) $source->getKey();
    }

    public function build(Model $source, array $options): array
    {
        /** @var Employee $source */
        $source->loadMissing(['designation', 'department', 'person']);
        $today = CarbonImmutable::now('Asia/Karachi');
        $gross = $this->monthlyGross($source, $today);
        $purpose = trim((string) ($options['purpose'] ?? ''));
        $vars = [
            'name' => $source->name(), 'cnic' => ['s' => 'cnic', 'masked' => $source->maskedCnic()], 'mill' => PdfRenderer::brand(Entity::query()->find($source->entity_id))['name'],
            'designation' => (string) ($source->designation->name ?? '—'), 'department' => (string) ($source->department->name ?? '—'),
            'joining' => ['d' => $source->joining_date->toDateString()], 'gross' => ['m' => $gross], 'words' => ['w' => $gross],
        ];

        return [
            'title' => $this->key(),
            'date' => $today->toDateString(),
            'fields' => [['date', ['d' => $today->toDateString()]], ...$this->employeeFields($source), ['joining_date', ['d' => $source->joining_date->toDateString()]]],
            'paragraphs' => array_values(array_filter([
                [$this->key(), $vars],
                $purpose !== '' ? ['certificate_purpose', ['purpose' => mb_substr($purpose, 0, 120)]] : null,
            ])),
            'signatures' => ['hr_manager', 'authorised'],
        ];
    }

    /** Monthly gross of the structure in force (a daily wage × 26 working days). */
    protected function monthlyGross(Employee $e, CarbonImmutable $day): int
    {
        $structure = $e->salaryOn($day);
        if ($structure === null) {
            return 0;
        }
        $sum = (int) $structure->lines->filter(fn ($l) => $l->component?->kind === 'earning')->sum('amount_paise');

        return $structure->pay_basis === 'daily' ? $sum * 26 : $sum;
    }
}
