export function initials(value: string): string {
    return value
        .trim()
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toLocaleUpperCase();
}

export function updatedLabel(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Recently';
    }

    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const recordDay = new Date(
        date.getFullYear(),
        date.getMonth(),
        date.getDate(),
    );
    const days = Math.round(
        (today.getTime() - recordDay.getTime()) / 86_400_000,
    );

    if (days === 0) {
        return new Intl.DateTimeFormat(undefined, {
            hour: 'numeric',
            minute: '2-digit',
        }).format(date);
    }

    if (days === 1) {
        return 'Yesterday';
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
    }).format(date);
}

export function statusTone(
    value: string,
): 'green' | 'blue' | 'amber' | 'red' | 'neutral' {
    if (['Interested', 'Eligible', 'Ready'].includes(value)) {
        return 'green';
    }

    if (value === 'Replied') {
        return 'blue';
    }

    if (['Not now', 'Needs review'].includes(value)) {
        return 'amber';
    }

    if (['Suppressed', 'Blocked'].includes(value)) {
        return 'red';
    }

    return 'neutral';
}

export function accountSourceLabel(source: string): string {
    if (source === 'campaign_backfill') {
        return 'Created from campaign history';
    }

    return 'Added manually';
}

export function accountWebsiteHref(website: string | null): string | null {
    if (!website) {
        return null;
    }

    return /^https?:\/\//i.test(website) ? website : `https://${website}`;
}
