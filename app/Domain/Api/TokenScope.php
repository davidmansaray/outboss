<?php

namespace App\Domain\Api;

enum TokenScope: string
{
    case CampaignsRead = 'campaigns:read';
    case CampaignsWrite = 'campaigns:write';
    case CampaignsLaunch = 'campaigns:launch';
    case PeopleRead = 'people:read';
    case PeopleWrite = 'people:write';
    case SourcesRead = 'sources:read';
    case SourcesWrite = 'sources:write';
    case InboxRead = 'inbox:read';
    case InboxSend = 'inbox:send';
    case MailboxesRead = 'mailboxes:read';
    case MailboxesWrite = 'mailboxes:write';
    case SuppressionsRead = 'suppressions:read';
    case SuppressionsWrite = 'suppressions:write';
    case ExportsRead = 'exports:read';
    case IntelligenceRead = 'intelligence:read';
    case IntelligenceWrite = 'intelligence:write';
    case ConversionsRead = 'conversions:read';
    case ConversionsWrite = 'conversions:write';
    case ConversionConnectionsWrite = 'conversion-connections:write';
    case OffersRead = 'offers:read';
    case OffersWrite = 'offers:write';
    case OffersApprove = 'offers:approve';
    case ObjectivesRead = 'objectives:read';
    case ObjectivesWrite = 'objectives:write';
    case ObjectivesApprove = 'objectives:approve';

    public function label(): string
    {
        return match ($this) {
            self::CampaignsRead => 'Read campaigns',
            self::CampaignsWrite => 'Write campaigns',
            self::CampaignsLaunch => 'Launch campaigns',
            self::PeopleRead => 'Read people',
            self::PeopleWrite => 'Write people',
            self::SourcesRead => 'Read prospect sources',
            self::SourcesWrite => 'Manage prospect sources',
            self::InboxRead => 'Read inbox',
            self::InboxSend => 'Send from inbox',
            self::MailboxesRead => 'Read mailboxes',
            self::MailboxesWrite => 'Write mailboxes',
            self::SuppressionsRead => 'Read suppressions',
            self::SuppressionsWrite => 'Write suppressions',
            self::ExportsRead => 'Read exports',
            self::IntelligenceRead => 'Read Account intelligence',
            self::IntelligenceWrite => 'Manage Account intelligence',
            self::ConversionsRead => 'Read conversions',
            self::ConversionsWrite => 'Manage conversions',
            self::ConversionConnectionsWrite => 'Manage conversion connections',
            self::OffersRead => 'Read Offers and readiness',
            self::OffersWrite => 'Configure and review Offers',
            self::OffersApprove => 'Approve Offers',
            self::ObjectivesRead => 'Read Objectives',
            self::ObjectivesWrite => 'Manage Objective inputs',
            self::ObjectivesApprove => 'Approve Objective execution',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CampaignsRead => 'View campaigns, sequences, and recipients.',
            self::CampaignsWrite => 'Create and edit campaigns and sequences.',
            self::CampaignsLaunch => 'Launch and resume campaign sending.',
            self::PeopleRead => 'View people and accounts.',
            self::PeopleWrite => 'Create and edit people and accounts.',
            self::SourcesRead => 'View prospect sources, runs, and observations.',
            self::SourcesWrite => 'Create, update, archive, and import from prospect sources.',
            self::InboxRead => 'View threads and messages.',
            self::InboxSend => 'Reply to threads and compose messages.',
            self::MailboxesRead => 'View connected mailboxes.',
            self::MailboxesWrite => 'Connect, update, and disconnect mailboxes.',
            self::SuppressionsRead => 'View the suppression list.',
            self::SuppressionsWrite => 'Add or remove suppression entries.',
            self::ExportsRead => 'Download workspace exports.',
            self::IntelligenceRead => 'View Account intelligence, evidence, and qualification.',
            self::IntelligenceWrite => 'Start and review Account intelligence workflows.',
            self::ConversionsRead => 'View Conversion Definitions and Events.',
            self::ConversionsWrite => 'Create and review Account Conversion Events and Definitions.',
            self::ConversionConnectionsWrite => 'Create, rotate, and disable conversion source credentials.',
            self::OffersRead => 'View Offers and operational readiness.',
            self::OffersWrite => 'Configure and review operational Offers.',
            self::OffersApprove => 'Approve Offers for activation.',
            self::ObjectivesRead => 'View Segments, Objectives, plans, conversations, and attribution.',
            self::ObjectivesWrite => 'Create and version Objective inputs and controlled runs.',
            self::ObjectivesApprove => 'Approve governed Objective execution and attribution corrections.',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::CampaignsRead, self::CampaignsWrite, self::CampaignsLaunch => 'Campaigns',
            self::PeopleRead, self::PeopleWrite, self::SourcesRead, self::SourcesWrite => 'People',
            self::InboxRead, self::InboxSend => 'Inbox',
            self::MailboxesRead, self::MailboxesWrite => 'Mailboxes',
            self::SuppressionsRead, self::SuppressionsWrite => 'Suppressions',
            self::ExportsRead => 'Exports',
            self::IntelligenceRead, self::IntelligenceWrite => 'Intelligence',
            self::ConversionsRead, self::ConversionsWrite, self::ConversionConnectionsWrite => 'Conversions',
            self::OffersRead, self::OffersWrite, self::OffersApprove => 'Offers',
            self::ObjectivesRead, self::ObjectivesWrite, self::ObjectivesApprove => 'Objectives',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::values();
    }

    /**
     * @return list<string>
     */
    public static function readOnly(): array
    {
        return array_values(array_filter(
            self::values(),
            fn (string $value): bool => str_ends_with($value, ':read'),
        ));
    }

    /**
     * @return list<array{name: string, scopes: list<array{value: string, label: string, description: string}>}>
     */
    public static function groups(): array
    {
        $grouped = [];

        foreach (self::cases() as $scope) {
            $grouped[$scope->group()][] = [
                'value' => $scope->value,
                'label' => $scope->label(),
                'description' => $scope->description(),
            ];
        }

        $groups = [];

        foreach ($grouped as $name => $scopes) {
            $groups[] = [
                'name' => $name,
                'scopes' => $scopes,
            ];
        }

        return $groups;
    }
}
