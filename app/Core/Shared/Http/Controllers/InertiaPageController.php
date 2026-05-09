<?php

declare(strict_types=1);

namespace App\Core\Shared\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders Inertia pages.
 *
 * The bulk of these are pure shell views — the page does its own data
 * fetching against the API. Pages that need server-side props (e.g.
 * Payment\Capture needs the Stripe publishable key, which we don't want
 * leaking into a public env injected into every page) get explicit
 * methods that pass the right initial props.
 */
final class InertiaPageController extends Controller
{
    public function login(): Response
    {
        return Inertia::render('Login');
    }

    public function dashboard(): Response
    {
        return Inertia::render('Dashboard');
    }

    public function dialerConsole(): Response
    {
        return Inertia::render('Dialer/Console');
    }

    public function pipeline(): Response
    {
        return Inertia::render('Pipeline/Index');
    }

    public function bookingSearch(): Response
    {
        return Inertia::render('Booking/Search');
    }

    public function paymentCapture(Request $request): Response
    {
        return Inertia::render('Payment/Capture', [
            'bookingId' => $request->query('booking_id'),
            'dealId' => $request->query('deal_id'),
            'amount' => (float) $request->query('amount', 0),
            'currency' => (string) $request->query('currency', 'USD'),
            'stripePublishableKey' => (string) env('STRIPE_KEY', ''),
        ]);
    }

    public function warRoom(Request $request): Response
    {
        if (! $request->user()?->role->canSupervise()) {
            abort(403);
        }

        return Inertia::render('Supervisor/WarRoom');
    }

    public function leadsIndex(): Response
    {
        return Inertia::render('Leads/Index');
    }

    public function commissionPayouts(): Response
    {
        return Inertia::render('Commission/Payouts');
    }

    public function complianceDnc(Request $request): Response
    {
        if (! $request->user()?->role->canSupervise()) {
            abort(403);
        }

        return Inertia::render('Compliance/Dnc');
    }

    public function customersIndex(): Response
    {
        return Inertia::render('Customers/Index');
    }

    public function agentsIndex(Request $request): Response
    {
        // Listing is open to everyone; the page itself gates the
        // "+ Add agent" button on canSupervise().
        return Inertia::render('Agents/Index');
    }
}
