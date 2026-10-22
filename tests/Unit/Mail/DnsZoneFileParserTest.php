<?php

use App\Domain\Mail\Support\DnsZoneFileParser;

it('parses every record from a flattened Stalwart zone file', function () {
    $zone = 'v1-ed25519-20260902._domainkey.example.com. IN TXT "v=DKIM1; k=ed25519; p=ED-KEY" '
        .'v1-rsa-20260902._domainkey.example.com. IN TXT ( "v=DKIM1; k=rsa; p=RSA-" "KEY" ) '
        .'example.com. IN TXT "v=spf1 mx -all" '
        .'example.com. IN MX 10 mail01.example.net. '
        .'_dmarc.example.com. IN TXT "v=DMARC1; p=reject" '
        .'_caldavs._tcp.example.com. IN SRV 0 1 443 mail01.example.net.';
    $parser = new DnsZoneFileParser;
    $records = $parser->records($zone);

    expect($records)->toHaveCount(6)
        ->and($records[0])->toMatchArray([
            'host' => 'v1-ed25519-20260902._domainkey.example.com.',
            'type' => 'TXT',
        ])
        ->and($parser->txtValue($records[0]['value']))->toBe('v=DKIM1; k=ed25519; p=ED-KEY')
        ->and($parser->txtValue($records[1]['value']))->toBe('v=DKIM1; k=rsa; p=RSA-KEY')
        ->and($records[3])->toBe([
            'host' => 'example.com.',
            'type' => 'MX',
            'value' => '10 mail01.example.net.',
        ]);
});
