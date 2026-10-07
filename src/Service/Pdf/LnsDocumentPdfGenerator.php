<?php

namespace App\Service\Pdf;

use App\Entity\LnsDocument;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final class LnsDocumentPdfGenerator
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $cacheDir,
    ) {
    }

    public function generate(LnsDocument $document): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'IBM Plex Sans');
        $options->set('fontDir', $this->cacheDir);
        $options->set('fontCache', $this->cacheDir);
        $options->set('fontHeightRatio', 1 / 1.3);

        $dompdf = new Dompdf($options);
        $pageTypes = [];
        $contentPages = [];
        $tocEntries = [];
        $dompdf->setCallbacks([
            [
                'event' => 'begin_page_render',
                'f' => static function (Frame $frame): void {
                    $type = '';
                    foreach ($frame->get_children() as $child) {
                        $node = $child->get_node();
                        if ($node instanceof \DOMElement && $node->hasAttribute('data-pdf-page')) {
                            $type = $node->getAttribute('data-pdf-page');
                            break;
                        }
                    }
                    foreach ($frame->get_children() as $child) {
                        $node = $child->get_node();
                        if ($node instanceof \DOMElement
                            && (($node->getAttribute('class') === 'side-tab navy' && $type !== 'content')
                                || (in_array($node->getAttribute('class'), ['band', 'footer'], true) && in_array($type, ['cover', 'closing'], true)))
                        ) {
                            $child->get_style()->visibility = 'hidden';
                        }
                    }
                },
            ],
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

                    if ($node->hasAttribute('data-toc-index')) {
                        $index = (int) $node->getAttribute('data-toc-index');
                        $tocEntries[$index]['page'] = $pageNumber;
                        $tocEntries[$index]['box'] = $frame->get_border_box();
                    }
                    if ($node->hasAttribute('data-toc-title-index')) {
                        $tocEntries[(int) $node->getAttribute('data-toc-title-index')]['titleBox'] = $frame->get_border_box();
                    }
                },
            ],
            [
                'event' => 'end_document',
                'f' => static function (int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics) use (&$pageTypes, &$contentPages, &$tocEntries): void {
                    $font = $fontMetrics->getFont('IBM Plex Mono', 'normal');
                    $color = [118 / 255, 122 / 255, 130 / 255];
                    if (in_array($pageTypes[$pageNumber] ?? '', ['toc', 'content'], true)) {
                        $label = sprintf('Page %d / %d', $pageNumber, $pageCount);
                        $x = $canvas->get_width() - 30 * 72 / 25.4 - $fontMetrics->getTextWidth($label, $font, 7.125);
                        $canvas->text($x, $canvas->get_height() - 8.5 * 72 / 25.4, $label, $font, 7.125, $color);
                    }

                    foreach ($tocEntries as $index => $entry) {
                        if ($entry['page'] !== $pageNumber) {
                            continue;
                        }

                        [$x, , $width] = $entry['box'];
                        [$titleX, $titleY, $titleWidth] = $entry['titleBox'];
                        $leaderStart = $titleX + $titleWidth + 7.5;
                        $leaderEnd = $x - 7.5;
                        if ($leaderStart < $leaderEnd) {
                            $canvas->line($leaderStart, $titleY + 11.25, $leaderEnd, $titleY + 11.25, [222 / 255, 218 / 255, 208 / 255], .75, [.75, 1.5]);
                        }
                        $label = (string) $contentPages[$index];
                        $x += $width - $fontMetrics->getTextWidth($label, $font, 8.25);
                        $canvas->text($x, $titleY + 3, $label, $font, 8.25, $color);
                    }
                },
            ],
        ]);
        $backgrounds = [];
        foreach (['cover', 'header', 'closing'] as $name) {
            $backgrounds[$name] = 'data:image/png;base64,'.base64_encode(file_get_contents(
                $this->projectDir.'/assets/images/lns-document-'.$name.'.png'
            ));
        }
        $html = $this->twig->render('lns_document/pdf.html.twig', [
            'document' => $document,
            'backgrounds' => $backgrounds,
        ]);

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
