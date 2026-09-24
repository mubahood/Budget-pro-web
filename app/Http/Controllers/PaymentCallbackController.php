<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionInvoice;
use App\Services\Billing\SubscriptionFulfillment;
use App\Services\FlutterwaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Browser return URL after Flutterwave hosted checkout (P0-14). Flutterwave
 * redirects here with ?status=successful|cancelled|failed&tx_ref=…&transaction_id=….
 * The transaction is re-verified with the gateway before anything is activated;
 * the webhook remains the authoritative channel, this page just confirms sooner.
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request, FlutterwaveService $flutterwave, SubscriptionFulfillment $fulfillment)
    {
        $status = strtolower((string) $request->query('status', ''));
        $txRef = trim((string) $request->query('tx_ref', ''));
        $transactionId = $request->query('transaction_id');

        $invoice = $txRef !== '' ? SubscriptionInvoice::where('provider_invoice_id', $txRef)->first() : null;

        if ($invoice === null) {
            return $this->page('not_found', 'We could not find this payment. If you were charged, contact support with your transaction reference.', $txRef, 404);
        }

        if ($invoice->status === 'paid') {
            return $this->page('paid', 'Your payment is confirmed and your subscription is active.', $txRef, 200, $invoice);
        }

        if ($status === 'cancelled') {
            return $this->page('cancelled', 'The payment was cancelled. No charge was made — you can try again from the app.', $txRef, 200, $invoice);
        }

        if ($status === 'successful' && $transactionId) {
            $verification = $flutterwave->verifyTransaction($transactionId);
            $data = $verification['data'] ?? [];
            if ($verification['success']
                && ($data['tx_ref'] ?? null) === $invoice->provider_invoice_id
                && $flutterwave->transactionSatisfies($data, (float) $invoice->amount, $invoice->currency)) {
                $fulfillment->fulfill($invoice, $data);

                return $this->page('paid', 'Your payment is confirmed and your subscription is active.', $txRef, 200, $invoice->fresh());
            }

            Log::warning('Payment callback could not verify transaction', ['tx_ref' => $txRef, 'transaction_id' => $transactionId]);

            return $this->page('pending', 'We received your payment but could not confirm it yet. It will be activated automatically once the gateway confirms it.', $txRef, 200, $invoice);
        }

        return $this->page('failed', 'The payment did not go through. You have not been charged — please try again.', $txRef, 200, $invoice);
    }

    private function page(string $outcome, string $message, string $txRef, int $httpStatus, ?SubscriptionInvoice $invoice = null)
    {
        return response()->view('payment.callback', [
            'outcome' => $outcome,
            'message' => $message,
            'txRef' => $txRef,
            'invoice' => $invoice,
            'appLink' => config('saas.mobile_deep_link'),
            'dashboardUrl' => url('/'),
        ], $httpStatus);
    }
}
