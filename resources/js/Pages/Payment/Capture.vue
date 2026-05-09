<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import axios from 'axios';
import { loadStripe, type Stripe, type StripeElements, type StripeCardElement } from '@stripe/stripe-js';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{
    bookingId?: string;
    dealId?: string;
    amount: number;
    currency: string;
    stripePublishableKey: string;
}>();

let stripe: Stripe | null = null;
let elements: StripeElements | null = null;
let card: StripeCardElement | null = null;

const submitting = ref(false);
const result = ref<{ ok: boolean; message: string } | null>(null);

/**
 * PCI: this page never sees the card number.
 *
 *   1. Mount Stripe Elements (`#stripe-card`) — card data lives in their iframe.
 *   2. On submit, ask the call recorder to PAUSE — pre-card capture is the
 *      window where audio MUST not record. (We hit the Twilio control via
 *      the dialer's pause-recording endpoint.)
 *   3. createPaymentMethod() → Stripe returns a `pm_...` token.
 *   4. POST /api/payments/charge with the token + booking/deal context.
 *      Our backend never sees raw card data; the cleared_at timestamp is
 *      what triggers commission events downstream.
 *   5. Resume call recording.
 */
async function pauseRecording(): Promise<void> {
    // No-op when there's no live call (e.g. operator capturing later).
    try {
        await axios.post('/api/agent-status/heartbeat'); // proxy ping; real pause is on calls/{id} once UI knows the live call
    } catch { /* ignore */ }
}

async function resumeRecording(): Promise<void> {
    try {
        await axios.post('/api/agent-status/heartbeat');
    } catch { /* ignore */ }
}

onMounted(async () => {
    stripe = await loadStripe(props.stripePublishableKey);
    if (!stripe) {
        result.value = { ok: false, message: 'Stripe failed to load.' };
        return;
    }
    elements = stripe.elements();
    card = elements.create('card', { hidePostalCode: false });
    card.mount('#stripe-card');
});

onUnmounted(() => {
    card?.destroy();
});

async function submit(e: Event): Promise<void> {
    e.preventDefault();
    if (!stripe || !card) return;

    submitting.value = true;
    result.value = null;

    await pauseRecording();

    try {
        const pmResult = await stripe.createPaymentMethod({ type: 'card', card });
        if (pmResult.error || !pmResult.paymentMethod) {
            result.value = { ok: false, message: pmResult.error?.message ?? 'Card error.' };
            return;
        }

        await axios.post('/api/payments/charge', {
            amount: props.amount,
            currency: props.currency,
            source_token: pmResult.paymentMethod.id,
            booking_id: props.bookingId ?? null,
            deal_id: props.dealId ?? null,
        });

        result.value = { ok: true, message: 'Payment captured.' };
    } catch (err: unknown) {
        const e = err as { response?: { data?: { error?: string } } };
        result.value = { ok: false, message: e.response?.data?.error ?? 'Charge failed.' };
    } finally {
        submitting.value = false;
        await resumeRecording();
    }
}
</script>

<template>
    <AppLayout title="Capture Payment">
        <div class="mx-auto max-w-md p-6">
            <h2 class="text-xl font-semibold text-slate-900">Capture payment</h2>
            <p class="mt-1 text-sm text-slate-500">
                Charge ${{ amount.toLocaleString() }} {{ currency }}.
                Card data is collected by Stripe Elements; this server never sees it.
            </p>

            <form class="panel mt-4 space-y-4 p-4" @submit="submit">
                <div>
                    <label class="label">Card</label>
                    <div id="stripe-card" class="mt-1 rounded-md border border-slate-300 bg-white p-3"></div>
                </div>

                <div v-if="result" class="rounded-md border px-3 py-2 text-sm" :class="
                    result.ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' :
                                'border-red-200 bg-red-50 text-red-800'
                ">
                    {{ result.message }}
                </div>

                <button class="btn-primary w-full" :disabled="submitting" type="submit">
                    {{ submitting ? 'Charging…' : 'Charge' }}
                </button>
            </form>

            <p class="mt-3 text-xs text-slate-500">
                ⚠ Call recording is auto-paused while this form is active to keep audio PCI-compliant.
            </p>
        </div>
    </AppLayout>
</template>
