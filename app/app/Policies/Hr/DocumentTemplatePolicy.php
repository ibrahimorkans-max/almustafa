<?php

declare(strict_types=1);

namespace App\Policies\Hr;

/** Document templates: managed (created, versioned, made default) only with hr.documents.template.manage. */
final class DocumentTemplatePolicy extends DocumentPolicy
{
    protected string $permission = 'hr.documents.template.manage';
}
