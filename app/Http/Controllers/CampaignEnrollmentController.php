<?php

namespace App\Http\Controllers;

use App\Domain\Campaigns\DTOs\EnrollmentCandidate;
use App\Domain\Campaigns\DTOs\EnrollmentResult;
use App\Domain\Campaigns\DTOs\EnrollmentSkip;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Exceptions\GovernedCampaignInputMutation;
use App\Domain\Campaigns\Services\EnrollPeopleInCampaign;
use App\Domain\Mail\Exceptions\FrozenMailExperimentInputMutation;
use App\Domain\Mail\Support\EmailNormalizer;
use App\Http\Requests\StoreCampaignEnrollmentRequest;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Person;
use App\Models\SuppressionEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CampaignEnrollmentController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        $workspaceId = (int) $campaign->getAttribute('workspace_id');
        $search = trim((string) $request->query('query', ''));
        $onlyEligible = $request->boolean('only_eligible');

        $recipients = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('workspace_id', $workspaceId)
            ->get(['person_id', 'email_normalized']);
        $enrolledPersonIds = $recipients
            ->pluck('person_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $enrolledEmails = $recipients->pluck('email_normalized')->filter()->all();
        $suppressedEmails = SuppressionEntry::query()
            ->where('workspace_id', $workspaceId)
            ->pluck('email_normalized')
            ->filter()
            ->all();

        $query = Person::query()
            ->where('workspace_id', $workspaceId)
            ->with('account:id,name')
            ->orderBy('full_name')
            ->orderBy('email_normalized');

        if ($search !== '') {
            $like = $this->like($search);
            $emailNeedle = $this->like(EmailNormalizer::normalize($search));
            $query->where(function ($builder) use ($like, $emailNeedle): void {
                $builder->where('full_name', 'like', $like)
                    ->orWhere('email_normalized', 'like', $emailNeedle)
                    ->orWhereHas('account', fn ($account) => $account->where('name', 'like', $like));
            });
        }

        if ($onlyEligible) {
            if ($enrolledPersonIds !== []) {
                $query->whereNotIn('id', $enrolledPersonIds);
            }

            $blockedEmails = array_values(array_unique([...$enrolledEmails, ...$suppressedEmails]));

            if ($blockedEmails !== []) {
                $query->whereNotIn('email_normalized', $blockedEmails);
            }
        }

        $people = $query->paginate(25)->withQueryString();
        $enrolledPersonSet = Collection::make($enrolledPersonIds)->flip();
        $enrolledEmailSet = Collection::make($enrolledEmails)->flip();
        $suppressedSet = Collection::make($suppressedEmails)->flip();

        return response()->json([
            'data' => $people->getCollection()->map(fn (Person $person): array => $this->personPayload(
                $person,
                $enrolledPersonSet,
                $enrolledEmailSet,
                $suppressedSet,
            ))->values()->all(),
            'meta' => [
                'current_page' => $people->currentPage(),
                'last_page' => $people->lastPage(),
                'per_page' => $people->perPage(),
                'total' => $people->total(),
            ],
        ]);
    }

    public function store(
        StoreCampaignEnrollmentRequest $request,
        Campaign $campaign,
        EnrollPeopleInCampaign $enroll,
    ): RedirectResponse {
        $ids = array_values(array_unique(array_map(
            intval(...),
            $request->validated('person_ids'),
        )));

        try {
            $result = DB::transaction(function () use ($campaign, $ids, $enroll): EnrollmentResult {
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

                $enrollment = $enroll->handle($locked, $candidates);

                return new EnrollmentResult(
                    $enrollment->enrolled,
                    [...$skipped, ...$enrollment->skipped],
                );
            });
        } catch (GovernedCampaignInputMutation $exception) {
            return back()
                ->withErrors(['campaign' => GovernedCampaignInputMutation::CODE.': '.$exception->getMessage()]);
        } catch (FrozenMailExperimentInputMutation $exception) {
            return back()->withErrors(['campaign' => $exception->getMessage()]);
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

        return back()->with('enrollment', $payload);
    }

    /**
     * @param  Collection<int, mixed>  $enrolledPersonIds
     * @param  Collection<string, mixed>  $enrolledEmails
     * @param  Collection<string, mixed>  $suppressedEmails
     * @return array{id: int, name: string, email: string, account: string|null, eligibility: string, enrollable: bool, reason: string|null}
     */
    private function personPayload(
        Person $person,
        Collection $enrolledPersonIds,
        Collection $enrolledEmails,
        Collection $suppressedEmails,
    ): array {
        $reason = null;

        if ($enrolledPersonIds->has($person->id) || $enrolledEmails->has($person->email_normalized)) {
            $reason = EnrollPeopleInCampaign::REASON_ALREADY_ENROLLED;
        } elseif ($suppressedEmails->has($person->email_normalized)) {
            $reason = EnrollPeopleInCampaign::REASON_SUPPRESSED;
        }

        return [
            'id' => $person->id,
            'name' => $this->displayName($person),
            'email' => $person->email_address,
            'account' => $person->account?->name,
            'eligibility' => $suppressedEmails->has($person->email_normalized) ? 'Suppressed' : 'Eligible',
            'enrollable' => $reason === null,
            'reason' => $reason,
        ];
    }

    private function displayName(Person $person): string
    {
        foreach ([$person->full_name, $person->first_name] as $value) {
            $name = trim((string) $value);

            if ($name !== '') {
                return $name;
            }
        }

        return $person->email_address;
    }

    private function like(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }
}
