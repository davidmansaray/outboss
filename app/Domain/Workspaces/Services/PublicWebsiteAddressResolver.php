<?php

namespace App\Domain\Workspaces\Services;

use App\Domain\Gtm\Support\PublicWebsiteUrl;

class PublicWebsiteAddressResolver
{
    /** @return list<string> */
    public function resolve(string $url): array
    {
        return PublicWebsiteUrl::publicAddresses($url, fresh: true);
    }
}
