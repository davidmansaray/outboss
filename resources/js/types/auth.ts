export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    workspace: {
        id: number;
        name: string;
        type: string;
        role: string | null;
        avatar_url: string | null;
    } | null;
    workspaces: Array<{
        id: number;
        name: string;
        type: string;
        role: string | null;
        avatar_url: string | null;
    }>;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
