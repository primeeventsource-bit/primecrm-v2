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

    public function leadShow(string $id): Response
    {
        // Page itself fetches /api/leads/{id} — passing the id as a prop
        // avoids a route-name lookup on the client and keeps Show.vue
        // independent of the URL shape.
        return Inertia::render('Leads/Show', ['leadId' => $id]);
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

    public function customerShow(string $id): Response
    {
        return Inertia::render('Customers/Show', ['customerId' => $id]);
    }

    public function agentsIndex(Request $request): Response
    {
        // Listing is open to everyone; the page itself gates the
        // "+ Add agent" button on canSupervise().
        return Inertia::render('Agents/Index');
    }

    public function ownerShow(string $id): Response
    {
        // Owner profile (timeshare-listing customer-service screen).
        // The owner is a Lead row in the database; this view is the
        // post-sale fulfillment-and-relationship surface, distinct
        // from /leads/{id} which is the pre-sale conversion view.
        return Inertia::render('Owners/Show', ['ownerId' => $id]);
    }

    public function listingsIndex(): Response
    {
        // Post-sale operational view: pending distribution / live /
        // with inquiries / booked / expired_unrented.
        return Inertia::render('Listings/Index');
    }

    public function listingShow(string $id): Response
    {
        return Inertia::render('Listings/Show', ['listingId' => $id]);
    }

    public function partnerSites(Request $request): Response
    {
        // Config + performance metrics for each partner site we push to.
        // Supervisor-only because credentials live here.
        if (! $request->user()?->role->canSupervise()) {
            abort(403);
        }

        return Inertia::render('PartnerSites/Index');
    }

    public function bookingsLedger(): Response
    {
        // Renter-side bookings ledger — confirmed rentals across all
        // listings. The success-metric view (D5).
        return Inertia::render('Bookings/Index');
    }

    public function complianceHub(Request $request): Response
    {
        // Compliance command center — disclosure review + refund cases +
        // chargebacks + DNC, supervisor-gated (D6).
        if (! $request->user()?->role->canSupervise()) {
            abort(403);
        }

        return Inertia::render('Compliance/Hub');
    }
}
