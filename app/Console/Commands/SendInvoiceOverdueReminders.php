<?php

namespace App\Console\Commands;

use App\Mail\InvoiceOverdueReminder;
use App\Models\Invoice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

#[Signature('invoices:overdue-reminder {--days=30 : Kirim reminder untuk invoice yang umur >= N hari} {--dry : Dry-run, hanya tampilkan tanpa kirim email}')]
#[Description('Kirim email pengingat untuk invoice yang sudah jatuh tempo')]
class SendInvoiceOverdueReminders extends Command
{
    public function handle(): int
    {
        $minDays = (int) $this->option('days');
        $dry     = (bool) $this->option('dry');

        $this->info("Mencari invoice overdue dengan umur >= {$minDays} hari...");

        $invoices = Invoice::query()
            ->withoutGlobalScopes()
            ->whereIn('status', ['terbit', 'sebagian'])
            ->whereRaw('DATEDIFF(?, invoice_date) >= ?', [now()->toDateString(), $minDays])
            ->with(['client', 'company'])
            ->get();

        if ($invoices->isEmpty()) {
            $this->info('Tidak ada invoice overdue. Selesai.');
            return self::SUCCESS;
        }

        $this->info("Ditemukan {$invoices->count()} invoice overdue.");

        $sent = 0;
        $skipped = 0;

        foreach ($invoices as $invoice) {
            $email = optional($invoice->client)->email;
            $umur  = $invoice->umur_hari;

            if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warn("  ⊘ {$invoice->invoice_number} — client {$invoice->client->name} tidak punya email valid");
                $skipped++;
                continue;
            }

            if ($dry) {
                $this->line("  [DRY] {$invoice->invoice_number} → {$email} (umur {$umur} hari)");
                continue;
            }

            try {
                Mail::to($email)->send(new InvoiceOverdueReminder($invoice));
                $this->info("  ✓ {$invoice->invoice_number} → {$email} (umur {$umur} hari)");
                $sent++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$invoice->invoice_number}: " . $e->getMessage());
                Log::error("Gagal kirim overdue reminder {$invoice->invoice_number}: " . $e->getMessage());
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Selesai. Terkirim: {$sent}, Skip: {$skipped}");

        return self::SUCCESS;
    }
}
