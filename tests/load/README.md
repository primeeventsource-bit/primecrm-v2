# Load tests

k6 scenarios that exercise the platform's load-bearing surfaces.
Each script targets a specific structural property the spec called out.

| Script | Asserts |
|---|---|
| `agent-session.js` | 1,000 concurrent agent sessions can sustain 5 min of heartbeats + lead-list polling at p95 < 200ms |
| `pacing-tick.js` | Compliance guardrail returns at p99 < 50ms under 5,000 RPS — proves the gate isn't the dialer's bottleneck |
| `webhook-flood.js` | Twilio webhooks accepted at 2,000 RPS with 0% non-2xx; idempotency holds (verified post-run via SQL) |

## Setup

Install [k6](https://k6.io/docs/get-started/installation/):

```bash
# macOS
brew install k6

# Linux
sudo gpg -k && sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update && sudo apt-get install k6
```

## Runbook

Always run against **staging**, never production. The webhook flood
generates synthetic Twilio events that, in production mode, would
trigger real downstream effects (commission events, payouts).

### 1. Bootstrap test data

```bash
# On the staging host
docker compose exec app php artisan db:seed --class=DemoSeeder
docker compose exec app php artisan tinker --execute='
  echo \App\Modules\Lead\Domain\Models\Lead::query()->limit(1000)->pluck("id")->implode(",");
' > /tmp/lead_ids.txt

docker compose exec app php artisan tinker --execute='
  echo \App\Modules\CallCenter\Domain\Models\Call::factory()->count(50)->create()->pluck("id")->implode(",");
' > /tmp/call_ids.txt
```

### 2. Capture a Sanctum token

```bash
TOKEN=$(curl -s -X POST https://stg.example.com/api/auth/login \
  -d 'email=loadtest@example.com&password=…' | jq -r .token)
```

### 3. Run

```bash
K6_BASE=https://stg.example.com \
  K6_CRED='email=loadtest@example.com&password=…' \
  k6 run tests/load/k6/agent-session.js

K6_BASE=https://stg.example.com K6_TOKEN=$TOKEN \
  K6_LEAD_IDS=$(cat /tmp/lead_ids.txt) \
  k6 run tests/load/k6/pacing-tick.js

K6_BASE=https://stg.example.com \
  K6_CALL_IDS=$(cat /tmp/call_ids.txt) \
  k6 run tests/load/k6/webhook-flood.js
```

### 4. Verify webhook idempotency post-run

```sql
-- Should return 0 rows. Each (provider, external_id) is unique by DB constraint;
-- a count > 1 would mean we somehow bypassed it.
SELECT provider, external_id, COUNT(*) AS c
FROM webhook_events
GROUP BY provider, external_id
HAVING COUNT(*) > 1;
```

## Interpreting failures

- **`http_req_duration p(95) > 200ms`** during agent-session: the API is
  the bottleneck. Check Horizon supervisor backlog (`/horizon`); usually
  `supervisor-default` is undersized for the heartbeat volume.

- **`guardrail_check_ms p(99) > 50ms`**: the compliance gate has a slow
  path. The first place to look is calling_windows cache (TTL 5min in
  CallingWindowService); if that's cold, the per-state DB lookup
  serializes. Either pre-warm or extend the TTL.

- **`errors > 0` on webhook-flood**: the controller is rejecting requests.
  `403` likely means `TWILIO_VERIFY_SIGNATURE=true` on staging; flip it
  off for the test run via `TWILIO_VERIFY_SIGNATURE=false` env override.
