import { describe, expect, it } from 'vitest';
import { buildAgentPatch, type AgentFormState, type AgentSnapshot } from './agentPatch';

// Concrete fixture builder so each test only specifies what it cares about.
function makeSnapshot(overrides: Partial<AgentSnapshot> = {}): AgentSnapshot {
    return {
        first_name: 'Sofia',
        last_name: 'Cruz',
        role: 'closer',
        phone: null,
        extension: null,
        timezone: 'America/New_York',
        is_panama_based: false,
        status: 'active',
        pay_type: 'hourly',
        base_rate_cents: 1850,
        pay_currency: 'USD',
        pay_notes: null,
        commission: {
            plan_id: 'plan-a',
            plan_name: 'Standard Closer 8%',
            effective_from: '2026-01-01',
            override_rate_pct: null,
        },
        ...overrides,
    };
}

function makeFormFrom(snap: AgentSnapshot): AgentFormState {
    return {
        first_name: snap.first_name ?? '',
        last_name: snap.last_name ?? '',
        role: snap.role,
        phone: snap.phone ?? '',
        extension: snap.extension ?? '',
        timezone: snap.timezone ?? 'America/New_York',
        is_panama_based: snap.is_panama_based,
        status: snap.status ?? 'active',
        pay_type: snap.pay_type ?? 'commission_only',
        base_rate: snap.base_rate_cents != null ? (snap.base_rate_cents / 100).toFixed(2) : '',
        pay_currency: snap.pay_currency ?? 'USD',
        pay_notes: snap.pay_notes ?? '',
        commission_plan_id: snap.commission?.plan_id ?? '',
        commission_override_rate: snap.commission?.override_rate_pct != null
            ? String(snap.commission.override_rate_pct)
            : '',
    };
}

describe('buildAgentPatch', () => {
    it('returns an empty object when the form is unchanged', () => {
        const snap = makeSnapshot();
        expect(buildAgentPatch(snap, makeFormFrom(snap))).toEqual({});
    });

    it('includes only the changed fields', () => {
        const snap = makeSnapshot();
        const form = makeFormFrom(snap);
        form.role = 'fronter';

        expect(buildAgentPatch(snap, form)).toEqual({ role: 'fronter' });
    });

    it('sends an empty phone string as explicit null (clear), not empty string', () => {
        const snap = makeSnapshot({ phone: '+18005551212' });
        const form = makeFormFrom(snap);
        form.phone = '';

        expect(buildAgentPatch(snap, form)).toEqual({ phone: null });
    });

    it('converts the form base_rate (decimal dollars string) to a number diff', () => {
        const snap = makeSnapshot({ base_rate_cents: 1850 });
        const form = makeFormFrom(snap);
        form.base_rate = '20.00';

        expect(buildAgentPatch(snap, form)).toEqual({ base_rate: 20 });
    });

    it('detects override-rate change on the same plan and sends both fields', () => {
        const snap = makeSnapshot({
            commission: {
                plan_id: 'plan-a',
                plan_name: 'Plan A',
                effective_from: '2026-01-01',
                override_rate_pct: 8,
            },
        });
        const form = makeFormFrom(snap);
        form.commission_override_rate = '12';

        expect(buildAgentPatch(snap, form)).toEqual({
            commission_plan_id: 'plan-a',
            commission_override_rate: 12,
        });
    });

    it('forces override to null when the plan is cleared', () => {
        const snap = makeSnapshot({
            commission: {
                plan_id: 'plan-a',
                plan_name: 'Plan A',
                effective_from: '2026-01-01',
                override_rate_pct: 10,
            },
        });
        const form = makeFormFrom(snap);
        form.commission_plan_id = '';
        // User left override field as 10 — but it must not be sent
        // when the plan is null (override on no-plan is incoherent).
        form.commission_override_rate = '10';

        expect(buildAgentPatch(snap, form)).toEqual({
            commission_plan_id: null,
            commission_override_rate: null,
        });
    });

    it('sends commission fields when switching to a different plan', () => {
        const snap = makeSnapshot();
        const form = makeFormFrom(snap);
        form.commission_plan_id = 'plan-b';
        form.commission_override_rate = '9';

        expect(buildAgentPatch(snap, form)).toEqual({
            commission_plan_id: 'plan-b',
            commission_override_rate: 9,
        });
    });

    it('does NOT include commission fields when both plan and override match snapshot', () => {
        const snap = makeSnapshot({
            commission: {
                plan_id: 'plan-a',
                plan_name: 'Plan A',
                effective_from: '2026-01-01',
                override_rate_pct: 12,
            },
        });
        const form = makeFormFrom(snap);

        // Touch an unrelated field so the patch isn't empty for an
        // unrelated reason.
        form.first_name = 'Renamed';

        const patch = buildAgentPatch(snap, form);
        expect(patch).toHaveProperty('first_name', 'Renamed');
        expect(patch).not.toHaveProperty('commission_plan_id');
        expect(patch).not.toHaveProperty('commission_override_rate');
    });

    it('treats null override and unset override as equivalent (no spurious diff)', () => {
        // Snapshot has override_rate_pct = null. Form starts as ''. No
        // user input — patch must not include commission_* keys.
        const snap = makeSnapshot();
        const form = makeFormFrom(snap);

        expect(buildAgentPatch(snap, form)).toEqual({});
    });
});
