<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { PageProps } from '@/types/api';

/**
 * Partner-sites configuration + performance metrics.
 *
 * Lists every partner channel we push timeshare listings to, with
 * push counters per status (live / pending / rejected / paused) and
 * total views + inquiries. Editable: name, active toggle, per-listing
 * cost, API endpoint. Credentials live encrypted on the model and are
 * not surfaced to the UI in plaintext (only "configured Y/N").
 *
 * Two-way integration: each site exposes an HMAC-signed webhook URL
 * that partners POST inquiries back into. The CRM is the operator's
 * back office; every partner-channel inquiry surfaces in /listings.
 * Webhook secrets are shown ONCE on create or rotate — the operator
 * is expected to paste it into the partner's integration config
 * immediately and not see it again. The secret-reveal panel is sticky
 * until dismissed.
 *
 * Adding a real driver (push side) is still a backend change — see
 * ListingDistributor::DRIVER_MAP. The UI surfaces which slugs have a
 * real driver via has_real_driver.
 */

const page = usePage<PageProps>();
const supervisorRoles = ['master_admin', 'admin', 'supervisor', 'manager'];
const canManageSites = computed(() => {
    const role = page.props.auth.user?.role ?? '';
    return supervisorRoles.includes(role);
});

interface SiteStats {
    pushes_total: number;
    pushes_live: number;
    pushes_pending: number;
    pushes_rejected: number;
    pushes_paused: number;
    total_views: number;
    total_inquiries: number;
}

interface Site {
    id: string;
    name: string;
    slug: string;
    is_active: boolean;
    api_endpoint: string | null;
    our_cost_per_listing: number | null;
    has_config: boolean;
    has_real_driver: boolean;
    has_webhook_secret: boolean;
    webhook_inquiry_url: string;
    webhook_booking_url: string;
    webhook_last_received_at: string | null;
    created_at: string | null;
    stats: SiteStats;
}

const sites = ref<Site[]>([]);
const loading = ref(true);
const editingSiteId = ref<string | null>(null);
const editForm = ref<Partial<Site>>({});
const saving = ref(false);
const flash = ref<{ kind: 'ok' | 'err'; msg: string } | null>(null);

/* ──────────────────────────────────────────────────────────────────────
 * CREATE — slide-down form revealed by the "Add partner site" button.
 * ──────────────────────────────────────────────────────────────────── */
const showCreate = ref(false);
const createForm = ref<{
    name: string;
    slug: string;
    api_endpoint: string;
    our_cost_per_listing: string;
    is_active: boolean;
}>({ name: '', slug: '', api_endpoint: '', our_cost_per_listing: '', is_active: true });
const createErrors = ref<Record<string, string[]>>({});
const creating = ref(false);

function resetCreateForm(): void {
    createForm.value = { name: '', slug: '', api_endpoint: '', our_cost_per_listing: '', is_active: true };
    createErrors.value = {};
}

async function submitCreate(): Promise<void> {
    creating.value = true;
    createErrors.value = {};
    try {
        const payload: Record<string, unknown> = {
            name: createForm.value.name,
            is_active: createForm.value.is_active,
        };
        if (createForm.value.slug.trim()) payload.slug = createForm.value.slug.trim();
        if (createForm.value.api_endpoint.trim()) payload.api_endpoint = createForm.value.api_endpoint.trim();
        if (createForm.value.our_cost_per_listing.trim()) {
            payload.our_cost_per_listing = Number(createForm.value.our_cost_per_listing);
        }
        const { data } = await axios.post<{
            data: Site;
            webhook: { secret: string; inquiry_url: string; booking_url: string };
        }>(
            '/api/partner-sites',
            payload,
        );
        // The create response carries the plaintext secret — pin it
        // into the sticky reveal panel so the operator can copy it.
        secretReveal.value = {
            siteId: data.data.id,
            siteName: data.data.name,
            secret: data.webhook.secret,
            inquiryUrl: data.webhook.inquiry_url,
            bookingUrl: data.webhook.booking_url,
        };
        flash.value = { kind: 'ok', msg: `Partner site "${data.data.name}" created.` };
        showCreate.value = false;
        resetCreateForm();
        await load();
    } catch (e: unknown) {
        const resp = (e as { response?: { status?: number; data?: { errors?: Record<string, string[]>; message?: string } } }).response;
        if (resp?.status === 422 && resp.data?.errors) {
            createErrors.value = resp.data.errors;
        } else {
            flash.value = { kind: 'err', msg: resp?.data?.message ?? 'Could not create partner site.' };
        }
    } finally {
        creating.value = false;
    }
}

/* ──────────────────────────────────────────────────────────────────────
 * SECRET REVEAL — sticky banner shown once after create / rotate.
 * Persists across navigation within the page until the operator
 * dismisses it; we never display the secret again after that.
 * ──────────────────────────────────────────────────────────────────── */
interface SecretReveal {
    siteId: string;
    siteName: string;
    secret: string;
    inquiryUrl: string;
    bookingUrl: string;
}
const secretReveal = ref<SecretReveal | null>(null);
const secretCopied = ref(false);
async function copySecret(): Promise<void> {
    if (!secretReveal.value) return;
    try {
        await navigator.clipboard.writeText(secretReveal.value.secret);
        secretCopied.value = true;
        window.setTimeout(() => (secretCopied.value = false), 2200);
    } catch {
        window.prompt('Copy this webhook secret:', secretReveal.value.secret);
    }
}

/* ──────────────────────────────────────────────────────────────────────
 * ROTATE + ARCHIVE
 * ──────────────────────────────────────────────────────────────────── */
const rotatingId = ref<string | null>(null);
async function rotateSecret(s: Site): Promise<void> {
    if (!window.confirm(`Rotate the webhook secret for ${s.name}? The old secret will stop working immediately.`)) return;
    rotatingId.value = s.id;
    try {
        const { data } = await axios.post<{
            webhook: { secret: string; inquiry_url: string; booking_url: string };
        }>(
            `/api/partner-sites/${s.id}/rotate-secret`,
        );
        secretReveal.value = {
            siteId: s.id,
            siteName: s.name,
            secret: data.webhook.secret,
            inquiryUrl: data.webhook.inquiry_url,
            bookingUrl: data.webhook.booking_url,
        };
        flash.value = { kind: 'ok', msg: `Webhook secret rotated for ${s.name}.` };
        await load();
    } catch {
        flash.value = { kind: 'err', msg: 'Could not rotate secret.' };
    } finally {
        rotatingId.value = null;
    }
}

const archivingId = ref<string | null>(null);
async function archiveSite(s: Site): Promise<void> {
    if (!window.confirm(`Archive ${s.name}? Existing pushes + inquiries stay in history; the site stops accepting new ones.`)) return;
    archivingId.value = s.id;
    try {
        await axios.delete(`/api/partner-sites/${s.id}`);
        flash.value = { kind: 'ok', msg: `${s.name} archived.` };
        await load();
    } catch {
        flash.value = { kind: 'err', msg: 'Could not archive partner site.' };
    } finally {
        archivingId.value = null;
    }
}

/* ──────────────────────────────────────────────────────────────────────
 * COPY HELPERS
 * ──────────────────────────────────────────────────────────────────── */
/* ──────────────────────────────────────────────────────────────────────
 * WEBHOOK EVENT FEED — lazy-loaded per card.
 *
 * Each site card has an expandable "Recent activity" panel showing the
 * latest ~25 inbound webhook attempts (successes, sig failures, dupes,
 * validation rejects). We don't fetch on page load — only when the
 * operator clicks Expand — so listing 20 partner sites doesn't fan out
 * to 20 webhook-event queries.
 * ──────────────────────────────────────────────────────────────────── */
interface WebhookEvent {
    id: string;
    kind: 'inquiry' | 'booking';
    http_status: number;
    signature_valid: boolean;
    external_inquiry_id: string | null;
    external_booking_id: string | null;
    related_id: string | null;
    error_message: string | null;
    request_ip: string | null;
    user_agent: string | null;
    payload_size_bytes: number;
    created_at: string | null;
}
const expandedFeedFor = ref<string | null>(null);
const feedEvents = ref<Record<string, WebhookEvent[]>>({});
const feedLoading = ref<Record<string, boolean>>({});
const feedError = ref<Record<string, string | null>>({});

async function toggleFeed(s: Site): Promise<void> {
    if (expandedFeedFor.value === s.id) {
        expandedFeedFor.value = null;
        return;
    }
    expandedFeedFor.value = s.id;
    // Always re-fetch on expand so the operator sees fresh data after
    // testing a webhook from the partner side (they'd otherwise have
    // to reload the page to see their test).
    feedLoading.value = { ...feedLoading.value, [s.id]: true };
    feedError.value = { ...feedError.value, [s.id]: null };
    try {
        const { data } = await axios.get<{ data: WebhookEvent[] }>(
            `/api/partner-sites/${s.id}/webhook-events`,
            { params: { limit: 25 } },
        );
        feedEvents.value = { ...feedEvents.value, [s.id]: data.data };
    } catch {
        feedError.value = { ...feedError.value, [s.id]: 'Could not load events.' };
    } finally {
        feedLoading.value = { ...feedLoading.value, [s.id]: false };
    }
}

function statusPillClass(e: WebhookEvent): string {
    if (!e.signature_valid) return 'bg-floor-lose/15 text-floor-lose ring-floor-lose/30';
    if (e.http_status >= 200 && e.http_status < 300) {
        return e.http_status === 200
            // 200 = duplicate (already had the row). Useful info, not an error.
            ? 'bg-deck-muted text-deck-soft ring-deck-line'
            : 'bg-floor-win/15 text-floor-win ring-floor-win/30';
    }
    if (e.http_status === 401) return 'bg-floor-lose/15 text-floor-lose ring-floor-lose/30';
    if (e.http_status === 422) return 'bg-floor-accent/15 text-floor-accent ring-floor-accent/30';
    return 'bg-deck-muted text-deck-soft ring-deck-line';
}

function statusLabel(e: WebhookEvent): string {
    if (!e.signature_valid) return 'sig fail';
    if (e.http_status === 201) return 'created';
    if (e.http_status === 200) return 'duplicate';
    if (e.http_status === 401) return 'unauthorized';
    if (e.http_status === 422) return 'rejected';
    return String(e.http_status);
}

/** Composite key: "<siteId>:<kind>" so each row's two buttons track copy state independently. */
const copiedUrlKey = ref<string | null>(null);
async function copyWebhookUrl(s: Site, kind: 'inquiry' | 'booking'): Promise<void> {
    const url = kind === 'inquiry' ? s.webhook_inquiry_url : s.webhook_booking_url;
    const key = `${s.id}:${kind}`;
    try {
        await navigator.clipboard.writeText(url);
        copiedUrlKey.value = key;
        window.setTimeout(() => {
            if (copiedUrlKey.value === key) copiedUrlKey.value = null;
        }, 2200);
    } catch {
        window.prompt('Copy this webhook URL:', url);
    }
}

/* ──────────────────────────────────────────────────────────────────────
 * LOAD + EXISTING EDIT FLOW
 * ──────────────────────────────────────────────────────────────────── */
async function load(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get<{ data: Site[] }>('/api/partner-sites');
        sites.value = data.data;
    } finally {
        loading.value = false;
    }
}

function startEdit(s: Site): void {
    editingSiteId.value = s.id;
    editForm.value = {
        name: s.name,
        is_active: s.is_active,
        our_cost_per_listing: s.our_cost_per_listing,
        api_endpoint: s.api_endpoint,
    };
}

function cancelEdit(): void {
    editingSiteId.value = null;
    editForm.value = {};
}

async function saveEdit(siteId: string): Promise<void> {
    saving.value = true;
    flash.value = null;
    try {
        await axios.patch(`/api/partner-sites/${siteId}`, editForm.value);
        flash.value = { kind: 'ok', msg: 'Saved.' };
        editingSiteId.value = null;
        await load();
    } catch {
        flash.value = { kind: 'err', msg: 'Could not save changes.' };
    } finally {
        saving.value = false;
        window.setTimeout(() => (flash.value = null), 4000);
    }
}

onMounted(load);

const totalPushes = computed(() =>
    sites.value.reduce((sum, s) => sum + s.stats.pushes_total, 0)
);
const totalLive = computed(() =>
    sites.value.reduce((sum, s) => sum + s.stats.pushes_live, 0)
);
const totalViews = computed(() =>
    sites.value.reduce((sum, s) => sum + s.stats.total_views, 0)
);
const totalInquiries = computed(() =>
    sites.value.reduce((sum, s) => sum + s.stats.total_inquiries, 0)
);

function fmtMoney(n: number | null | undefined): string {
    if (n == null) return '—';
    if (!n) return '$0';
    return '$' + n.toFixed(2);
}

function fmtRelative(iso: string | null): string {
    if (!iso) return 'never';
    const diffMs = Date.now() - Date.parse(iso);
    const mins = Math.floor(diffMs / 60_000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const h = Math.floor(mins / 60);
    if (h < 24) return `${h}h ago`;
    const d = Math.floor(h / 24);
    return `${d}d ago`;
}
</script>

<template>
    <AppLayout title="Partner sites">
        <div class="p-6">
            <!-- Header -->
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-deck-text">Partner sites</h1>
                    <p class="text-sm text-deck-soft">
                        Where listings get pushed AND where renter inquiries flow back from.
                        Each site has its own driver (outbound) and a signed webhook URL (inbound).
                    </p>
                </div>
                <button
                    v-if="canManageSites"
                    type="button"
                    class="btn-primary"
                    @click="showCreate = !showCreate"
                >
                    {{ showCreate ? 'Cancel' : '+ Add partner site' }}
                </button>
            </div>

            <!-- Secret reveal — sticky until dismissed -->
            <div
                v-if="secretReveal"
                class="mb-4 rounded-md border-2 border-floor-accent/50 bg-floor-accent/[0.08] p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold text-floor-accent">
                            Webhook secret for {{ secretReveal.siteName }}
                        </div>
                        <p class="mt-1 text-xs text-deck-soft">
                            Copy this now — it will <strong>not</strong> be shown again.
                            Paste it into your integration with the partner so they can sign inbound webhooks.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn-ghost text-xs"
                        title="Dismiss"
                        @click="secretReveal = null"
                    >Dismiss</button>
                </div>

                <div class="mt-3 space-y-2">
                    <div>
                        <div class="deck-label">Secret</div>
                        <div class="mt-1 flex items-center gap-2">
                            <code class="flex-1 truncate rounded bg-deck-bg/70 px-3 py-2 font-mono text-sm text-floor-accent ring-1 ring-deck-line">
                                {{ secretReveal.secret }}
                            </code>
                            <button class="btn-ghost text-xs" @click="copySecret">
                                {{ secretCopied ? 'Copied ✓' : 'Copy' }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <div class="deck-label">Inquiry webhook URL <span class="text-deck-dim normal-case">(renter expressed interest)</span></div>
                        <code class="mt-1 block truncate rounded bg-deck-bg/70 px-3 py-2 font-mono text-xs text-deck-soft ring-1 ring-deck-line">
                            {{ secretReveal.inquiryUrl }}
                        </code>
                    </div>
                    <div>
                        <div class="deck-label">Booking webhook URL <span class="text-deck-dim normal-case">(renter confirmed a stay)</span></div>
                        <code class="mt-1 block truncate rounded bg-deck-bg/70 px-3 py-2 font-mono text-xs text-deck-soft ring-1 ring-deck-line">
                            {{ secretReveal.bookingUrl }}
                        </code>
                    </div>
                    <p class="font-mono text-[10px] uppercase tracking-wider text-deck-dim">
                        Partner signs the raw request body with HMAC-SHA256
                        using this secret, sends as <code>X-Partner-Signature: sha256=&lt;hex&gt;</code>.
                    </p>
                </div>
            </div>

            <!-- Create form -->
            <div v-if="showCreate" class="deck-card mb-4 p-5">
                <h2 class="text-base font-semibold text-deck-text">New partner site</h2>
                <p class="text-xs text-deck-dim">Saving generates a webhook secret you'll see once.</p>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="label">Name <span class="text-floor-lose">*</span></label>
                        <input
                            v-model="createForm.name"
                            type="text"
                            class="input mt-1"
                            placeholder="e.g. Airbnb, RedWeek, Vrbo"
                            :disabled="creating"
                        />
                        <div v-if="createErrors.name" class="mt-1 text-xs text-floor-lose">
                            {{ createErrors.name[0] }}
                        </div>
                    </div>
                    <div>
                        <label class="label">Slug <span class="text-deck-dim">(optional — auto-derived from name)</span></label>
                        <input
                            v-model="createForm.slug"
                            type="text"
                            class="input mt-1 font-mono"
                            placeholder="airbnb"
                            :disabled="creating"
                        />
                        <div v-if="createErrors.slug" class="mt-1 text-xs text-floor-lose">
                            {{ createErrors.slug[0] }}
                        </div>
                        <p class="mt-1 text-[10px] text-deck-dim">
                            Must match the ListingDistributor driver key to use a real (non-mock) push driver.
                        </p>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="label">API endpoint <span class="text-deck-dim">(optional)</span></label>
                        <input
                            v-model="createForm.api_endpoint"
                            type="url"
                            class="input mt-1 font-mono"
                            placeholder="https://api.partner.example/v2"
                            :disabled="creating"
                        />
                        <div v-if="createErrors.api_endpoint" class="mt-1 text-xs text-floor-lose">
                            {{ createErrors.api_endpoint[0] }}
                        </div>
                    </div>
                    <div>
                        <label class="label">Per-listing cost (USD) <span class="text-deck-dim">(optional)</span></label>
                        <input
                            v-model="createForm.our_cost_per_listing"
                            type="number" min="0" step="0.01" max="9999.99"
                            class="input mt-1"
                            placeholder="0.00"
                            :disabled="creating"
                        />
                        <div v-if="createErrors.our_cost_per_listing" class="mt-1 text-xs text-floor-lose">
                            {{ createErrors.our_cost_per_listing[0] }}
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="flex items-center gap-2 text-sm text-deck-soft">
                        <input v-model="createForm.is_active" type="checkbox" class="rounded border-deck-line" :disabled="creating" />
                        Active — allow new pushes immediately
                    </label>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        class="btn-ghost"
                        :disabled="creating"
                        @click="showCreate = false; resetCreateForm()"
                    >Cancel</button>
                    <button
                        class="btn-primary"
                        :disabled="creating || !createForm.name.trim()"
                        @click="submitCreate"
                    >
                        {{ creating ? 'Creating…' : 'Create partner site' }}
                    </button>
                </div>
            </div>

            <!-- Flash -->
            <div v-if="flash"
                 class="mb-4 rounded-md px-3 py-2 text-sm"
                 :class="flash.kind === 'ok'
                     ? 'border border-floor-win/30 bg-floor-win/10 text-floor-win'
                     : 'border border-floor-lose/30 bg-floor-lose/10 text-floor-lose'">
                {{ flash.msg }}
            </div>

            <!-- Aggregate strip -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-4">
                <div class="deck-card p-4">
                    <div class="deck-label">Total pushes</div>
                    <div class="mt-1 deck-num text-2xl">{{ totalPushes || '—' }}</div>
                </div>
                <div class="deck-card p-4">
                    <div class="deck-label">Live now</div>
                    <div class="mt-1 deck-num text-2xl text-floor-win">{{ totalLive || '—' }}</div>
                </div>
                <div class="deck-card p-4">
                    <div class="deck-label">Views (lifetime)</div>
                    <div class="mt-1 deck-num text-2xl text-floor-info">{{ totalViews || '—' }}</div>
                </div>
                <div class="deck-card p-4">
                    <div class="deck-label">Inquiries (lifetime)</div>
                    <div class="mt-1 deck-num text-2xl text-floor-accent">{{ totalInquiries || '—' }}</div>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="loading" class="panel p-6 text-sm text-deck-soft">Loading partner sites…</div>

            <!-- Empty -->
            <div v-else-if="sites.length === 0" class="panel p-12 text-center text-sm text-deck-dim">
                <div class="text-base">No partner sites configured yet.</div>
                <p class="mt-2 max-w-md mx-auto italic">
                    Add a site to start pushing listings out — and to get a unique webhook URL
                    partners can post inquiries back to.
                </p>
                <button
                    v-if="canManageSites"
                    type="button"
                    class="btn-primary mt-4"
                    @click="showCreate = true"
                >+ Add your first partner site</button>
            </div>

            <!-- Site cards -->
            <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div v-for="s in sites" :key="s.id" class="deck-card p-5">
                    <!-- Title row -->
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="text-lg font-semibold text-deck-text">{{ s.name }}</h3>
                                <span class="pill font-mono ring-1 ring-inset"
                                      :class="s.is_active
                                          ? 'bg-floor-win/15 text-floor-win ring-floor-win/30'
                                          : 'bg-deck-muted text-deck-soft ring-deck-line'">
                                    {{ s.is_active ? 'active' : 'paused' }}
                                </span>
                                <span v-if="s.has_real_driver" class="pill bg-floor-info/15 text-floor-info ring-1 ring-floor-info/30 font-mono">
                                    real driver
                                </span>
                                <span v-else class="pill bg-floor-accent/15 text-floor-accent ring-1 ring-floor-accent/30 font-mono">
                                    mock driver
                                </span>
                            </div>
                            <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim mt-1">
                                slug: {{ s.slug }} · {{ fmtMoney(s.our_cost_per_listing) }} per listing
                            </div>
                        </div>
                        <div v-if="editingSiteId !== s.id" class="flex gap-1">
                            <button class="btn-ghost text-xs" @click="startEdit(s)">Edit</button>
                            <button
                                v-if="canManageSites"
                                class="btn-ghost text-xs text-floor-lose hover:bg-floor-lose/10"
                                :disabled="archivingId === s.id"
                                @click="archiveSite(s)"
                            >
                                {{ archivingId === s.id ? 'Archiving…' : 'Archive' }}
                            </button>
                        </div>
                    </div>

                    <!-- Stats grid -->
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div>
                            <div class="deck-label">Pushes</div>
                            <div class="mt-1 deck-num text-base">{{ s.stats.pushes_total || '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Live</div>
                            <div class="mt-1 deck-num text-base text-floor-win">{{ s.stats.pushes_live || '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Pending</div>
                            <div class="mt-1 deck-num text-base text-floor-accent">{{ s.stats.pushes_pending || '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Rejected</div>
                            <div class="mt-1 deck-num text-base"
                                 :class="s.stats.pushes_rejected > 0 ? 'text-floor-lose' : 'text-deck-dim'">
                                {{ s.stats.pushes_rejected || '—' }}
                            </div>
                        </div>
                        <div>
                            <div class="deck-label">Paused</div>
                            <div class="mt-1 deck-num text-base text-deck-soft">{{ s.stats.pushes_paused || '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Views</div>
                            <div class="mt-1 deck-num text-base text-floor-info">{{ s.stats.total_views || '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Inquiries</div>
                            <div class="mt-1 deck-num text-base text-floor-accent">{{ s.stats.total_inquiries || '—' }}</div>
                        </div>
                        <div>
                            <div class="deck-label">Push config</div>
                            <div class="mt-1 deck-num text-base"
                                 :class="s.has_config ? 'text-floor-win' : 'text-deck-dim'">
                                {{ s.has_config ? '✓' : '—' }}
                            </div>
                        </div>
                    </div>

                    <!-- API endpoint -->
                    <div v-if="s.api_endpoint && editingSiteId !== s.id" class="mt-3 text-xs">
                        <div class="deck-label">Partner endpoint (outbound)</div>
                        <div class="font-mono text-deck-soft mt-1 truncate">{{ s.api_endpoint }}</div>
                    </div>

                    <!-- Webhook panel — inbound. Always shown so partners can be
                         pointed at the URLs even if no events have arrived yet.
                         Two endpoints share the same secret: inquiries (renter
                         expressed interest) and bookings (renter confirmed). -->
                    <div
                        v-if="editingSiteId !== s.id"
                        class="mt-4 rounded-md border border-deck-line bg-deck-bg/50 p-3"
                    >
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <div class="deck-label">Inbound webhooks</div>
                            <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                                last received: <span :class="s.webhook_last_received_at ? 'text-deck-soft' : 'text-deck-dim'">{{ fmtRelative(s.webhook_last_received_at) }}</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim mb-1">
                                    Inquiry · renter interest
                                </div>
                                <div class="flex items-center gap-2">
                                    <code class="flex-1 truncate rounded bg-deck-bg/70 px-2 py-1.5 font-mono text-[11px] text-deck-soft ring-1 ring-deck-line">
                                        {{ s.webhook_inquiry_url }}
                                    </code>
                                    <button
                                        class="btn-ghost text-xs"
                                        title="Copy inquiry webhook URL"
                                        @click="copyWebhookUrl(s, 'inquiry')"
                                    >
                                        {{ copiedUrlKey === `${s.id}:inquiry` ? 'Copied ✓' : 'Copy' }}
                                    </button>
                                </div>
                            </div>
                            <div>
                                <div class="text-[10px] font-mono uppercase tracking-wider text-deck-dim mb-1">
                                    Booking · confirmed stay
                                </div>
                                <div class="flex items-center gap-2">
                                    <code class="flex-1 truncate rounded bg-deck-bg/70 px-2 py-1.5 font-mono text-[11px] text-deck-soft ring-1 ring-deck-line">
                                        {{ s.webhook_booking_url }}
                                    </code>
                                    <button
                                        class="btn-ghost text-xs"
                                        title="Copy booking webhook URL"
                                        @click="copyWebhookUrl(s, 'booking')"
                                    >
                                        {{ copiedUrlKey === `${s.id}:booking` ? 'Copied ✓' : 'Copy' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-2 border-t border-deck-line pt-2">
                            <span class="text-[11px] text-deck-dim">
                                Secret status:
                                <span :class="s.has_webhook_secret ? 'text-floor-win' : 'text-floor-lose'">
                                    {{ s.has_webhook_secret ? 'configured' : 'not set' }}
                                </span>
                            </span>
                            <div class="flex items-center gap-1">
                                <button
                                    class="btn-ghost text-xs"
                                    :title="expandedFeedFor === s.id
                                        ? 'Hide recent webhook activity'
                                        : 'Show recent webhook activity'"
                                    @click="toggleFeed(s)"
                                >
                                    {{ expandedFeedFor === s.id ? '▾ Hide activity' : '▸ View activity' }}
                                </button>
                                <button
                                    v-if="canManageSites"
                                    class="btn-ghost text-xs"
                                    :disabled="rotatingId === s.id"
                                    :title="s.has_webhook_secret
                                        ? 'Rotate (invalidates the old secret immediately)'
                                        : 'Mint a webhook secret'"
                                    @click="rotateSecret(s)"
                                >
                                    {{ rotatingId === s.id
                                        ? 'Rotating…'
                                        : s.has_webhook_secret ? 'Rotate secret' : 'Mint secret' }}
                                </button>
                            </div>
                        </div>

                        <!-- Activity feed — lazy-loaded on expand -->
                        <div
                            v-if="expandedFeedFor === s.id"
                            class="mt-3 border-t border-deck-line pt-3"
                        >
                            <div v-if="feedLoading[s.id]" class="text-xs text-deck-dim">
                                Loading activity…
                            </div>
                            <div v-else-if="feedError[s.id]" class="text-xs text-floor-lose">
                                {{ feedError[s.id] }}
                            </div>
                            <div
                                v-else-if="!feedEvents[s.id] || feedEvents[s.id].length === 0"
                                class="text-xs text-deck-dim italic"
                            >
                                No webhook activity yet. Once the partner posts to either
                                URL, every attempt — successes, signature failures, validation
                                rejects — shows up here.
                            </div>
                            <div v-else class="space-y-1.5 max-h-72 overflow-y-auto">
                                <div
                                    v-for="e in feedEvents[s.id]"
                                    :key="e.id"
                                    class="rounded-md border border-deck-line bg-deck-bg/50 px-2.5 py-2 text-[11px]"
                                >
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="pill font-mono ring-1 ring-inset" :class="statusPillClass(e)">
                                            {{ statusLabel(e) }}
                                        </span>
                                        <span class="pill font-mono bg-deck-muted text-deck-soft ring-1 ring-deck-line">
                                            {{ e.kind }}
                                        </span>
                                        <span class="font-mono tabular-nums text-deck-dim">
                                            HTTP {{ e.http_status }}
                                        </span>
                                        <span class="ml-auto font-mono tabular-nums text-deck-soft">
                                            {{ fmtRelative(e.created_at) }}
                                        </span>
                                    </div>
                                    <!-- External ids row — only shown when present -->
                                    <div
                                        v-if="e.external_inquiry_id || e.external_booking_id"
                                        class="mt-1 flex items-center gap-3 font-mono text-[10px] text-deck-dim"
                                    >
                                        <span v-if="e.external_inquiry_id">
                                            inquiry: <span class="text-deck-soft">{{ e.external_inquiry_id }}</span>
                                        </span>
                                        <span v-if="e.external_booking_id">
                                            booking: <span class="text-deck-soft">{{ e.external_booking_id }}</span>
                                        </span>
                                    </div>
                                    <!-- Error / context -->
                                    <div
                                        v-if="e.error_message"
                                        class="mt-1 text-[11px] text-floor-lose break-words"
                                    >{{ e.error_message }}</div>
                                    <!-- Forensics row — IP + payload size, dim by default -->
                                    <div class="mt-1 flex items-center gap-3 font-mono text-[10px] text-deck-dim">
                                        <span v-if="e.request_ip">from <span class="text-deck-soft">{{ e.request_ip }}</span></span>
                                        <span>{{ e.payload_size_bytes }} bytes</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit form -->
                    <div v-if="editingSiteId === s.id" class="mt-4 border-t border-deck-line pt-4 space-y-3">
                        <div>
                            <label class="label">Name</label>
                            <input v-model="editForm.name" type="text" class="input mt-1 text-sm" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="label">Per-listing cost (USD)</label>
                                <input
                                    v-model.number="editForm.our_cost_per_listing"
                                    type="number" min="0" step="0.01" max="9999.99"
                                    class="input mt-1 text-sm"
                                />
                            </div>
                            <div class="flex items-end">
                                <label class="flex items-center gap-2 text-sm text-deck-soft pb-1">
                                    <input v-model="editForm.is_active" type="checkbox" class="rounded border-deck-line" />
                                    Active (allow new pushes)
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="label">API endpoint</label>
                            <input v-model="editForm.api_endpoint" type="text" placeholder="https://api.partner.example/v2" class="input mt-1 text-sm" />
                        </div>
                        <div class="flex gap-2 justify-end">
                            <button class="btn-ghost text-xs" :disabled="saving" @click="cancelEdit">Cancel</button>
                            <button class="btn-primary text-xs" :disabled="saving" @click="saveEdit(s.id)">
                                {{ saving ? 'Saving…' : 'Save' }}
                            </button>
                        </div>
                        <p class="text-[10px] font-mono uppercase tracking-wider text-deck-dim">
                            Outbound credentials live encrypted on the <code>config</code> column.
                            Inbound webhook secret rotates via the panel above.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
