<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting;

use App\Domain\Service\PdfRendererInterface;
use Dompdf\Dompdf;
use Dompdf\Options;

class DompdfRenderer implements PdfRendererInterface
{
    public function render(string $html): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4');
        $dompdf->render();

        return $dompdf->output();
    }
}
