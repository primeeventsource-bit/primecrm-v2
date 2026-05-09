<script setup lang="ts">
import { ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';

const email = ref('');
const password = ref('');
const error = ref<string | null>(null);
const loading = ref(false);

async function submit(e: Event): Promise<void> {
    e.preventDefault();
    error.value = null;
    loading.value = true;

    try {
        // CSRF cookie for stateful Sanctum SPA — must precede the login POST.
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/api/auth/login', {
            email: email.value,
            password: password.value,
        });
        router.visit('/dashboard');
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } };
        error.value = e.response?.data?.message ?? 'Invalid credentials';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Head title="Sign in" />
    <div class="flex min-h-screen items-center justify-center bg-slate-100">
        <form class="panel w-full max-w-sm space-y-4 p-6" @submit="submit">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Prime CRM</h1>
                <p class="text-sm text-slate-500">Sign in to continue.</p>
            </div>

            <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ error }}
            </div>

            <div>
                <label class="label" for="email">Email</label>
                <input id="email" v-model="email" type="email" class="input mt-1" required autocomplete="email" />
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <input id="password" v-model="password" type="password" class="input mt-1" required autocomplete="current-password" />
            </div>

            <button type="submit" class="btn-primary w-full" :disabled="loading">
                {{ loading ? 'Signing in…' : 'Sign in' }}
            </button>
        </form>
    </div>
</template>
