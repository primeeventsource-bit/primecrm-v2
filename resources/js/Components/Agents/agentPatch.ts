// Pure helpers for the Edit Agent form. Extracted so they can be
// unit-tested without mounting the Vue component — the diff logic is
// the only piece that's algorithmic enough to be worth testing, and
// it's the bit most likely to break silently (a wrong field name or
// missing strict-equality comparison just sends a slightly-wrong
// patch the user can't see).

export type PayType = 'hourly' | 'salary' | 'commission_only' | 'hybrid';

export interface AgentSnapshot {
    first_name: string | null;
    last_name: string | null;
    role: string;
    phone: string | null;
    extension: string | null;
    timezone: string | null;
    is_panama_based: boolean;
    status?: string;
    pay_type: PayType | null;
    base_rate_cents: number | null;
    pay_currency: string | null;
    pay_notes: string | null;
    commission: {
        plan_id: string;
        plan_name: string | null;
        effective_from: string | null;
        override_rate_pct: number | null;
    } | null;
}

export interface AgentFormState {
    first_name: string;
    last_name: string;
    role: string;
    phone: string;
    extension: string;
    timezone: string;
    is_panama_based: boolean;
    status: string;
    pay_type: PayType;
    /** Decimal dollars as a string (form input value). */
    base_rate: string;
    pay_currency: string;
    pay_notes: string;
    commission_plan_id: string;
    /** Percent as a string (form input value); '' = unset. */
    commission_override_rate: string;
}

/**
 * Build a minimal patch payload — only fields whose current form value
 * differs from the loaded snapshot are included.
 *
 * Empty-string clears (e.g. phone) are sent as explicit `null` so the
 * server treats them as "clear" rather than "unchanged". Cleared
 * commission/base_rate likewise become `null`.
 *
 * Commission has a coupled invariant: an override only makes sense in
 * the context of a plan, so the patch sends both `commission_plan_id`
 * and `commission_override_rate` together when either has changed
 * (and forces the override to `null` when the plan is cleared).
 */
export function buildAgentPatch(initial: AgentSnapshot, form: AgentFormState): Record<string, unknown> {
    const patch: Record<string, unknown> = {};

    if (form.first_name !== (initial.first_name ?? '')) patch.first_name = form.first_name;
    if (form.last_name !== (initial.last_name ?? '')) patch.last_name = form.last_name;
    if (form.role !== initial.role) patch.role = form.role;
    if (form.phone !== (initial.phone ?? '')) patch.phone = form.phone || null;
    if (form.extension !== (initial.extension ?? '')) patch.extension = form.extension || null;
    if (form.timezone !== (initial.timezone ?? 'America/New_York')) patch.timezone = form.timezone;
    if (form.is_panama_based !== initial.is_panama_based) patch.is_panama_based = form.is_panama_based;
    if (form.status !== (initial.status ?? 'active')) patch.status = form.status;

    // Compensation
    if (form.pay_type !== (initial.pay_type ?? 'commission_only')) patch.pay_type = form.pay_type;

    const newRate = form.base_rate === '' ? null : Number(form.base_rate);
    const oldRate = initial.base_rate_cents != null ? initial.base_rate_cents / 100 : null;
    if (newRate !== oldRate) patch.base_rate = newRate;

    if (form.pay_currency !== (initial.pay_currency ?? 'USD')) patch.pay_currency = form.pay_currency || null;
    if (form.pay_notes !== (initial.pay_notes ?? '')) patch.pay_notes = form.pay_notes || null;

    // Commission — send both fields together if either changed.
    const newPlanId = form.commission_plan_id || null;
    const oldPlanId = initial.commission?.plan_id ?? null;
    const newOverride = form.commission_override_rate === '' ? null : Number(form.commission_override_rate);
    const oldOverride = initial.commission?.override_rate_pct ?? null;

    if (newPlanId !== oldPlanId || newOverride !== oldOverride) {
        patch.commission_plan_id = newPlanId;
        // Override is only meaningful with a plan attached.
        patch.commission_override_rate = newPlanId === null ? null : newOverride;
    }

    return patch;
}
