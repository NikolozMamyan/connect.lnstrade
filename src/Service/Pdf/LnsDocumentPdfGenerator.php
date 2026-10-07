<?php

namespace App\Service\Pdf;

use App\Entity\LnsDocument;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Dompdf\FrameDecorator\Block;
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
        $pageTypes = [];
        $contentPages = [];
        $tocEntries = [];
        $dompdf->setCallbacks([
            [
                'event' => 'begin_frame',
                'f' => static function (Frame $frame, Canvas $canvas) use (&$pageTypes, &$contentPages, &$tocEntries): void {
                    $node = $frame->get_node();
                    if (!$node instanceof \DOMElement) {
                        return;
                    }

                    $pageNumber = $canvas->get_page_number();
                    if ($node->hasAttribute('data-pdf-page')) {
                        $pageTypes[$pageNumber] = $node->getAttribute('data-pdf-page');
                        if ($node->hasAttribute('data-content-index')) {
                            $contentPages[(int) $node->getAttribute('data-content-index')] ??= $pageNumber;
                        }
                    }

                    if ($node->getAttribute('class') === 'content' && $frame instanceof Block && !$frame->is_split) {
                        $availableHeight = $frame->get_containing_block('h');
                        $offset = max(0, ($availableHeight - $frame->get_margin_height()) / 2);
                        $frame->move(0, $offset);
                    }

                    if ($node->hasAttribute('data-toc-index')) {
                        $tocEntries[(int) $node->getAttribute('data-toc-index')] = [
                            'page' => $pageNumber,
                            'box' => $frame->get_border_box(),
                        ];
                    }
                },
            ],
            [
                'event' => 'end_document',
                'f' => static function (int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics) use (&$pageTypes, &$contentPages, &$tocEntries): void {
                    $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
                    $color = [118 / 255, 122 / 255, 130 / 255];
                    if (in_array($pageTypes[$pageNumber] ?? '', ['toc', 'content'], true)) {
                        $label = sprintf('Page %d / %d', $pageNumber, $pageCount);
                        $x = $canvas->get_width() - 20 * 72 / 25.4 - $fontMetrics->getTextWidth($label, $font, 6);
                        $canvas->text($x, $canvas->get_height() - 9 * 72 / 25.4, $label, $font, 6, $color);
                    }

                    foreach ($tocEntries as $index => $entry) {
                        if ($entry['page'] !== $pageNumber) {
                            continue;
                        }

                        [$x, $y, $width] = $entry['box'];
                        $label = (string) $contentPages[$index];
                        $x += $width - $fontMetrics->getTextWidth($label, $font, 6.75);
                        $canvas->text($x, $y, $label, $font, 6.75, $color);
                    }
                },
            ],
        ]);
        $html = $this->twig->render('lns_document/pdf.html.twig', [
            'document' => $document,
        ]);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
