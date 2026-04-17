<?php

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class InvoicePdfGenerator
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<int, array<string, mixed>> $lines
     */
    public function generate(array $invoice, array $lines): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $html = $this->twig->render('flux/factures/pdf_document.html.twig', [
            'invoice' => $invoice,
            'lines' => $lines,
        ]);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
