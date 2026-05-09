<script setup lang="ts">
import { ref } from 'vue';
import axios from 'axios';

const emit = defineEmits<{ (e: 'created'): void; (e: 'cancel'): void }>();

const form = ref({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    role: 'closer' as string,
    phone: '',
    extension: '',
    timezone: 'America/New_York',
    is_panama_based: false,
});

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const payload: Record<string, unknown> = { ...form.value };
    for (const k of Object.keys(payload)) {
        if (payload[k] === '' || payload[k] === null) delete payload[k];
    }

    try {
        await axios.post('/api/agents', payload);
        emit('created');
    } catch (err: unknown) {
        const e = err as { response?: { data?: { errors?: Record<string, string[]>; error?: string; message?: string } } };
        errors.value = e.response?.data?.errors ?? {};
        if (!Object.keys(errors.value).length) {
            errors.value._global = [e.response?.data?.error ?? e.response?.data?.message ?? 'Failed to create agent'];
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

        <fieldset class="grid grid-cols-2 gap-3">
            <div>
                <label class="label">First name <span class="text-red-600">*</span></label>
                <input v-model="form.first_name" type="text" class="input mt-1" required />
                <p v-if="errors.first_name" class="mt-1 text-xs text-red-600">{{ errors.first_name[0] }}</p>
            </div>
            <div>
                <label class="label">Last name <span class="text-red-600">*</span></label>
                <input v-model="form.last_name" type="text" class="input mt-1" required />
                <p v-if="errors.last_name" class="mt-1 text-xs text-red-600">{{ errors.last_name[0] }}</p>
            </div>
            <div>
                <label class="label">Email <span class="text-red-600">*</span></label>
                <input v-model="form.email" type="email" class="input mt-1" required />
                <p v-if="errors.email" class="mt-1 text-xs text-red-600">{{ errors.email[0] }}</p>
            </div>
            <div>
                <label class="label">Password <span class="text-red-600">*</span></label>
                <input v-model="form.password" type="password" class="input mt-1" required minlength="8" />
                <p v-if="errors.password" class="mt-1 text-xs text-red-600">{{ errors.password[0] }}</p>
            </div>
            <div>
                <label class="label">Role <span class="text-red-600">*</span></label>
                <select v-model="form.role" class="input mt-1" required>
                    <option value="closer">Closer</option>
                    <option value="fronter">Fronter</option>
                    <option value="agent">Agent</option>
                    <option value="supervisor">Supervisor</option>
                    <option value="manager">Manager</option>
                    <option value="qa">QA</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div>
                <label class="label">Location</label>
                <select v-model="form.is_panama_based" class="input mt-1">
                    <option :value="false">United States</option>
                    <option :value="true">Panama</option>
                </select>
            </div>
            <div>
                <label class="label">Phone</label>
                <input v-model="form.phone" type="tel" class="input mt-1" />
            </div>
            <div>
                <label class="label">Extension</label>
                <input v-model="form.extension" type="text" class="input mt-1" maxlength="16" />
            </div>
        </fieldset>

        <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
            <button type="button" class="btn-ghost text-slate-600 hover:bg-slate-100" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="submitting">
                {{ submitting ? 'Saving…' : 'Create agent' }}
            </button>
        </div>
    </form>
</template>
