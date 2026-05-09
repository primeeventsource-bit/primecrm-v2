/**
 * k6 — pacing-tick simulator.
 *
 * Drives the dialer's compliance guardrail at high concurrency to verify
 * tail latency under load. Each VU repeatedly calls
 *   GET /api/compliance/guardrail/check/{leadId}
 * which exercises the same code path as DialLeadJob's pre-flight (DNC →
 * consent → frequency → calling-window).
 *
 * Targets:
 *   - p99 < 50ms across the guardrail check (the spec's design budget)
 *   - 0% error rate
 *   - Throughput ≥ 5,000 RPS sustained
 *
 * The point isn't to test predictive pacing math directly — that's CPU-
 * bound in PHP and runs offline. The point is to prove the *gate* itself
 * scales to 50k pacing ticks/min × N agents per tick before becoming a
 * bottleneck.
 *
 * Run:
 *   K6_BASE=https://stg.example.com K6_TOKEN=<sanctum_token> \
 *     K6_LEAD_IDS=$(cat lead_ids.txt | head -1000 | paste -sd,) \
 *     k6 run tests/load/k6/pacing-tick.js
 */
import http from 'k6/http';
import { check } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const baseUrl = __ENV.K6_BASE || 'http://localhost:8000';
const token = __ENV.K6_TOKEN;
const leadIds = (__ENV.K6_LEAD_IDS || '').split(',').filter((id) => id);

if (!token) {
    throw new Error('K6_TOKEN required (Sanctum bearer for an authorized user).');
}
if (leadIds.length === 0) {
    throw new Error('K6_LEAD_IDS required (comma-separated list of lead UUIDs).');
}

const guardrailLatency = new Trend('guardrail_check_ms');
const errorRate = new Rate('errors');

export const options = {
    scenarios: {
        sustained_load: {
            executor: 'constant-arrival-rate',
            rate: 5000,           // 5k requests/second target
            timeUnit: '1s',
            duration: '2m',
            preAllocatedVUs: 200,
            maxVUs: 500,
        },
    },
    thresholds: {
        guardrail_check_ms: ['p(95)<35', 'p(99)<50'],
        errors: ['rate<0.001'],
        http_req_duration: ['p(99)<80'], // including network
    },
};

export default function () {
    const leadId = leadIds[Math.floor(Math.random() * leadIds.length)];

    const res = http.get(`${baseUrl}/api/compliance/guardrail/check/${leadId}`, {
        headers: { Authorization: `Bearer ${token}` },
    });

    guardrailLatency.add(res.timings.duration);
    const ok = check(res, {
        'guardrail 200': (r) => r.status === 200,
        'has decision': (r) => r.json('decision.allowed') !== undefined,
    });
    if (!ok) errorRate.add(1);
}
