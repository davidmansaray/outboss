<?php

namespace App\Domain\Mail\Services;

use App\Domain\Mail\Contracts\TlsEndpointChecker;
use Throwable;

/** Verify a public TLS endpoint with peer and hostname validation enabled. */
final class NativeTlsEndpointChecker implements TlsEndpointChecker
{
    /** @return 'pass'|'missing'|'unknown' */
    public function check(string $host, int $port, int $timeoutSeconds): string
    {
        $context = stream_context_create([
            'ssl' => [
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $errno = 0;
        $error = '';
        $target = str_contains($host, ':') ? '['.$host.']' : $host;

        try {
            $socket = @stream_socket_client(
                'tls://'.$target.':'.$port,
                $errno,
                $error,
                max(1, min(10, $timeoutSeconds)),
                STREAM_CLIENT_CONNECT,
                $context,
            );
        } catch (Throwable) {
            $socket = false;
        }

        if (! is_resource($socket)) {
            // A refused/unreachable endpoint is not proof that its
            // certificate is invalid; keep it unknown for a retry.
            return 'unknown';
        }

        fclose($socket);

        return 'pass';
    }
}
