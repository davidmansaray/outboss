<?php

namespace App\Domain\Mail\Contracts;

use App\Domain\Mail\DTOs\ManagedDomainRequest;
use App\Domain\Mail\DTOs\ManagedMailboxRequest;
use App\Domain\Mail\DTOs\ProvisionedDomain;
use App\Domain\Mail\DTOs\ProvisionedMailbox;
use App\Domain\Mail\DTOs\RotatedCredential;
use App\Models\ManagedMailboxAccount;
use App\Models\ManagedMailDomain;

/** Operator-controlled provisioning operations for managed mail backends. */
interface ManagedMailboxProvisioner
{
    public function createDomain(ManagedDomainRequest $request): ProvisionedDomain;

    /**
     * Inspect a remote domain on the instance that owns it. The optional key
     * keeps old callers compatible while preventing verification from
     * silently falling back to the global/default instance.
     */
    public function inspectDomain(string $remoteDomainId, ?string $managedInstanceKey = null): ProvisionedDomain;

    public function createMailbox(ManagedMailboxRequest $request): ProvisionedMailbox;

    public function rotateMailboxCredential(ManagedMailboxAccount $account): RotatedCredential;

    public function suspendMailbox(ManagedMailboxAccount $account): void;

    public function deleteMailbox(ManagedMailboxAccount $account): void;

    public function deleteDomain(ManagedMailDomain $domain): void;
}
