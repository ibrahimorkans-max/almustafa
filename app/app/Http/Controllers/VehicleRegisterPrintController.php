<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Gate\VehicleRegister;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Printing from the vehicle register (opened in the browser, ready to print):
 *  GET /admin/vehicle-register/vehicle/{id}    one vehicle's slip: details, weights, how weight and price were worked out
 *  GET /admin/vehicle-register/weighment/{id}  the official weighment slip (once issued)
 *  GET /admin/vehicle-register/day/{date}      the end-of-day register of all vehicles in and out (?side=inbound|outbound);
 *                                              with ?slips=1 every vehicle's complete slip, one per page
 * The vehicle is looked up inside the working mill; the person needs the right to see gate entries.
 */
final class VehicleRegisterPrintController
{
    public function vehicle(string $id, VehicleRegister $register, AuditLogger $audit): Response
    {
        $entry = $this->entry($id);
        $audit->record('document.printed', $entry, meta: ['type' => 'vehicle_slip']);

        return $this->pdf($register->vehicleSlipPdf($entry), 'vehicle-slip-'.$entry->number);
    }

    public function weighment(string $id, SlipRenderer $slips, AuditLogger $audit): Response
    {
        $entry = $this->entry($id);
        try {
            $pdf = $slips->pdf($entry);
        } catch (BusinessRuleViolation $e) {
            abort(409, $e->getMessage());
        }
        $audit->record('document.printed', $entry, meta: ['type' => 'weighment_slip']);

        return $this->pdf($pdf, 'weighment-slip-'.$entry->number);
    }

    public function day(string $date, Request $request, VehicleRegister $register, AuditLogger $audit): Response
    {
        $this->allow();
        $side = $request->query('side');
        $side = is_string($side) && array_key_exists($side, VehicleRegister::SIDES) ? $side : null;
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Karachi') ?: abort(404);
        } catch (\Throwable) {
            abort(404);
        }
        $slips = $request->boolean('slips');
        $audit->record('report.vehicle_register.printed', null, null, null, ['date' => $day->toDateString(), 'side' => $side, 'slips' => $slips]);

        return $slips
            ? $this->pdf($register->daySlipsPdf($day, $side), 'vehicle-slips-'.$day->toDateString())
            : $this->pdf($register->dayPdf($day, $side), 'vehicle-register-'.$day->toDateString());
    }

    private function entry(string $id): GateEntry
    {
        $this->allow();
        $entry = GateEntry::query()->whereNull('archived_at')->find($id) ?? abort(404);
        abort_unless(auth('web')->user()?->can('view', $entry), 403);

        return $entry;
    }

    private function allow(): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof LoginAccount && $user->can('viewAny', GateEntry::class), 403);
    }

    private function pdf(string $pdf, string $name): Response
    {
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'.pdf"', 'X-Print-Frame' => '1']);
    }
}
