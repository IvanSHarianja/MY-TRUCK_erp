<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashFlowService;
use App\Services\Accounting\EquityStatementService;
use App\Services\Accounting\IncomeStatementByAssetService;
use App\Services\Accounting\IncomeStatementMatrixService;
use App\Services\Accounting\IncomeStatementService;
use App\Services\Accounting\TrialBalanceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PdfController extends Controller
{
    /**
     * Cetak PDF Invoice.
     */
    public function invoice(Invoice $invoice)
    {
        $invoice->load(['client', 'businessUnit', 'payments.cashAccount']);
        $company = Company::findOrFail($invoice->company_id);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => $company,
        ])->setPaper('a4');

        $filename = "Invoice-{$invoice->invoice_number}.pdf";

        return $pdf->stream($filename);
    }

    /**
     * Trial Balance PDF.
     */
    public function trialBalance(Request $request, Company $tenant)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = $request->query('month') ? (int) $request->query('month') : null;

        $service = app(TrialBalanceService::class);
        $balances = $service->getBalancesByCategory($tenant->id, $year, $month);
        $totals = $service->getGrandTotal($tenant->id, $year, $month);

        $pdf = Pdf::loadView('pdf.reports.trial-balance', [
            'company'    => $tenant,
            'year'       => $year,
            'month'      => $month,
            'balances'   => $balances,
            'totals'     => $totals,
            'periodLabel' => $this->periodLabel($year, $month),
        ])->setPaper('a4');

        return $pdf->stream("Neraca-Saldo-{$tenant->slug}-{$year}-" . ($month ?? 'YTD') . ".pdf");
    }

    /**
     * Income Statement PDF.
     */
    public function incomeStatement(Request $request, Company $tenant)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = $request->query('month') ? (int) $request->query('month') : null;
        $businessUnitId = $request->query('business_unit_id') ? (int) $request->query('business_unit_id') : null;

        $report = app(IncomeStatementService::class)->getReport($tenant->id, $year, $month, $businessUnitId);

        $pdf = Pdf::loadView('pdf.reports.income-statement', array_merge($report, [
            'company'     => $tenant,
            'year'        => $year,
            'month'       => $month,
            'periodLabel' => $this->periodLabel($year, $month),
        ]))->setPaper('a4');

        return $pdf->stream("Laba-Rugi-{$tenant->slug}-{$year}-" . ($month ?? 'YTD') . ".pdf");
    }

    /**
     * Income Statement Matrix per Lini PDF.
     */
    public function incomeStatementMatrix(Request $request, Company $tenant)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = $request->query('month') ? (int) $request->query('month') : null;

        $report = app(IncomeStatementMatrixService::class)->getReport($tenant->id, $year, $month);

        $pdf = Pdf::loadView('pdf.reports.income-statement-matrix', array_merge($report, [
            'company'     => $tenant,
            'year'        => $year,
            'month'       => $month,
            'periodLabel' => $this->periodLabel($year, $month),
        ]))->setPaper('a4', 'landscape');  // landscape karena matrix lebar

        return $pdf->stream("Laba-Rugi-per-Lini-{$tenant->slug}-{$year}-" . ($month ?? 'YTD') . ".pdf");
    }

    /**
     * Income Statement per Aset PDF — cost tracking per unit.
     */
    public function incomeStatementByAsset(Request $request, Company $tenant)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = $request->query('month') ? (int) $request->query('month') : null;
        $type  = $request->query('type');

        $report = app(IncomeStatementByAssetService::class)->getReport($tenant->id, $year, $month);

        // Filter type kalau ada
        if ($type) {
            $report['assets'] = array_values(array_filter(
                $report['assets'],
                fn ($row) => $row['type'] === $type,
            ));
        }

        $pdf = Pdf::loadView('pdf.reports.income-statement-by-asset', array_merge($report, [
            'company'     => $tenant,
            'year'        => $year,
            'month'       => $month,
            'typeFilter'  => $type,
            'periodLabel' => $this->periodLabel($year, $month),
        ]))->setPaper('a4', 'landscape');  // landscape supaya 8 kolom muat

        return $pdf->stream("Laba-Rugi-per-Unit-{$tenant->slug}-{$year}-" . ($month ?? 'YTD') . ".pdf");
    }

    /**
     * Balance Sheet PDF.
     */
    public function balanceSheet(Request $request, Company $tenant)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = $request->query('month') ? (int) $request->query('month') : null;

        $report = app(BalanceSheetService::class)->getReport($tenant->id, $year, $month);

        $pdf = Pdf::loadView('pdf.reports.balance-sheet', array_merge($report, [
            'company'     => $tenant,
            'year'        => $year,
            'month'       => $month,
            'periodLabel' => $this->periodLabel($year, $month),
        ]))->setPaper('a4');

        return $pdf->stream("Neraca-{$tenant->slug}-{$year}-" . ($month ?? 'YTD') . ".pdf");
    }

    /**
     * Equity Statement PDF.
     */
    public function equityStatement(Request $request, Company $tenant)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = $request->query('month') ? (int) $request->query('month') : null;

        $report = app(EquityStatementService::class)->getReport($tenant->id, $year, $month);

        $pdf = Pdf::loadView('pdf.reports.equity-statement', array_merge($report, [
            'company'     => $tenant,
            'year'        => $year,
            'month'       => $month,
            'periodLabel' => $this->periodLabel($year, $month),
        ]))->setPaper('a4');

        return $pdf->stream("Perubahan-Ekuitas-{$tenant->slug}-{$year}-" . ($month ?? 'YTD') . ".pdf");
    }

    /**
     * Cash Flow PDF.
     */
    public function cashFlow(Request $request, Company $tenant)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = $request->query('month') ? (int) $request->query('month') : null;

        $report = app(CashFlowService::class)->getReport($tenant->id, $year, $month);

        $pdf = Pdf::loadView('pdf.reports.cash-flow', array_merge($report, [
            'company'     => $tenant,
            'year'        => $year,
            'month'       => $month,
            'periodLabel' => $this->periodLabel($year, $month),
        ]))->setPaper('a4');

        return $pdf->stream("Arus-Kas-{$tenant->slug}-{$year}-" . ($month ?? 'YTD') . ".pdf");
    }

    private function periodLabel(int $year, ?int $month): string
    {
        $months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        return $month
            ? $months[$month - 1] . ' ' . $year
            : "Tahun {$year} (s.d. akhir tahun)";
    }
}
