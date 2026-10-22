<?php

use App\Domain\Mail\DTOs\ManagedMailboxRequest;
use App\Domain\Mail\Exceptions\StalwartManagementException;
use App\Domain\Mail\Providers\StalwartManagedMailboxProvisioner;
use App\Domain\Mail\Stalwart\StalwartManagementClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function stalwartMethodResponse(string $method, array $arguments): array
{
    return ['methodResponses' => [[$method, $arguments, 'outboss-1']]];
}

function stalwartManagementClient(): StalwartManagementClient
{
    return new StalwartManagementClient(instance: [
        'base_url' => 'https://mx-api.example.test',
        'management_api_url' => 'https://mx-api.example.test/jmap/',
        'admin_api_key' => 'admin-secret-that-must-not-leak',
        'verify_tls' => true,
        'timeout_seconds' => 15,
    ]);
}

it('creates a domain through the current Stalwart management JMAP contract', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['apiUrl' => 'https://mx-api.example.test/jmap/']);
        }

        $method = $request->data()['methodCalls'][0][0] ?? null;

        return match ($method) {
            'x:Domain/query' => Http::response(stalwartMethodResponse($method, ['ids' => []])),
            'x:Domain/set' => Http::response(stalwartMethodResponse($method, [
                'created' => ['outboss-domain' => ['id' => 'domain-1']],
            ])),
            'x:Domain/get' => Http::response(stalwartMethodResponse($method, [
                'list' => [['id' => 'domain-1', 'name' => 'outreach.example.com']],
            ])),
            default => Http::response([], 500),
        };
    });

    $domain = stalwartManagementClient()->createDomain('Outreach.Example.com', 'request-123');

    expect($domain)->toMatchArray(['id' => 'domain-1', 'name' => 'outreach.example.com']);
    Http::assertSent(function (Request $request): bool {
        if (($request->data()['methodCalls'][0][0] ?? null) !== 'x:Domain/set') {
            return true;
        }

        $created = $request->data()['methodCalls'][0][1]['create']['outboss-domain'];

        return $request->url() === 'https://mx-api.example.test/jmap/'
            && $request->hasHeader('Authorization', 'Bearer admin-secret-that-must-not-leak')
            && $request->hasHeader('X-Correlation-ID', 'request-123')
            && $created['name'] === 'outreach.example.com'
            && $created['dkimManagement']['@type'] === 'Automatic'
            && $created['dnsManagement']['@type'] === 'Manual';
    });
});

it('returns an exact existing domain without submitting a duplicate create', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['apiUrl' => 'https://mx-api.example.test/jmap/']);
        }

        $method = $request->data()['methodCalls'][0][0];

        return match ($method) {
            'x:Domain/query' => Http::response(stalwartMethodResponse($method, ['ids' => ['domain-1']])),
            'x:Domain/get' => Http::response(stalwartMethodResponse($method, [
                'list' => [['id' => 'domain-1', 'name' => 'example.com']],
            ])),
            default => Http::response([], 500),
        };
    });

    expect(stalwartManagementClient()->createDomain('example.com')['id'])->toBe('domain-1');
    Http::assertNotSent(fn (Request $request) => ($request->data()['methodCalls'][0][0] ?? null) === 'x:Domain/set');
});

it('creates an account with a generated password and never exposes it in errors', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['apiUrl' => 'https://mx-api.example.test/jmap/']);
        }

        $method = $request->data()['methodCalls'][0][0] ?? null;

        return match ($method) {
            'x:Account/query' => Http::response(stalwartMethodResponse($method, ['ids' => []])),
            'x:Account/set' => Http::response(stalwartMethodResponse($method, [
                'created' => ['outboss-account' => ['id' => 'account-1']],
            ])),
            'x:Account/get' => Http::response(stalwartMethodResponse($method, [
                'list' => [[
                    'id' => 'account-1',
                    '@type' => 'User',
                    'name' => 'alice',
                    'domainId' => 'domain-1',
                    'emailAddress' => 'alice@example.com',
                ]],
            ])),
            default => Http::response([], 500),
        };
    });

    $account = stalwartManagementClient()->createAccount(
        'domain-1',
        'Alice',
        'generated-mailbox-secret',
        'Alice Example',
    );

    expect($account['emailAddress'])->toBe('alice@example.com');
    Http::assertSent(function (Request $request): bool {
        if (($request->data()['methodCalls'][0][0] ?? null) !== 'x:Account/set') {
            return true;
        }

        $created = $request->data()['methodCalls'][0][1]['create']['outboss-account'];

        $credentials = (array) $created['credentials'];

        return $created['name'] === 'alice'
            && $created['domainId'] === 'domain-1'
            && $credentials['0']['@type'] === 'Password'
            && $credentials['0']['secret'] === 'generated-mailbox-secret'
            && $created['roles']['@type'] === 'Custom'
            && (array) $created['roles']['roleIds'] === []
            && $created['permissions']['@type'] === 'Replace'
            && $created['permissions']['enabledPermissions'] === [
                'authenticate' => true,
                'authenticateWithAlias' => true,
                'emailSend' => true,
                'emailReceive' => true,
                'jmapMailboxGet' => true,
                'jmapIdentityGet' => true,
                'jmapEmailGet' => true,
                'jmapEmailQuery' => true,
                'jmapEmailQueryChanges' => true,
                'jmapEmailCreate' => true,
                'jmapEmailSubmissionCreate' => true,
            ];
    });
});

it('returns a mailbox credential only for the remote account create that consumed it', function () {
    config()->set('outboss.managed_mail.default_instance', 'internal');
    config()->set('outboss.managed_mail.instances.internal', [
        'base_url' => 'https://mx-api.example.test',
        'management_api_url' => 'https://mx-api.example.test/jmap/',
        'admin_api_key' => 'admin-secret-that-must-not-leak',
        'verify_tls' => true,
        'timeout_seconds' => 15,
    ]);

    Http::preventStrayRequests();
    $queries = 0;
    Http::fake(function (Request $request) use (&$queries) {
        if ($request->method() === 'GET') {
            return Http::response(['apiUrl' => 'https://mx-api.example.test/jmap/']);
        }

        $method = $request->data()['methodCalls'][0][0] ?? null;
        if ($method === 'x:Account/query') {
            $queries++;

            return Http::response(stalwartMethodResponse($method, [
                'ids' => $queries === 1 ? [] : ['account-1'],
            ]));
        }
        if ($method === 'x:Account/set') {
            return Http::response(stalwartMethodResponse($method, [
                'created' => ['outboss-account' => ['id' => 'account-1']],
            ]));
        }
        if ($method === 'x:Account/get') {
            return Http::response(stalwartMethodResponse($method, [
                'list' => [[
                    'id' => 'account-1',
                    '@type' => 'User',
                    'name' => 'alice',
                    'domainId' => 'domain-1',
                    'emailAddress' => 'alice@example.com',
                    'credentials' => ['masked-provider-value'],
                ]],
            ]));
        }

        return Http::response([], 500);
    });

    $request = new ManagedMailboxRequest(
        localPart: 'alice',
        domain: 'example.com',
        remoteDomainId: 'domain-1',
        password: 'one-time-generated-password',
    );
    $provisioner = new StalwartManagedMailboxProvisioner;
    $first = $provisioner->createMailbox($request);
    $second = $provisioner->createMailbox($request);

    expect($first->password)->toBe('one-time-generated-password')
        ->and($second->password)->toBeNull()
        ->and($first->metadata)->not->toHaveKey('credentials');
});

it('discovers and uses the authenticated management JMAP endpoint', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response([
                'apiUrl' => 'https://mx-api.example.test/jmap/',
            ]);
        }

        $method = $request->data()['methodCalls'][0][0] ?? null;

        return Http::response(stalwartMethodResponse((string) $method, [
            'list' => [['id' => 'domain-1', 'name' => 'example.com']],
        ]));
    });

    $client = new StalwartManagementClient(instance: [
        'base_url' => 'https://mx-api.example.test',
        'admin_api_key' => 'admin-secret-that-must-not-leak',
    ]);

    expect($client->inspectDomain('domain-1')['name'])->toBe('example.com');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://mx-api.example.test/.well-known/jmap');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://mx-api.example.test/jmap/');
});

it('keeps every DKIM key from a flattened managed domain zone', function () {
    config()->set('outboss.managed_mail.instances.internal', [
        'base_url' => 'https://mx-api.example.test',
        'management_api_url' => 'https://mx-api.example.test/jmap/',
        'admin_api_key' => 'admin-secret-that-must-not-leak',
        'verify_tls' => true,
    ]);
    $zone = 'v1-ed._domainkey.example.com. IN TXT "v=DKIM1; k=ed25519; p=ED-KEY" '
        .'v1-rsa._domainkey.example.com. IN TXT ( "v=DKIM1; k=rsa; p=RSA-" "KEY" ) '
        .'example.com. IN TXT "v=spf1 mx -all" '
        .'example.com. IN MX 10 mail01.example.test. '
        .'_dmarc.example.com. IN TXT "v=DMARC1; p=reject"';

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($zone) {
        if ($request->method() === 'GET') {
            return Http::response(['apiUrl' => 'https://mx-api.example.test/jmap/']);
        }

        $method = $request->data()['methodCalls'][0][0] ?? null;

        return Http::response(stalwartMethodResponse((string) $method, [
            'list' => [[
                'id' => 'domain-1',
                'name' => 'example.com',
                'dnsZoneFile' => $zone,
            ]],
        ]));
    });

    $domain = (new StalwartManagedMailboxProvisioner('internal'))->inspectDomain('domain-1');

    expect($domain->dnsRecords['dkim'])->toBe([
        [
            'host' => 'v1-ed._domainkey.example.com',
            'value' => 'v=DKIM1; k=ed25519; p=ED-KEY',
        ],
        [
            'host' => 'v1-rsa._domainkey.example.com',
            'value' => 'v=DKIM1; k=rsa; p=RSA-KEY',
        ],
    ])->and($domain->dnsRecords['mx'])->toBe([
        'host' => 'example.com',
        'value' => 'mail01.example.test',
    ]);
});

it('recovers an ambiguous domain create response by querying the unique name', function () {
    Http::preventStrayRequests();
    $queries = 0;
    Http::fake(function (Request $request) use (&$queries) {
        if ($request->method() === 'GET') {
            return Http::response(['apiUrl' => 'https://mx-api.example.test/jmap/']);
        }

        $method = $request->data()['methodCalls'][0][0] ?? null;

        if ($method === 'x:Domain/query') {
            $queries++;

            return Http::response(stalwartMethodResponse($method, [
                'ids' => $queries === 1 ? [] : ['domain-1'],
            ]));
        }
        if ($method === 'x:Domain/set') {
            return Http::response(['title' => 'response lost after commit'], 500);
        }
        if ($method === 'x:Domain/get') {
            return Http::response(stalwartMethodResponse($method, [
                'list' => [['id' => 'domain-1', 'name' => 'example.com']],
            ]));
        }

        return Http::response([], 500);
    });

    expect(stalwartManagementClient()->createDomain('example.com')['id'])->toBe('domain-1')
        ->and($queries)->toBe(2);
    Http::assertSentCount(5);
});

it('maps authentication failures to a safe provider-neutral exception', function () {
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response([
        'detail' => 'admin-secret-that-must-not-leak',
    ], 401));

    try {
        stalwartManagementClient()->inspectDomain('domain-1');
        $this->fail('Expected a management exception.');
    } catch (StalwartManagementException $exception) {
        expect($exception->errorCode)->toBe('provider_authentication_failed')
            ->and($exception->getMessage())->not->toContain('admin-secret-that-must-not-leak');
    }
});

it('treats a confirmed missing account as a successful delete retry', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['apiUrl' => 'https://mx-api.example.test/jmap/']);
        }

        $method = $request->data()['methodCalls'][0][0] ?? null;

        return match ($method) {
            'x:Account/set' => Http::response(['title' => 'response lost after commit'], 500),
            'x:Account/get' => Http::response(stalwartMethodResponse($method, ['list' => []])),
            default => Http::response([], 500),
        };
    });

    stalwartManagementClient()->deleteAccount('account-1', 'delete-retry');

    Http::assertSent(fn (Request $request): bool => ($request->data()['methodCalls'][0][0] ?? null) === 'x:Account/set');
    Http::assertSent(fn (Request $request): bool => ($request->data()['methodCalls'][0][0] ?? null) === 'x:Account/get');
});

it('does not mask a delete provider error while the account still exists', function () {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['apiUrl' => 'https://mx-api.example.test/jmap/']);
        }

        $method = $request->data()['methodCalls'][0][0] ?? null;

        return match ($method) {
            'x:Account/set' => Http::response(stalwartMethodResponse($method, [
                'notDestroyed' => ['account-1' => ['type' => 'forbidden']],
            ])),
            'x:Account/get' => Http::response(stalwartMethodResponse($method, [
                'list' => [['id' => 'account-1', 'name' => 'sales']],
            ])),
            default => Http::response([], 500),
        };
    });

    try {
        stalwartManagementClient()->deleteAccount('account-1');
        $this->fail('Expected the original provider error.');
    } catch (StalwartManagementException $exception) {
        expect($exception->errorCode)->toBe('provider_forbidden');
    }
});

it('refuses missing configuration and insecure production TLS', function () {
    $missing = new StalwartManagementClient(instance: []);

    expect(fn () => $missing->inspectDomain('domain-1'))
        ->toThrow(StalwartManagementException::class, 'Managed mail provisioning is not configured.');

    app()->detectEnvironment(fn () => 'production');
    $insecure = new StalwartManagementClient(instance: [
        'base_url' => 'https://mx-api.example.test',
        'admin_api_key' => 'secret',
        'verify_tls' => false,
    ]);

    expect(fn () => $insecure->inspectDomain('domain-1'))
        ->toThrow(StalwartManagementException::class, 'Managed mail TLS verification cannot be disabled in production.');

    $plaintext = new StalwartManagementClient(instance: [
        'base_url' => 'http://mx-api.example.test',
        'admin_api_key' => 'secret',
        'verify_tls' => true,
    ]);

    expect(fn () => $plaintext->inspectDomain('domain-1'))
        ->toThrow(StalwartManagementException::class, 'Managed mail provisioning requires HTTPS in production.');
});
