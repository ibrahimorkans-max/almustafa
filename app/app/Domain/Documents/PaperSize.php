<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * The paper every slip and report is printed on: A4, A6, Letter, or a small roll of 80 mm or 50 mm. Chosen per
 * device (the printer next to it), from the paper selector at the top of every screen; `?paper=` on a
 * print link overrides it for that one print. Without a choice, each document keeps its own size.
 */
final class PaperSize
{
    public const OPTIONS = [
        'a4' => 'A4',
        'a6' => 'A6',
        'letter' => 'Letter',
        'roll' => 'Small roll (80 mm)',
        'roll50' => 'Small roll (50 mm)',
    ];

    /** Width in mm of each roll paper. */
    public const ROLL_WIDTHS = ['roll' => 80, 'roll50' => 50];

    public const COOKIE = 'print_paper';

    /** The paper chosen for this print (null: the document's own size). */
    public static function current(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }
        $request = request();
        foreach ([$request->query('paper'), $request->cookie(self::COOKIE)] as $choice) {
            if (is_string($choice) && isset(self::OPTIONS[$choice])) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * The mPDF format for a document: the chosen paper, kept landscape for wide reports on A4 and Letter.
     * A roll is returned as null: its height is measured from the content (PdfRenderer).
     *
     * @param  string  $documentFormat  the document's own format: A4, A4-L, A5
     */
    public static function mpdfFormat(?string $paper, string $documentFormat): ?string
    {
        $landscape = str_ends_with($documentFormat, '-L');

        return match ($paper) {
            'a4' => $landscape ? 'A4-L' : 'A4',
            'letter' => $landscape ? 'Letter-L' : 'Letter',
            'a6' => 'A6',
            'roll', 'roll50' => null,
            default => $documentFormat,
        };
    }

    /** Small papers get small margins and type. */
    public static function isSmall(?string $paper, string $documentFormat): bool
    {
        return in_array($paper, ['a6', 'roll', 'roll50'], true) || ($paper === null && $documentFormat === 'A5');
    }

    public static function isRoll(?string $paper): bool
    {
        return $paper !== null && isset(self::ROLL_WIDTHS[$paper]);
    }
}
