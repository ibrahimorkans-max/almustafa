<?php

declare(strict_types=1);

use App\Domain\Documents\Engine\DocumentRenderer;
use App\Domain\Documents\PaperSize;
use App\Domain\Documents\PdfRenderer;
use Tests\Support\Make;

/*
 * Every invoice, slip and report prints portrait and at actual size: the page is the real paper and the
 * PDF tells the print dialog not to scale it ("Actual size", /PrintScaling /None).
 */
beforeEach(function () {
    $this->entity = Make::entity('PRT');
});

/** A wide list, as the screen printer and the registers make them. */
function wideSheet(): array
{
    $columns = array_map(fn (int $i) => 'Column '.$i, range(1, 16));

    return ['title' => 'Wide register', 'lines' => [], 'columns' => $columns, 'fields' => [],
        'rows' => array_fill(0, 5, array_map(fn (int $i) => 'Value '.str_repeat('x', 12).$i, range(1, 16)))];
}

it('prints a report that used to be landscape on an upright A4 page, at actual size', function () {
    $pdf = app(PdfRenderer::class)->render('reports.screen', ['sheet' => wideSheet(), 'printedAt' => now(), 'printedBy' => 'qa'], $this->entity, 'A4-L', paper: 'a4');

    expect($pdf)->toContain('/MediaBox [0 0 595.280 841.890]')   // 210 × 297 mm, portrait
        ->not->toContain('/MediaBox [0 0 841.890 595.280]')
        ->toContain('/PrintScaling /None');
});

it('keeps every chosen paper upright and at its real size', function (string $paper, string $box) {
    $pdf = app(PdfRenderer::class)->render('reports.screen', ['sheet' => wideSheet(), 'printedAt' => now(), 'printedBy' => 'qa'], $this->entity, 'A4-L', paper: $paper);

    expect($pdf)->toContain('/MediaBox [0 0 '.$box)->toContain('/PrintScaling /None');
})->with([
    'A4' => ['a4', '595.280 841.890'],
    'Letter' => ['letter', '612.000 792.000'],
    'A6' => ['a6', '297.640 419.530'],
    'roll 80 mm' => ['roll', '226.77'],
]);

it('never offers or prints landscape in the document engine', function () {
    expect(DocumentRenderer::PAPERS)->not->toHaveKey('a4_landscape')
        ->and(DocumentRenderer::paper('a4_landscape'))->toBe('a4')
        ->and(PaperSize::mpdfFormat('a4', 'A4-L'))->toBe('A4')
        ->and(PaperSize::mpdfFormat('letter', 'A4-L'))->toBe('Letter')
        ->and(PaperSize::mpdfFormat(null, 'A4-L'))->toBe('A4');
});
