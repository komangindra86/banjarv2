<?php

namespace App\Modules\Finance;

use Dompdf\Dompdf;
use Dompdf\Options;

class FinanceReportPdf
{
    // F4/folio 210 x 330 mm (Master Prompt §26.5), dinyatakan dalam points (1mm = 2.83464567pt).
    private const WIDTH_PT = 210 * 2.83464567;

    private const HEIGHT_PT = 330 * 2.83464567;

    public function render(object $report, array $data): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(resource_path('views/pdf'));
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.finance-report', ['report' => $report, 'data' => $data])->render(), 'UTF-8');
        $pdf->setPaper([0, 0, self::WIDTH_PT, self::HEIGHT_PT]);
        $pdf->render();
        $pdf->getCanvas()->page_text(self::WIDTH_PT - 120, self::HEIGHT_PT - 30, '{PAGE_NUM} / {PAGE_COUNT}', null, 9, [0.4, 0.4, 0.4]);

        return $pdf->output();
    }
}
