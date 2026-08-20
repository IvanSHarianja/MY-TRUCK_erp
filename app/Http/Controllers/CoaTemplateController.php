<?php

namespace App\Http\Controllers;

use App\Support\CoaTemplateExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoint untuk download template Excel COA.
 *
 * Public (tanpa auth) — file ini adalah template kosong tanpa data sensitif,
 * dan diperlukan sebelum user login pertama kali (saat first-time register PT).
 */
class CoaTemplateController extends Controller
{
    public function __invoke(CoaTemplateExporter $exporter): StreamedResponse
    {
        return $exporter->stream('template-coa-my-truck.xlsx');
    }
}
