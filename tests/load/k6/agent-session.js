/**
 * k6 — agent-session simulator.
 *
 * Models 1,000 concurrent agents holding a dial session for 5 minutes.
 * Each VU:
 *   1. Authenticates (Sanctum CSRF + login)
 *   2. Starts a dial session
 *   3. Heartbeats every 20s
 *   4. Loads its assigned-leads list periodically (the dialer console fetch)
 *   5. Stops the session
 *
 * Targets:
 *   - p95 endpoint latency < 200ms (dashboard goal in the spec)
 *   - 0% error rate on session lifecycle endpoints
 *   - Heartbeat success ≥ 99.9%
 *
 * Run:
 *   K6_BASE=https://stg.example.com K6_CRED='email=agent@x.com&password=…' \
 *     k6 run tests/load/k6/agent-session.js
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const baseUrl = __ENV.K6_BASE || 'http://localhost:8000';
const credBody = __ENV.K6_CRED || 'email=agent1@example.com&password=password';

const errorRate = new Rate('errors');
const sessionStartLatency = new Trend('session_start_ms');
const heartbeatLatency = new Trend('heartbeat_ms');

export const options = {
    scenarios: {
        ramp_to_thousand: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '1m', target: 200 },
                { duration: '2m', target: 1000 },
                { duration: '5m', target: 1000 },
                { duration: '1m', target: 0 },
            ],
            gracefulRampDown: '30s',
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<200', 'p(99)<400'],
        errors: ['rate<0.01'],
        session_start_ms: ['p(95)<300'],
        heartbeat_ms: ['p(95)<100'],
    },
};

function authenticate() {
    const csrf = http.get(`${baseUrl}/sanctum/csrf-cookie`);
    check(csrf, { 'csrf 200': (r) => r.status === 204 || r.status === 200 });

    const login = http.post(`${baseUrl}/api/auth/login`, credBody, {
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    });
    check(login, { 'login 200': (r) => r.status === 200 });
    return login.json('token');
}

export default function () {
    const token = authenticate();
    if (!token) {
        errorRate.add(1);
        return;
    }
    const headers = { Authorization: `Bearer ${token}` };

    const startRes = http.post(`${baseUrl}/api/dialer/sessions`, null, { headers });
    sessionStartLatency.add(startRes.timings.duration);
    const sessionStarted = check(startRes, { 'session created': (r) => r.status === 201 });
    if (!sessionStarted) {
        errorRate.add(1);
        return;
    }
    const sessionId = startRes.json('data.id');

    // 5 minutes simulated session: heartbeat + lead-list polling
    for (let i = 0; i < 15; i++) {
        const hb = http.post(`${baseUrl}/api/agent-status/heartbeat`, null, { headers });
        heartbeatLatency.add(hb.timings.duration);
        check(hb, { 'heartbeat 200': (r) => r.status === 200 });

        const list = http.get(`${baseUrl}/api/leads?per_page=10`, { headers });
        check(list, { 'leads 200': (r) => r.status === 200 });

        sleep(20);
    }

    const stopRes = http.post(`${baseUrl}/api/dialer/sessions/${sessionId}/stop`, null, { headers });
    check(stopRes, { 'session stopped': (r) => r.status === 200 });
}
