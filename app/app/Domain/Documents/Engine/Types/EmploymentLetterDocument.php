<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine\Types;

/** Employment certificate (service letter) without salary figures. */
final class EmploymentLetterDocument extends SalaryCertificateDocument
{
    public function key(): string
    {
        return 'employment_letter';
    }

    public function label(): string
    {
        return 'Employment certificate';
    }

    public function series(): string
    {
        return 'CERT';
    }
}
