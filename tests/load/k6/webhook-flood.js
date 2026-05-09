/**
 * k6 — Twilio webhook-flood test.
 *
 * Verifies the (provider, external_id) idempotency guarantee under high
 * concurrency. Generates a small fixed pool of CallSids and randomly
 * fires one of {ringing, in-progress, completed} for each, replaying
 * each (CallSid, status) pair multiple times.
 *
 * Targets:
 *   - 200 OK on every request (Twilio retries non-2xx)
 *   - Eventually-consistent: each (CallSid, status) row in webhook_events
 *     has count=1 after the test, regardless of how many times it was sent.
 *     (Verified by a SQL query in the README runbook, not in-test.)
 *
 * The webhook controller's only signature check is via the configured
 * gateway, which in test mode (TWILIO_VERIFY_SIGNATURE=false) accepts all.
 * Don't run this against production.
 *
 * Run:
 *   K6_BASE=https://stg.example.com K6_CALL_IDS=$(…uuids…) \
 *     k6 run tests/load/k6/webhook-flood.js
 */
import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const baseUrl = __ENV.K6_BASE || 'http://localhost:8000';
const callIds = (__ENV.K6_CALL_IDS || '').split(',').filter((id) => id);
const sids = Array.from({ length: 50 }, (_, i) => `CA${'0'.repeat(30)}${String(i).padStart(2, '0')}`);
const statuses = ['ringing', 'in-progress', 'completed', 'no-answer'];

if (callIds.length === 0) {
    throw new Error('K6_CALL_IDS required.');
}

const webhookLatency = new Trend('webhook_post_ms');
const errorRate = new Rate('errors');

export const options = {
    scenarios: {
        flood: {
            executor: 'ramping-arrival-rate',
            startRate: 100,
            timeUnit: '1s',
            preAllocatedVUs: 100,
            maxVUs: 500,
            stages: [
                { duration: '30s', target: 500 },
                { duration: '1m', target: 2000 },
                { duration: '30s', target: 0 },
            ],
        },
    },
    thresholds: {
        webhook_post_ms: ['p(95)<150', 'p(99)<300'],
        http_req_duration: ['p(95)<200'],
        errors: ['rate<0.001'],
    },
};

export default function () {
    const sid = sids[Math.floor(Math.random() * sids.length)];
    const status = statuses[Math.floor(Math.random() * statuses.length)];
    const callId = callIds[Math.floor(Math.random() * callIds.length)];

    const body = `CallSid=${sid}&CallStatus=${status}&From=%2B15555550000&To=%2B14155551234`;

    const res = http.post(`${baseUrl}/webhooks/twilio/status/${callId}`, body, {
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Twilio-Signature': 'k6-load-test',
        },
    });

    webhookLatency.add(res.timings.duration);
    const ok = check(res, { 'webhook 200': (r) => r.status === 200 });
    if (!ok) errorRate.add(1);
}
