<?php

namespace App\Domain\Gtm\Services;

use App\Domain\Gtm\DTOs\GtmProfileVersionReference;
use App\Domain\Gtm\Support\CanonicalGtmProfilePayload;
use App\Models\GtmProfile;
use App\Models\GtmProfileVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ResolveCurrentGtmProfileVersion
{
    public function resolve(GtmProfile $profile): GtmProfileVersionReference
    {
        $version = GtmProfileVersion::query()
            ->whereKey($profile->current_version_id)
            ->where('gtm_profile_id', $profile->getKey())
            ->first();

        if ($version === null) {
            throw (new ModelNotFoundException)->setModel(
                GtmProfileVersion::class,
                [$profile->current_version_id],
            );
        }

        return new GtmProfileVersionReference(
            profileId: (string) $version->gtm_profile_id,
            versionId: (string) $version->getKey(),
            version: (int) $version->version,
            schemaVersion: (int) $version->schema_version,
            contentHash: (string) $version->content_hash,
            payload: CanonicalGtmProfilePayload::visible((array) $version->payload),
        );
    }

    public function handle(GtmProfile $profile): GtmProfileVersionReference
    {
        return $this->resolve($profile);
    }

    public function __invoke(GtmProfile $profile): GtmProfileVersionReference
    {
        return $this->resolve($profile);
    }
}
