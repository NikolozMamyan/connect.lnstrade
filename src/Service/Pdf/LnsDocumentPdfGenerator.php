<?php

namespace App\Service\Pdf;

use App\Entity\LnsDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final class LnsDocumentPdfGenerator
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function generate(LnsDocument $document): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $html = $this->twig->render('lns_document/pdf.html.twig', [
            'document' => $document,
        ]);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
