<?php

namespace App\Mcp;

use App\Mcp\Prompts\CampaignReviewPrompt;
use App\Mcp\Prompts\TriageRepliesPrompt;
use App\Mcp\Resources\AccountIntelligenceResource;
use App\Mcp\Resources\AssetsResource;
use App\Mcp\Resources\CampaignOutcomesResource;
use App\Mcp\Resources\ConversionEventsResource;
use App\Mcp\Resources\MailboxHealthResource;
use App\Mcp\Resources\OfferReadinessResource;
use App\Mcp\Resources\ProspectSourcesResource;
use App\Mcp\Tools\ActivateConversionDefinition;
use App\Mcp\Tools\AddSequenceStep;
use App\Mcp\Tools\AddSuppression;
use App\Mcp\Tools\ArchiveConversionDefinition;
use App\Mcp\Tools\CancelAccountResearch;
use App\Mcp\Tools\ClassifyThread;
use App\Mcp\Tools\CompleteCampaign;
use App\Mcp\Tools\ComposeMessage;
use App\Mcp\Tools\ConfigureOffer;
use App\Mcp\Tools\CorrectConversionEvent;
use App\Mcp\Tools\CorrectResearchClaim;
use App\Mcp\Tools\CreateAccount;
use App\Mcp\Tools\CreateCampaign;
use App\Mcp\Tools\CreateConversionDefinition;
use App\Mcp\Tools\CreateConversionEvent;
use App\Mcp\Tools\CreatePerson;
use App\Mcp\Tools\CreateProspectSource;
use App\Mcp\Tools\EnrollPeople;
use App\Mcp\Tools\GetAccountIntelligence;
use App\Mcp\Tools\GetAccountResearchRun;
use App\Mcp\Tools\GetAsset;
use App\Mcp\Tools\GetCampaign;
use App\Mcp\Tools\GetCampaignLaunchReadiness;
use App\Mcp\Tools\GetConversionDefinition;
use App\Mcp\Tools\GetConversionEvent;
use App\Mcp\Tools\GetMailboxAuthCheck;
use App\Mcp\Tools\GetOffer;
use App\Mcp\Tools\GetOfferReadiness;
use App\Mcp\Tools\GetPerson;
use App\Mcp\Tools\GetProspectSource;
use App\Mcp\Tools\GetQualificationDecision;
use App\Mcp\Tools\GetResearchClaim;
use App\Mcp\Tools\GetSourceRun;
use App\Mcp\Tools\GetThread;
use App\Mcp\Tools\LaunchCampaign;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListAssets;
use App\Mcp\Tools\ListCampaignRecipients;
use App\Mcp\Tools\ListCampaigns;
use App\Mcp\Tools\ListConversionDefinitions;
use App\Mcp\Tools\ListConversionEvents;
use App\Mcp\Tools\ListMailboxes;
use App\Mcp\Tools\ListOffers;
use App\Mcp\Tools\ListPeople;
use App\Mcp\Tools\ListProspectSources;
use App\Mcp\Tools\ListSourceRunObservations;
use App\Mcp\Tools\ListSuppressions;
use App\Mcp\Tools\ListThreads;
use App\Mcp\Tools\MarkThreadDone;
use App\Mcp\Tools\PauseCampaign;
use App\Mcp\Tools\PreflightAccountResearch;
use App\Mcp\Tools\ProposeAssetVersion;
use App\Mcp\Tools\RemoveSuppression;
use App\Mcp\Tools\ReorderSequenceSteps;
use App\Mcp\Tools\ReplyToThread;
use App\Mcp\Tools\ResumeCampaign;
use App\Mcp\Tools\ReverseConversionEvent;
use App\Mcp\Tools\ReviewConversionEvent;
use App\Mcp\Tools\ReviewQualificationDecision;
use App\Mcp\Tools\SendTestStep;
use App\Mcp\Tools\StartAccountResearch;
use App\Mcp\Tools\StartProspectSourceRun;
use App\Mcp\Tools\UpdateCampaign;
use App\Mcp\Tools\UpdatePerson;
use App\Mcp\Tools\UpdateRecipient;
use App\Mcp\Tools\UpdateSequenceStep;
use App\Mcp\Tools\VersionConversionDefinition;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Outboss')]
#[Version('0.3.0')]
#[Instructions(<<<'MARKDOWN'
Outboss is a cold-email and go-to-market workspace. Use these tools to inspect and change campaigns, people, prospect sources, the unified inbox, mailboxes, and suppressions for the authenticated workspace only.

Safety rules:
- Tools whose descriptions start with "Sends real email:" send mail or start campaign sending. Confirm with the user before calling LaunchCampaign, ResumeCampaign, ReplyToThread, ComposeMessage, or SendTestStep.
- Check GetCampaignLaunchReadiness (or the campaign-review prompt) before launching. Do not launch when blockers remain.
- Stay inside the authenticated workspace. Never assume access to another workspace's records.
- Prefer read tools first. List tools default to 20 results (maximum 100); use page and per_page when more records exist.
- Prospect source observations are policy-filtered. Do not request or infer raw uploaded rows, provider payloads, credentials, or personal data omitted by a source policy.
- Use British English in user-facing summaries.
MARKDOWN)]
class OutbossServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListCampaigns::class,
        GetCampaign::class,
        GetCampaignLaunchReadiness::class,
        GetAccountIntelligence::class,
        PreflightAccountResearch::class,
        StartAccountResearch::class,
        GetAccountResearchRun::class,
        CancelAccountResearch::class,
        GetResearchClaim::class,
        CorrectResearchClaim::class,
        GetQualificationDecision::class,
        ReviewQualificationDecision::class,
        ListConversionDefinitions::class,
        GetConversionDefinition::class,
        CreateConversionDefinition::class,
        VersionConversionDefinition::class,
        ActivateConversionDefinition::class,
        ArchiveConversionDefinition::class,
        ListConversionEvents::class,
        GetConversionEvent::class,
        CreateConversionEvent::class,
        ReviewConversionEvent::class,
        CorrectConversionEvent::class,
        ReverseConversionEvent::class,
        ListOffers::class,
        GetOffer::class,
        GetOfferReadiness::class,
        ConfigureOffer::class,
        ListAssets::class,
        GetAsset::class,
        ProposeAssetVersion::class,
        ListCampaignRecipients::class,
        ListPeople::class,
        GetPerson::class,
        ListAccounts::class,
        ListThreads::class,
        GetThread::class,
        ListMailboxes::class,
        GetMailboxAuthCheck::class,
        ListSuppressions::class,
        CreateCampaign::class,
        UpdateCampaign::class,
        AddSequenceStep::class,
        UpdateSequenceStep::class,
        ReorderSequenceSteps::class,
        EnrollPeople::class,
        PauseCampaign::class,
        CompleteCampaign::class,
        UpdateRecipient::class,
        CreatePerson::class,
        UpdatePerson::class,
        CreateAccount::class,
        ListProspectSources::class,
        GetProspectSource::class,
        CreateProspectSource::class,
        StartProspectSourceRun::class,
        GetSourceRun::class,
        ListSourceRunObservations::class,
        AddSuppression::class,
        RemoveSuppression::class,
        LaunchCampaign::class,
        ResumeCampaign::class,
        ReplyToThread::class,
        ComposeMessage::class,
        SendTestStep::class,
        MarkThreadDone::class,
        ClassifyThread::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        CampaignOutcomesResource::class,
        MailboxHealthResource::class,
        ProspectSourcesResource::class,
        AccountIntelligenceResource::class,
        ConversionEventsResource::class,
        OfferReadinessResource::class,
        AssetsResource::class,
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        TriageRepliesPrompt::class,
        CampaignReviewPrompt::class,
    ];
}
