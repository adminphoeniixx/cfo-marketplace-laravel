<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    store: {
        name: string;
        email: string | null;
        phone: string | null;
        commission: number;
    };
    sellers_count: number;
}>();

const form = useForm({
    store_name: '',
    name: '',
    email: '',
    phone: '',
    city: '',
    state: '',
    gst_number: '',
    password: '',
    password_confirmation: '',
});

const submit = () =>
    form.post('/sell', {
        onError: () => form.reset('password', 'password_confirmation'),
    });
</script>

<template>
    <Head :title="`Sell on ${store.name}`" />

    <div
        class="min-h-screen bg-[#f7f7f7] text-[#303030] dark:bg-[#1a1a1a] dark:text-[#e3e3e3]"
    >
        <div class="mx-auto grid max-w-5xl gap-8 px-5 py-10 lg:grid-cols-2 lg:py-16">
            <div class="lg:pt-6">
                <p
                    class="text-xs font-medium tracking-wide text-[#8a8a8a] uppercase"
                >
                    {{ store.name }} for sellers
                </p>
                <h1 class="mt-2 text-3xl font-semibold sm:text-4xl">
                    Put your shop in front of the whole country
                </h1>
                <p class="mt-3 text-[15px] leading-relaxed text-[#616161] dark:text-[#b5b5b5]">
                    List what you make, price it yourself, and let us handle the
                    checkout, the payment and the paperwork. You keep your own
                    prices and your own name on every order.
                </p>

                <dl class="mt-7 space-y-4">
                    <div>
                        <dt class="text-sm font-medium">Commission</dt>
                        <dd class="text-sm text-[#616161] dark:text-[#b5b5b5]">
                            {{ store.commission }}% of the sale — nothing to pay
                            up front, and nothing to pay on a month you sell
                            nothing.
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium">You are paid out</dt>
                        <dd class="text-sm text-[#616161] dark:text-[#b5b5b5]">
                            Per order, once it is delivered and past its return
                            window. Every payout shows the orders behind it.
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium">Run it from anywhere</dt>
                        <dd class="text-sm text-[#616161] dark:text-[#b5b5b5]">
                            A seller panel in the browser and an app on your
                            phone — the same catalogue, the same orders.
                        </dd>
                    </div>
                    <div v-if="sellers_count > 0">
                        <dt class="text-sm font-medium">You would be in company</dt>
                        <dd class="text-sm text-[#616161] dark:text-[#b5b5b5]">
                            {{ sellers_count }} store{{ sellers_count === 1 ? '' : 's' }}
                            already trading here.
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="store.email || store.phone"
                    class="mt-7 text-sm text-[#8a8a8a]"
                >
                    Questions before you apply?
                    <template v-if="store.email">
                        <a class="underline" :href="`mailto:${store.email}`">{{ store.email }}</a>
                    </template>
                    <template v-if="store.email && store.phone"> · </template>
                    <template v-if="store.phone">{{ store.phone }}</template>
                </p>
            </div>

            <div
                class="rounded-xl border border-[#e3e3e3] bg-white p-5 shadow-sm sm:p-6 dark:border-[#2a2a2a] dark:bg-[#232323]"
            >
                <h2 class="text-lg font-semibold">Apply to sell</h2>
                <p class="mt-1 text-sm text-[#8a8a8a]">
                    Applications are read by a person. You will hear back once
                    your store has been looked at.
                </p>

                <form class="mt-5 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="store_name">
                            Store name
                        </label>
                        <input
                            id="store_name"
                            v-model="form.store_name"
                            type="text"
                            required
                            autofocus
                            placeholder="Meera Textiles"
                            class="w-full rounded-lg border border-[#d0d0d0] bg-white px-3 py-2 text-sm outline-none focus:border-[#303030] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                        />
                        <p v-if="form.errors.store_name" class="mt-1 text-xs text-red-600">
                            {{ form.errors.store_name }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="name">
                                Your name
                            </label>
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                required
                                autocomplete="name"
                                class="w-full rounded-lg border border-[#d0d0d0] bg-white px-3 py-2 text-sm outline-none focus:border-[#303030] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">
                                {{ form.errors.name }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="phone">
                                Phone
                            </label>
                            <input
                                id="phone"
                                v-model="form.phone"
                                type="tel"
                                autocomplete="tel"
                                placeholder="9876543210"
                                class="w-full rounded-lg border border-[#d0d0d0] bg-white px-3 py-2 text-sm outline-none focus:border-[#303030] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                            />
                            <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">
                                {{ form.errors.phone }}
                            </p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="email">
                            Email address
                        </label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="email"
                            class="w-full rounded-lg border border-[#d0d0d0] bg-white px-3 py-2 text-sm outline-none focus:border-[#303030] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                        />
                        <p class="mt-1 text-xs text-[#8a8a8a]">
                            This is also your sign-in.
                        </p>
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="city">City</label>
                            <input
                                id="city"
                                v-model="form.city"
                                type="text"
                                placeholder="Chennai"
                                class="w-full rounded-lg border border-[#d0d0d0] bg-white px-3 py-2 text-sm outline-none focus:border-[#303030] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="state">State</label>
                            <input
                                id="state"
                                v-model="form.state"
                                type="text"
                                placeholder="Tamil Nadu"
                                class="w-full rounded-lg border border-[#d0d0d0] bg-white px-3 py-2 text-sm outline-none focus:border-[#303030] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="gst_number">
                            GSTIN
                        </label>
                        <input
                            id="gst_number"
                            v-model="form.gst_number"
                            type="text"
                            placeholder="Optional — you can add it later"
                            class="w-full rounded-lg border border-[#d0d0d0] bg-white px-3 py-2 text-sm outline-none focus:border-[#303030] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                        />
                        <p v-if="form.errors.gst_number" class="mt-1 text-xs text-red-600">
                            {{ form.errors.gst_number }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="password">
                                Password
                            </label>
                            <input
                                id="password"
                                v-model="form.password"
                                type="password"
                                required
                                autocomplete="new-password"
                                class="w-full rounded-lg border border-[#d0d0d0] bg-white px-3 py-2 text-sm outline-none focus:border-[#303030] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                            />
                            <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">
                                {{ form.errors.password }}
                            </p>
                        </div>
                        <div>
                            <label
                                class="mb-1 block text-sm font-medium"
                                for="password_confirmation"
                            >
                                Confirm password
                            </label>
                            <input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                required
                                autocomplete="new-password"
                                class="w-full rounded-lg border border-[#d0d0d0] bg-white px-3 py-2 text-sm outline-none focus:border-[#303030] dark:border-[#3a3a3a] dark:bg-[#1a1a1a]"
                            />
                        </div>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-lg bg-[#303030] px-4 py-2.5 text-sm font-medium text-white disabled:opacity-60 dark:bg-[#e3e3e3] dark:text-[#1a1a1a]"
                    >
                        {{ form.processing ? 'Sending…' : 'Apply to sell' }}
                    </button>

                    <p class="text-center text-xs text-[#8a8a8a]">
                        Already applied?
                        <a class="underline" href="/login">Sign in</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
</template>
