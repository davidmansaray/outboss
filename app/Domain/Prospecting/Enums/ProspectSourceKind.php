<?php

namespace App\Domain\Prospecting\Enums;

enum ProspectSourceKind: string
{
    case ExistingAccounts = 'existing_accounts';
    case CsvImport = 'csv_import';
    case ApiImport = 'api_import';
    case Manual = 'manual';

    /** Provider kinds are represented in code before a provider is enabled. */
    case LocalBusiness = 'local_business';
    case Provider = 'provider';

    public function isEnabledInS1(): bool
    {
        return match ($this) {
            self::ExistingAccounts, self::CsvImport, self::ApiImport, self::Manual => true,
            self::LocalBusiness, self::Provider => false,
        };
    }
}
