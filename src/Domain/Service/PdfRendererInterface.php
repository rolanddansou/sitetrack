<?php

declare(strict_types=1);

namespace App\Domain\Service;

interface PdfRendererInterface
{
    public function render(string $html): string;
}
