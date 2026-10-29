<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\PrintDelivery;
use Illuminate\Http\Response;

/** GET /admin/print-job/{key}: a file waiting for the print dialog (PrintDelivery), shown inline in this site's own frame. */
final class PrintJobController
{
    public function __invoke(string $key): Response
    {
        $job = PrintDelivery::take($key) ?? abort(410, 'This print has expired. Press Print again.');

        return response($job['bytes'], 200, [
            'Content-Type' => $job['mime'],
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $job['name']).'"',
            'Cache-Control' => 'no-store, private',
            'X-Print-Frame' => '1',
        ]);
    }
}
