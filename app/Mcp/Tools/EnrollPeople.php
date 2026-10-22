<?php

namespace App\Mcp\Tools;

use App\Domain\Api\TokenScope;
use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\DTOs\EnrollmentResult;
use App\Domain\Campaigns\DTOs\EnrollmentSkip;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Services\EnrollPeopleInCampaign;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Models\Campaign;
use App\Models\Person;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Description('Enrols people in a campaign by person ids (maximum 500). Skips unknown, already enrolled, or suppressed addresses. Does not send email; sending starts only after LaunchCampaign or ResumeCampaign.')]
class EnrollPeople extends OutbossTool
{
    public function __construct(private EnrollPeopleInCampaign $enroll) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()
                ->required()
                ->description('The campaign to enrol people into.'),
            'person_ids' => $schema->array()
                ->items($schema->integer())
                ->min(1)
                ->max(500)
                ->unique()
                ->required()
                ->description('Workspace person ids to enrol. Maximum 500 per call.'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->denyUnlessScoped($request, TokenScope::CampaignsWrite->value)) {
            return $denied;
        }

        $data = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'person_ids' => ['required', 'array', 'min:1', 'max:500'],
            'person_ids.*' => ['integer', 'distinct'],
        ]);
        $campaign = $this->authorised($request, Campaign::class, (int) $data['campaign_id'], 'update');
        $ids = array_values(array_unique(array_map(intval(...), $data['person_ids'])));

        try {
            $result = DB::transaction(function () use ($campaign, $ids): EnrollmentResult {
                $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                $workspaceId = (int) $locked->getAttribute('workspace_id');
                $people = Person::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $ids)
                    ->with('account:id,name')
                    ->get()
                    ->keyBy('id');

                $candidates = [];
                $skipped = [];

                foreach ($ids as $id) {
                    $person = $people->get($id);

                    if (! $person instanceof Person) {
                        $skipped[] = new EnrollmentSkip($id, 'Unknown', 'Not found');

                        continue;
                    }

                    $candidates[] = new EnrollmentCandidate(
                        personId: $person->id,
                        accountId: $person->account_id !== null ? (int) $person->account_id : null,
                        emailAddress: $person->email_address,
                        emailNormalized: $person->email_normalized,
                        fullName: $person->full_name,
                        firstName: $person->first_name ?: EmailNormalizer::firstName($person->full_name),
                        company: $person->account?->name,
                    );
                }

                $enrollment = $this->enroll->handle($locked, $candidates);

                return new EnrollmentResult(
                    $enrollment->enrolled,
                    [...$skipped, ...$enrollment->skipped],
                );
            });
        } catch (GovernedCampaignInputMutation $exception) {
            return $this->governedCampaignError($exception);
        } catch (HttpException $exception) {
            return Response::error($exception->getMessage());
        }

        $payload = $result->toArray();
        $campaign->refresh();

        if (
            $result->enrolled > 0
            && (
                $campaign->getAttribute('status') === CampaignStatus::Completed
                || $campaign->getAttribute('status') === CampaignStatus::Completed->value
            )
        ) {
            $payload['notice'] = 'Campaign is completed — resume it to start sending to newly added people.';
        }

        return $this->json($payload);
    }
}
