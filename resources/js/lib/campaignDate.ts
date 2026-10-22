/** Display dates without changing the UTC instants used by approval. */
export function campaignDate(
    value: string | null | undefined,
    timeZone = 'UTC',
): string {
    if (!value) {
        return 'Not set';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return (
        new Intl.DateTimeFormat('en-GB', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone,
        }).format(date) + ` (${timeZone})`
    );
}
