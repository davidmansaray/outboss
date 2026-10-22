# OAuth setup

Outboss V1 connects Gmail, Microsoft, and Zoho through server-side OAuth. Create one test application per provider before live smoke tests.

## Shared rules

- Callback URLs must match the environment exactly. Do not use wildcards.
- Store client IDs and secrets in `.env`. Never commit them.
- Tokens are encrypted with `APP_KEY`. Losing that key orphans every stored grant.

## Google / Gmail

1. Create a Google Cloud project and enable the Gmail API.
2. Configure an OAuth consent screen. `gmail.readonly` is a restricted scope; plan verification before production.
3. Create a web client and set `GOOGLE_REDIRECT_URI` to `{APP_URL}/oauth/gmail/callback`.
4. Required scopes: `openid email profile`, `gmail.readonly`, `gmail.send`.
5. Request offline access. Use `prompt=consent` only when a refresh token is missing or the user is reconnecting.

```text
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://app.example.com/oauth/gmail/callback
```

## Microsoft Graph

1. Register an app in Microsoft Entra that supports organisational and personal accounts (`common`).
2. Add a web redirect URI of `{APP_URL}/oauth/microsoft/callback`.
3. Delegated scopes: `openid profile email offline_access User.Read Mail.ReadWrite Mail.Send`.
4. Every Graph message request sends `Prefer: IdType="ImmutableId"`.

```text
MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
MICROSOFT_REDIRECT_URI=https://app.example.com/oauth/microsoft/callback
```

## Zoho Mail

1. Create a **Server-based Application** in the [Zoho API Console](https://api-console.zoho.com/). One client can serve every datacenter.
2. Redirect URI: `{APP_URL}/oauth/zoho/callback` (exact match; try `http://localhost` first locally, then a tunnel if Zoho rejects it).
3. In the client's **Settings**, enable every DC you expect users from (US plus at least EU, IN, AU, JP, CA, SA, AE, CN) and turn on **"Use the same OAuth credentials for all data centers"**. If that toggle is off, Zoho issues a distinct secret per DC and our single `ZOHO_CLIENT_SECRET` will fail for non-US users.
4. Scopes: `ZohoMail.accounts.READ`, `ZohoMail.messages.READ`, `ZohoMail.messages.CREATE`, `ZohoMail.folders.READ`.
5. Auth requests always send `access_type=offline` and `prompt=consent` so every connect/reconnect mints a refresh token.
6. The callback's `location` and `accounts-server` query params choose the regional token and Mail hosts. Region hosts come from the allowlist in `config/outboss.php`. Do not add free-form host environment variables.
7. Zoho Mail REST API needs a paid/org plan. Confirm the test account can call `mail.zoho.*/api`.

```text
ZOHO_CLIENT_ID=
ZOHO_CLIENT_SECRET=
ZOHO_REDIRECT_URI=https://app.example.com/oauth/zoho/callback
```

## Local development

The test suite uses `FakeMailProvider` for every provider. Live connect/sync/send still needs the credentials above and one real mailbox per provider.
