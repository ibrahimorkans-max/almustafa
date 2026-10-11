<?php

declare(strict_types=1);

namespace App\Filament\Resources\Hr\AttendanceLogResource\Pages;

use App\Domain\Approvals\Actions\ManualAttendance;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Hr\Attendance\AttendanceImport;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Hr\AttendanceLogResource;
use App\Models\Hr\Employee;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListAttendanceLogs extends ListRecords
{
    protected static string $resource = AttendanceLogResource::class;

    protected function getHeaderActions(): array
    {
        $canOverride = function (): bool {
            $user = Filament::auth()->user();

            return $user instanceof LoginAccount && $user->hasPermission('hr.attendance.override');
        };
        // A single upload is the file object itself; (array) on it would give its properties, not the file.
        $read = function (mixed $state): string {
            $file = is_array($state) ? reset($state) : $state;

            return $file instanceof TemporaryUploadedFile ? (string) $file->get() : '';
        };

        return [
            Action::make('import')->label('Import from biometric file')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->visible($canOverride)
                ->steps([
                    Step::make('File')->description('CSV: employee code, date-time (PKT), in / out (optional)')->schema([
                        FileUpload::make('file')->required()->storeFiles(false)->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->maxSize(5120),
                    ])->afterValidation(function (Get $get, Set $set) use ($read): void {
                        $p = app(AttendanceImport::class)->preview($read($get('file')));
                        $new = count(array_filter($p['rows'], fn ($r) => ! $r['duplicate']));
                        $inferred = count(array_filter($p['rows'], fn ($r) => $r['inferred'] && ! $r['duplicate']));
                        $set('summary', "{$new} new punch(es) to import, ".(count($p['rows']) - $new).' already imported (skipped), '
                            ."{$inferred} with in / out worked out from the order. ".(count($p['errors']) ? count($p['errors']).' line(s) cannot be read: '.implode(' ', array_slice($p['errors'], 0, 5)) : 'No errors.'));
                    }),
                    Step::make('Check')->schema([
                        Placeholder::make('summary_view')->label('What will happen')->content(fn (Get $get) => $get('summary')),
                        Hidden::make('summary'),
                    ]),
                ])
                ->action(function (array $data) use ($read): void {
                    try {
                        $r = app(AttendanceImport::class)->import($read($data['file'] ?? null));
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title("{$r['imported']} punch(es) imported, {$r['duplicates']} skipped as already imported.")
                        ->body($r['errors'] ? count($r['errors']).' line(s) could not be read and were left out.' : null)->success()->send();
                }),
            Action::make('manual')->label('Request manual attendance')->icon('heroicon-o-pencil-square')
                ->visible(function (): bool {
                    $user = Filament::auth()->user();

                    return $user instanceof LoginAccount && $user->hasPermission('hr.attendance.override');
                })
                ->modalDescription('For a forgotten phone, no network at a remote point, or to correct a rejected punch. It counts only after a different person approves it.')
                ->form([
                    Select::make('employee_id')->label('Employee')->required()->searchable()
                        ->options(fn () => Employee::query()->with('person')->where('status', '!=', Employee::EXITED)->orderBy('code')->get()
                            ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->code} {$e->name()}"])->all()),
                    Select::make('kind')->options(['in' => 'In', 'out' => 'Out'])->required(),
                    // Entered and read as PKT wall time (the approval action converts it once; future times are refused there).
                    DateTimePicker::make('at')->label('Time (PKT)')->required()->seconds(false),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        $employee = Employee::query()->with('person')->findOrFail($data['employee_id']);
                        app(ApprovalService::class)->submit(ManualAttendance::KEY, $data,
                            "Manual attendance {$data['kind']} for {$employee->code} {$employee->name()}", $data['reason']);
                    } catch (ApprovalDenied $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first() ?? 'Check the entries.')->danger()->send();

                        return;
                    }
                    Notification::make()->title('Sent for approval.')->body('The punch is created when a different person approves it.')->success()->send();
                }),
        ];
    }
}
