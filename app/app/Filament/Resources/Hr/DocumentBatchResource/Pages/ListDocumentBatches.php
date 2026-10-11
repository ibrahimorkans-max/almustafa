<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\DocumentBatchResource\Pages;

use App\Domain\Documents\Engine\DocumentBatches;
use App\Domain\Documents\Engine\DocumentPresenter;
use App\Domain\Documents\Engine\DocumentRenderer;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\DocumentBatchResource;
use App\Filament\Support\DocumentActions;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunAttendance;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDocumentBatches extends ListRecords
{
    protected static string $resource = DocumentBatchResource::class;

    public const KINDS = [
        'payslip' => 'Payslips of a month', 'attendance_summary' => 'Attendance summaries of a month',
        'employment_letter' => 'Employment certificates', 'salary_certificate' => 'Salary certificates',
    ];

    protected function getHeaderActions(): array
    {
        $monthly = fn (Get $get) => in_array($get('doc_type'), ['payslip', 'attendance_summary'], true);

        return [
            Action::make('start')->label('New bulk generation')->icon('heroicon-o-plus')
                ->visible(fn () => DocumentActions::allowed())
                ->form([
                    Select::make('doc_type')->label('Documents')->options(self::KINDS)->default('payslip')->required()->live(),
                    Select::make('run')->label('Month (finalized payroll)')->required($monthly)->visible($monthly)
                        ->options(fn () => PayrollRun::query()->where('status', PayrollRun::FINALIZED)->orderByDesc('month')->pluck('month', 'id')),
                    Select::make('department')->label('Department (empty = all)')
                        ->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')),
                    Select::make('language')->options(DocumentPresenter::LANGUAGES)->default('bilingual')->required(),
                    Select::make('paper')->options(DocumentRenderer::PAPERS)->default('half_a4')->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        [$ids, $label] = $this->sources($data);
                        $batch = app(DocumentBatches::class)->start($data['doc_type'], $ids, $label, ['language' => $data['language'], 'paper' => $data['paper']]);
                        Notification::make()->title("Started: {$batch->total} documents. The list shows the progress.")->success()->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: list<string>, 1: string}
     */
    private function sources(array $data): array
    {
        $type = (string) $data['doc_type'];
        $dept = filled($data['department'] ?? null) ? (string) $data['department'] : null;
        $deptName = $dept !== null ? ' — '.Department::query()->whereKey($dept)->value('name') : '';
        $inDept = fn () => Employee::query()->when($dept !== null, fn ($q) => $q->where('department_id', $dept))->select('id');
        if (in_array($type, ['payslip', 'attendance_summary'], true)) {
            $run = PayrollRun::query()->where('status', PayrollRun::FINALIZED)->findOrFail($data['run']);
            $ids = $type === 'payslip'
                ? $run->currentItems()->whereIn('employee_id', $inDept())->orderBy('employee->code')->pluck('id')->all()
                : PayrollRunAttendance::query()->where('payroll_run_id', $run->id)->where('lock_no', $run->lock_no)->whereIn('employee_id', $inDept())->pluck('id')->all();

            return [$ids, self::KINDS[$type]." {$run->month}{$deptName}"];
        }
        $ids = Employee::query()->where('status', '!=', Employee::EXITED)->when($dept !== null, fn ($q) => $q->where('department_id', $dept))
            ->orderBy('code')->pluck('id')->all();

        return [$ids, self::KINDS[$type].' '.now('Asia/Karachi')->format('d M Y').$deptName];
    }
}
