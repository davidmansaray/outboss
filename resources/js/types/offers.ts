export type OfferStatus = 'draft' | 'active' | 'paused' | 'archived';

export type OfferVersion = {
    uuid: string;
    version: number;
    content_hash?: string;
    origin?: string;
    status_snapshot: OfferStatus;
    created_at?: string;
    actor?: { type?: string; name?: string | null } | null;
};

export type Offer = {
    uuid: string;
    name: string;
    promise: string;
    target_audience: string;
    desired_outcome: string;
    call_to_action: string;
    notes?: string;
    status: OfferStatus;
    lock_version: number;
    current_version_id?: string | null;
    current_version?: OfferVersion | null;
    version_count: number;
    created_at?: string;
    updated_at?: string;
};

export type OfferFilter = 'all' | OfferStatus;

export type OfferPagination = {
    next_cursor: string | null;
    has_more: boolean;
    per_page: number;
};

export type OfferCounts = Record<OfferFilter, number>;

export const OFFER_STATUSES: Array<{
    id: OfferFilter;
    label: string;
    description: string;
}> = [
    { id: 'all', label: 'All offers', description: 'Every workspace offer.' },
    { id: 'draft', label: 'Draft', description: 'Still being shaped.' },
    {
        id: 'active',
        label: 'Active',
        description: 'Available for future runs.',
    },
    {
        id: 'paused',
        label: 'Paused',
        description: 'Temporarily not starting runs.',
    },
    { id: 'archived', label: 'Archived', description: 'Retained for history.' },
];

export function canTransitionOffer(
    from: OfferStatus,
    to: OfferStatus,
): boolean {
    return (
        (from === 'draft' && to === 'active') ||
        (from === 'active' && to === 'paused') ||
        (from === 'paused' && to === 'active') ||
        (from === 'paused' && to === 'archived') ||
        (from === 'archived' && to === 'paused')
    );
}
