<?php

namespace App\Modules\Administration;

use Dompdf\Dompdf;
use Dompdf\Options;

class LetterPdf
{
    public function render(array $snapshot): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(resource_path('views/pdf'));
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.letter', ['document' => $snapshot])->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->getCanvas()->page_text(470, 805, '{PAGE_NUM} / {PAGE_COUNT}', null, 9, [0.4, 0.4, 0.4]);

        return $pdf->output();
    }
}
