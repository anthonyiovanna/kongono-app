<?php

declare(strict_types=1);

namespace App\Http;

use Kongono\Validation\Attributes\Max;
use Kongono\Validation\Attributes\Min;
use Kongono\Validation\Attributes\Required;

final readonly class EchoInput
{
    public function __construct(
        #[Required, Min(1), Max(500)]
        public string $message,
    ) {}
}
