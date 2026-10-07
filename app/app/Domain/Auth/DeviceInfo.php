<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final readonly class DeviceInfo
{
    public function __construct(
        public string $uid,
        public string $platform,
        public ?string $name = null,
    ) {}
}
