/**
 * Wire shapes returned by /api/* endpoints.
 *
 * Mirrors the Laravel Resource classes 1:1. When a Resource adds/removes
 * a field, update the matching interface here. Keep camelCase mapping out
 * of these — they should be exactly what the API returns.
 */

export type Uuid = string;

export interface AuthUser {
    id: Uuid;
    tenant_id: Uuid;
    name: string;
    email: string;
    role: string;
    permissions: string[];
}

export interface PageProps {
    auth: { user: AuthUser | null };
    flash: { success?: string; error?: string };
    csrf: string;
    echo: {
        host: string;
        key: string;
        cluster: string;
    };
}

export type AgentStatusValue =
    | 'available'
    | 'on_call'
    | 'wrap_up'
    | 'on_break'
    | 'offline';

export interface AgentStatusRecord {
    agent_id: Uuid;
    status: AgentStatusValue;
    previous_status: AgentStatusValue | null;
    current_call_id: Uuid | null;
    current_session_id: Uuid | null;
    status_changed_at: string | null;
    last_heartbeat_at: string | null;
}

export interface DialSession {
    id: Uuid;
    agent_id: Uuid;
    campaign_id: Uuid | null;
    mode: 'manual' | 'preview' | 'progressive' | 'predictive';
    status: 'active' | 'paused' | 'stopped' | 'ended';
    leads_processed: number;
    calls_initiated: number;
    calls_connected: number;
    calls_abandoned: number;
    total_talk_seconds: number;
    started_at: string | null;
    paused_at: string | null;
    ended_at: string | null;
}

export type CallStatus =
    | 'queued'
    | 'initiated'
    | 'ringing'
    | 'in_progress'
    | 'completed'
    | 'busy'
    | 'no_answer'
    | 'failed'
    | 'canceled';

export interface Call {
    id: Uuid;
    lead_id: Uuid | null;
    agent_id: Uuid | null;
    dial_session_id: Uuid | null;
    campaign_id: Uuid | null;
    provider: string;
    provider_call_sid: string | null;
    from_number: string;
    to_number: string;
    direction: 'outbound' | 'inbound' | 'internal_transfer';
    status: CallStatus;
    substatus: string | null;
    disposition: string | null;
    disposition_notes: string | null;
    queued_at: string | null;
    initiated_at: string | null;
    answered_at: string | null;
    ended_at: string | null;
    ring_seconds: number;
    duration_seconds: number;
    has_recording: boolean;
}

export interface Lead {
    id: Uuid;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    email: string | null;
    phone: string;
    alternate_phone: string | null;
    country: string | null;
    state: string | null;
    city: string | null;
    postal_code: string | null;
    timezone: string | null;
    status: string | null;
    substatus: string | null;
    priority: 'low' | 'normal' | 'high' | 'hot' | null;
    score: number;
    source: string;
    source_campaign: string | null;
    source_medium: string | null;
    resort_interest: string | null;
    property_type: string | null;
    estimated_value: string | null;
    assigned_agent_id: Uuid | null;
    assigned_at: string | null;
    last_contacted_at: string | null;
    contact_attempts: number;
    is_on_dnc: boolean;
    has_express_consent: boolean;
    consent_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface GuardrailDecision {
    allowed: boolean;
    rejection_code: string | null;
    category: string | null;
    reason: string | null;
    metadata: Record<string, unknown>;
}
