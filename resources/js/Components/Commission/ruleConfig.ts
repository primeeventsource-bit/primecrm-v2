// Pure helpers for the rule editor. Extracted so the type-specific
// config-shaping logic — percent → rate decimal, dollars stay dollars,
// override % → rate decimal, etc. — can be unit-tested without DOM.

export type RuleType = 'percentage' | 'flat' | 'override' | 'tiered' | 'bonus';

export interface TieredBracket {
    /** null = unbounded top tier */
    up_to: number | null;
    /** Decimal rate (e.g. 0.08 = 8%) */
    rate: number;
}

export interface RuleFormState {
    rule_type: RuleType;

    // percentage
    pct_rate: string;        // percent, as a form-input string
    pct_base_field: string;

    // flat
    flat_amount: string;     // dollars as a string

    // override
    override_rate: string;   // percent, as a form-input string

    // tiered (structured)
    tiered_base_field: string;
    tiered_marginal: boolean;
    tiered_brackets: TieredBracket[];

    // bonus / fallback
    raw_config: string;      // JSON
}

export interface BuildConfigResult {
    config: Record<string, unknown> | null;
    error?: string;
}

/**
 * Build the `config` JSON object the API expects for a given rule
 * type from the form's flat fields.
 *
 * Returns `{ config: null, error }` when the user-supplied JSON or
 * tiered-brackets payload is malformed — the caller surfaces this as
 * a form error rather than letting a 422 round-trip back from the API.
 */
export function buildRuleConfig(form: RuleFormState): BuildConfigResult {
    switch (form.rule_type) {
        case 'percentage':
            return {
                config: {
                    rate: Number(form.pct_rate) / 100,
                    base_field: form.pct_base_field || 'amount',
                },
            };

        case 'flat':
            return {
                config: { amount: Number(form.flat_amount) },
            };

        case 'override':
            return {
                config: { override_rate: Number(form.override_rate) / 100 },
            };

        case 'tiered': {
            const brackets = form.tiered_brackets
                .filter((b) => !(b.up_to == null && b.rate === 0)) // drop empty rows
                .map((b) => ({
                    up_to: b.up_to,
                    rate: Number(b.rate),
                }));

            if (brackets.length === 0) {
                return { config: null, error: 'Add at least one bracket.' };
            }

            // Engine sorts internally, but sort here too so the saved
            // payload reads top-down. Open-ended tier (up_to === null)
            // always last.
            brackets.sort((a, b) => {
                if (a.up_to == null) return 1;
                if (b.up_to == null) return -1;
                return a.up_to - b.up_to;
            });

            return {
                config: {
                    brackets,
                    marginal: form.tiered_marginal,
                    base_field: form.tiered_base_field || 'amount',
                },
            };
        }

        case 'bonus':
            // Bonus is not yet implemented in the engine; we accept
            // hand-edited JSON so operators can stage future rules,
            // but the payout pipeline ignores them today.
            try {
                return { config: JSON.parse(form.raw_config || '{}') };
            } catch {
                return { config: null, error: 'Config JSON is not valid.' };
            }
    }
}
