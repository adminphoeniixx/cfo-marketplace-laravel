export const currency = (
    value: number | string | null | undefined,
    fraction = 2,
): string => {
    const amount = Number(value ?? 0);

    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        minimumFractionDigits: fraction,
        maximumFractionDigits: fraction,
    }).format(amount);
};

export const compactCurrency = (
    value: number | string | null | undefined,
): string => {
    const amount = Number(value ?? 0);

    if (amount >= 10000000) {
        return `₹${(amount / 10000000).toFixed(2)}Cr`;
    }

    if (amount >= 100000) {
        return `₹${(amount / 100000).toFixed(2)}L`;
    }

    if (amount >= 1000) {
        return `₹${(amount / 1000).toFixed(1)}k`;
    }

    return currency(amount, 0);
};

export const number = (value: number | string | null | undefined): string =>
    new Intl.NumberFormat('en-IN').format(Number(value ?? 0));

export const date = (
    value: string | null | undefined,
    withTime = false,
): string => {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    });
};

export const relativeDate = (value: string | null | undefined): string => {
    if (!value) {
        return '—';
    }

    const diff = Date.now() - new Date(value).getTime();
    const minutes = Math.round(diff / 60000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    if (minutes < 1440) {
        return `${Math.round(minutes / 60)}h ago`;
    }

    if (minutes < 43200) {
        return `${Math.round(minutes / 1440)}d ago`;
    }

    return date(value);
};

export const titleCase = (value: string | null | undefined): string =>
    (value ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

type Tone =
    | 'neutral'
    | 'info'
    | 'success'
    | 'warning'
    | 'critical'
    | 'attention'
    | 'new';

const toneMap: Record<string, Tone> = {
    active: 'success',
    approved: 'success',
    paid: 'success',
    completed: 'success',
    delivered: 'success',
    fulfilled: 'success',
    in_stock: 'success',
    processed: 'success',

    pending: 'attention',
    processing: 'info',
    on_hold: 'warning',
    shipped: 'info',
    partially_fulfilled: 'warning',
    partially_refunded: 'warning',
    low_stock: 'warning',
    draft: 'neutral',
    unfulfilled: 'warning',

    cancelled: 'critical',
    rejected: 'critical',
    failed: 'critical',
    refunded: 'critical',
    suspended: 'critical',
    blocked: 'critical',
    out_of_stock: 'critical',

    archived: 'neutral',
    inactive: 'neutral',
};

export const statusTone = (status: string | null | undefined): Tone =>
    toneMap[status ?? ''] ?? 'neutral';
