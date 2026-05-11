<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Partner-sites configuration + performance metrics.
 *
 * Lists every partner channel we push timeshare listings to, with
 * push counters per status (live / pending / rejected / paused) and
 * total views + inquiries. Editable: name, active toggle, per-listing
 * cost, API endpoint. Credentials live encrypted on the model and
 * are not surfaced to the UI in plaintext (only "configured Y/N").
 *
 * Adding a real integration is a backend code change (implement
 * PartnerDriver, register in ListingDistributor::DRIVER_MAP); the
 * UI here surfaces whether each site has a real driver via
 * has_real_driver so operators know which sites use the mock fallback.
 */

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
    created_at: string | null;
    stats: SiteStats;
}

const sites = ref<Site[]>([]);
const loading = ref(true);
const editingSiteId = ref<string | null>(null);
const editForm = ref<Partial<Site>>({});
const saving = ref(false);
const flash = ref<{ kind: 'ok' | 'err'; msg: string } | null>(null);

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
</script>

<template>
    <AppLayout title="Partner sites">
        <div class="p-6">
            <!-- Header -->
            <div class="mb-4">
                <h1 class="text-2xl font-semibold text-deck-text">Partner sites</h1>
                <p class="text-sm text-deck-soft">
                    Where listings get pushed. Each site has its own driver — real integrations replace the mock one as they ship.
                </p>
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
            <div v-else-if="sites.length === 0" class="panel p-12 text-center text-sm text-deck-dim italic">
                No partner sites configured. Run the seeder, or insert rows directly into <code>partner_sites</code>.
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
                        <button
                            v-if="editingSiteId !== s.id"
                            class="btn-ghost text-xs"
                            @click="startEdit(s)"
                        >Edit</button>
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
                            <div class="deck-label">Configured?</div>
                            <div class="mt-1 deck-num text-base"
                                 :class="s.has_config ? 'text-floor-win' : 'text-deck-dim'">
                                {{ s.has_config ? '✓' : '—' }}
                            </div>
                        </div>
                    </div>

                    <!-- API endpoint -->
                    <div v-if="s.api_endpoint && editingSiteId !== s.id" class="mt-3 text-xs">
                        <div class="deck-label">Endpoint</div>
                        <div class="font-mono text-deck-soft mt-1 truncate">{{ s.api_endpoint }}</div>
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
                            Credentials are managed at the model layer (encrypted on the <code>config</code> column). API key changes still require a backend update.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
