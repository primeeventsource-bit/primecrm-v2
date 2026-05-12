import { describe, expect, it } from 'vitest';
import { buildRuleConfig, type RuleFormState } from './ruleConfig';

function baseForm(overrides: Partial<RuleFormState> = {}): RuleFormState {
    return {
        rule_type: 'percentage',
        pct_rate: '8',
        pct_base_field: 'amount',
        flat_amount: '',
        override_rate: '1',
        tiered_base_field: 'amount',
        tiered_marginal: false,
        tiered_brackets: [],
        raw_config: '',
        ...overrides,
    };
}

describe('buildRuleConfig', () => {
    it('packs a percentage rule as decimal rate + base_field', () => {
        const { config, error } = buildRuleConfig(baseForm({
            rule_type: 'percentage',
            pct_rate: '8.5',
            pct_base_field: 'amount',
        }));

        expect(error).toBeUndefined();
        expect(config).toEqual({ rate: 0.085, base_field: 'amount' });
    });

    it('defaults base_field to "amount" when blank on a percentage rule', () => {
        const { config } = buildRuleConfig(baseForm({
            rule_type: 'percentage',
            pct_rate: '10',
            pct_base_field: '',
        }));

        expect(config).toEqual({ rate: 0.1, base_field: 'amount' });
    });

    it('packs a flat rule with the raw dollar amount', () => {
        const { config } = buildRuleConfig(baseForm({
            rule_type: 'flat',
            flat_amount: '250',
        }));

        expect(config).toEqual({ amount: 250 });
    });

    it('packs an override rule with override_rate as a decimal', () => {
        const { config } = buildRuleConfig(baseForm({
            rule_type: 'override',
            override_rate: '1.5',
        }));

        expect(config).toEqual({ override_rate: 0.015 });
    });

    describe('tiered', () => {
        it('packs brackets sorted ascending with the open-ended tier last', () => {
            const { config } = buildRuleConfig(baseForm({
                rule_type: 'tiered',
                tiered_marginal: false,
                tiered_brackets: [
                    { up_to: null, rate: 0.10 }, // top tier — should end up last
                    { up_to: 5000, rate: 0.05 },
                    { up_to: 20000, rate: 0.08 },
                ],
            }));

            expect(config).toEqual({
                brackets: [
                    { up_to: 5000, rate: 0.05 },
                    { up_to: 20000, rate: 0.08 },
                    { up_to: null, rate: 0.10 },
                ],
                marginal: false,
                base_field: 'amount',
            });
        });

        it('preserves the marginal flag when set', () => {
            const { config } = buildRuleConfig(baseForm({
                rule_type: 'tiered',
                tiered_marginal: true,
                tiered_brackets: [{ up_to: null, rate: 0.08 }],
            }));

            expect((config as any).marginal).toBe(true);
        });

        it('errors when no brackets are provided', () => {
            const result = buildRuleConfig(baseForm({
                rule_type: 'tiered',
                tiered_brackets: [],
            }));

            expect(result.config).toBeNull();
            expect(result.error).toContain('bracket');
        });
    });

    describe('bonus', () => {
        it('passes hand-edited JSON through verbatim when parseable', () => {
            const { config } = buildRuleConfig(baseForm({
                rule_type: 'bonus',
                raw_config: '{"threshold": 10, "amount": 500}',
            }));

            expect(config).toEqual({ threshold: 10, amount: 500 });
        });

        it('returns an error on invalid JSON', () => {
            const { config, error } = buildRuleConfig(baseForm({
                rule_type: 'bonus',
                raw_config: '{not json',
            }));

            expect(config).toBeNull();
            expect(error).toContain('JSON');
        });

        it('treats blank bonus config as {}', () => {
            const { config } = buildRuleConfig(baseForm({
                rule_type: 'bonus',
                raw_config: '',
            }));

            expect(config).toEqual({});
        });
    });
});
