<?php

namespace App\Jobs;

use App\Models\BudgetItem;
use App\Models\BudgetProgram;
use App\Models\User;
use App\Models\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBudgetItemNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $budgetItemId;
    protected $companyId;
    protected $budgetProgramId;

    /**
     * Create a new job instance.
     */
    public function __construct($budgetItemId, $companyId, $budgetProgramId)
    {
        $this->budgetItemId = $budgetItemId;
        $this->companyId = $companyId;
        $this->budgetProgramId = $budgetProgramId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $budgetItem = BudgetItem::find($this->budgetItemId);
            
            if (!$budgetItem) {
                Log::warning("BudgetItem #{$this->budgetItemId} not found for notification");
                return;
            }

            $program = BudgetProgram::find($this->budgetProgramId);
            if (!$program) {
                Log::warning("BudgetProgram #{$this->budgetProgramId} not found");
                return;
            }

            // Get company users' emails
            $users = User::where('company_id', $this->companyId)->get();
            $emails = $this->collectEmails($users);

            // Build email content
            $budgetDownloadLink = url('budget-program-print?id=' . $this->budgetProgramId);
            $mailBody = $this->buildEmailBody($budgetItem, $budgetDownloadLink);

            // Prepare mail data
            $mailData = [
                'email' => $emails,
                'subject' => $program->name . " - Budget Updates",
                'body' => $mailBody,
                'data' => $mailBody,
                'name' => 'Admin',
            ];

            // Send email
            Utils::mail_sender($mailData);
            
            Log::info("Budget item notification sent for item #{$this->budgetItemId}");
            
        } catch (\Throwable $e) {
            Log::error("Failed to send budget item notification: " . $e->getMessage());
            // Don't throw - we don't want to retry email notifications indefinitely
        }
    }

    /**
     * Collect valid emails from users
     */
    private function collectEmails($users)
    {
        $emails = [];
        
        foreach ($users as $user) {
            if (filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $user->email;
            }
            
            if (filter_var($user->username, FILTER_VALIDATE_EMAIL)) {
                if (!in_array($user->username, $emails)) {
                    $emails[] = $user->username;
                }
            }
        }

        // Add default notification email if not present
        if (!in_array('mubahood360@gmail.com', $emails)) {
            $emails[] = 'mubahood360@gmail.com';
        }

        return $emails;
    }

    /**
     * Build email body HTML
     */
    private function buildEmailBody($budgetItem, $downloadLink)
    {
        $unitPrice = number_format($budgetItem->unit_price);
        $quantity = number_format($budgetItem->quantity);
        $investedAmount = number_format($budgetItem->invested_amount);
        $balance = number_format($budgetItem->balance);
        $percentageDone = $budgetItem->percentage_done;
        $categoryName = $budgetItem->category ? $budgetItem->category->name : 'N/A';
        $categoryDetails = $budgetItem->category ? $budgetItem->category->details : '';

        return <<<EOD
            <p>Dear Admin,</p><br>
            <p>Budget item <b>{$budgetItem->name} - {$categoryName}</b> has been updated.</p>
            <p><b>Unit price:</b> {$unitPrice}</p>
            <p><b>Quantity:</b> {$quantity}</p>
            <p><b>Invested Amount:</b> {$investedAmount}</p>
            <p><b>Percentage Done:</b> {$percentageDone}%</p>
            <p><b>Balance:</b> {$balance}</p>
            <p><b>Details:</b> {$categoryDetails}</p>
            <p>Click <a href="{$downloadLink}">here to DOWNLOAD UPDATED Budget</a> pdf.</p>
            <br><p>Thank you.</p>
        EOD;
    }

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = 60;
}
