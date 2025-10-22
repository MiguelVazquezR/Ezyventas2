<?php

namespace App\Console\Commands;

use App\Models\ServiceOrder;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixServiceOrderFolios extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fix-service-order-folios';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finds and corrects transaction folios that do not match their related service order folio.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting the process to fix service order transaction folios...');

        // Find all transactions linked to a service order
        $transactions = Transaction::where('transactionable_type', ServiceOrder::class)
            ->with('transactionable') // Eager load the related service order
            ->get();

        $progressBar = $this->output->createProgressBar(count($transactions));
        $progressBar->start();

        $correctedCount = 0;

        foreach ($transactions as $transaction) {
            // The related service order is already loaded
            $serviceOrder = $transaction->transactionable;

            if (!$serviceOrder || !$serviceOrder->folio) {
                $this->warn("\nSkipping Transaction ID: {$transaction->id}. No related service order or service order folio found.");
                $progressBar->advance();
                continue;
            }

            // Extract the numeric part from the service order folio (e.g., "OS-001" -> "001")
            $orderFolioNumber = preg_replace('/[^0-9]/', '', $serviceOrder->folio);

            // Construct the expected transaction folio (e.g., "OS-V-001")
            $expectedTransactionFolio = 'OS-V-' . $orderFolioNumber;

            // If the current folio is different from the expected one, update it
            if ($transaction->folio !== $expectedTransactionFolio) {
                $this->line("\nFound mismatch for Service Order #{$serviceOrder->folio}:");
                $this->warn(" -> Incorrect Transaction Folio: {$transaction->folio}");
                $this->info(" -> Correct Transaction Folio:   {$expectedTransactionFolio}");

                // Update the transaction
                $transaction->folio = $expectedTransactionFolio;
                $transaction->save();

                $correctedCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->info("\n\nProcess finished.");
        $this->info("Checked " . count($transactions) . " transactions.");

        if ($correctedCount > 0) {
            $this->info("Successfully corrected {$correctedCount} transaction folios.");
        } else {
            $this->info("No incorrect folios were found.");
        }

        return 0;
    }
}