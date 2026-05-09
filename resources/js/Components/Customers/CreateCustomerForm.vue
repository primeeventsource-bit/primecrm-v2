<script setup lang="ts">
import { ref } from 'vue';
import axios from 'axios';

const emit = defineEmits<{ (e: 'created'): void; (e: 'cancel'): void }>();

const form = ref({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    alternate_phone: '',
    country: 'US',
    state: '',
    city: '',
    postal_code: '',
    status: 'active' as 'active' | 'vip' | 'prospect' | 'churned' | 'blacklisted',
    source: 'manual',
    notes: '',
});

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const dedupNote = ref<string | null>(null);

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    dedupNote.value = null;

    const payload: Record<string, unknown> = { ...form.value };
    for (const k of Object.keys(payload)) {
        if (payload[k] === '' || payload[k] === null) delete payload[k];
    }

    try {
        const { data, status } = await axios.post('/api/customers', payload);
        if (status === 200 && data?.meta?.was_duplicate) {
            dedupNote.value = 'A customer with this phone already exists. Returned the existing record.';
        }
        emit('created');
    } catch (err: unknown) {
        const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } };
        errors.value = e.response?.data?.errors ?? {};
        if (!Object.keys(errors.value).length && e.response?.data?.message) {
            errors.value._global = [e.response.data.message];
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <div v-if="errors._global" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errors._global[0] }}
        </div>
        <div v-if="dedupNote" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
            {{ dedupNote }}
        </div>

        <fieldset class="grid grid-cols-2 gap-3">
            <div>
                <label class="label">First name</label>
                <input v-model="form.first_name" type="text" class="input mt-1" maxlength="120" />
                <p v-if="errors.first_name" class="mt-1 text-xs text-red-600">{{ errors.first_name[0] }}</p>
            </div>
            <div>
                <label class="label">Last name</label>
                <input v-model="form.last_name" type="text" class="input mt-1" maxlength="120" />
            </div>
            <div>
                <label class="label">Phone <span class="text-red-600">*</span></label>
                <input v-model="form.phone" type="tel" placeholder="+14155551234" class="input mt-1" required />
                <p v-if="errors.phone" class="mt-1 text-xs text-red-600">{{ errors.phone[0] }}</p>
            </div>
            <div>
                <label class="label">Email</label>
                <input v-model="form.email" type="email" class="input mt-1" />
                <p v-if="errors.email" class="mt-1 text-xs text-red-600">{{ errors.email[0] }}</p>
            </div>
            <div>
                <label class="label">Alt phone</label>
                <input v-model="form.alternate_phone" type="tel" class="input mt-1" />
            </div>
            <div>
                <label class="label">Status</label>
                <select v-model="form.status" class="input mt-1">
                    <option value="active">Active</option>
                    <option value="vip">VIP ⭐</option>
                    <option value="prospect">Prospect</option>
                    <option value="churned">Churned</option>
                    <option value="blacklisted">Blacklisted</option>
                </select>
            </div>
            <div>
                <label class="label">City</label>
                <input v-model="form.city" type="text" class="input mt-1" maxlength="120" />
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="label">State</label>
                    <input v-model="form.state" type="text" class="input mt-1 uppercase" maxlength="2" />
                </div>
                <div>
                    <label class="label">Postal</label>
                    <input v-model="form.postal_code" type="text" class="input mt-1" maxlength="20" />
                </div>
            </div>
        </fieldset>

        <div>
            <label class="label">Notes</label>
            <textarea v-model="form.notes" rows="3" class="input mt-1" placeholder="VIP since 2024; prefers Westgate properties; allergic to feather pillows…"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
            <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
                {{ submitting ? 'Saving…' : 'Create customer' }}
            </button>
        </div>
    </form>
</template>
