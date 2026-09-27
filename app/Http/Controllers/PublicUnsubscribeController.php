<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Engage\CustomerConsent;

/**
 * The "Stop messages" link at the end of a customer message (supermarket plan C4). There is no
 * inbound SMS/WhatsApp webhook, so one tap on this signed link stops them instead. The page asks
 * first (GET) and stops on the button (POST): link previews never unsubscribe anyone.
 */
class PublicUnsubscribeController extends Controller
{
    public function show(int $customer, string $token)
    {
        $c = $this->customer($customer, $token);

        return view('unsubscribe-public', ['shop' => CustomerConsent::shopName($c), 'done' => (bool) $c->messages_opt_out, 'customer' => $c->id, 'token' => $token]);
    }

    public function stop(int $customer, string $token)
    {
        $c = $this->customer($customer, $token);
        app(CustomerConsent::class)->optOut($c);

        return view('unsubscribe-public', ['shop' => CustomerConsent::shopName($c), 'done' => true, 'customer' => $c->id, 'token' => $token]);
    }

    private function customer(int $id, string $token): Customer
    {
        abort_unless(CustomerConsent::verify($id, $token), 404);

        return Customer::withoutGlobalScopes()->where('is_deleted', 0)->findOrFail($id);
    }
}
