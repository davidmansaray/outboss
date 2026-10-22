<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Mail\DTOs\AssignmentDecision;
use App\Domain\Mail\Enums\MailExperimentDomainStrategy;
use App\Domain\Mail\Enums\MailExperimentStatus;
use App\Domain\Mail\Exceptions\ManagedMailProvisioningException;
use App\Domain\Mail\Services\ExperimentEvidenceExporter;
use App\Domain\Mail\Services\ExperimentMetricsService;
use App\Domain\Mail\Services\MailExperimentAssignmentService;
use App\Domain\Mail\Services\MailExperimentLifecycleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AttachMailExperimentMailboxesRequest;
use App\Http\Requests\Settings\PrepareMailExperimentRequest;
use App\Http\Requests\Settings\StoreMailExperimentRequest;
use App\Models\Campaign;
use App\Models\Mailbox;
use App\Models\MailExperiment;
use App\Models\MailExperimentCohort;
use App\Models\ManagedMailboxAccount;
use App\Models\Person;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Internal owner-only experiment preparation, lifecycle, and reporting UI. */
final class MailExperimentController extends Controller
{
    public function __construct(
        private readonly MailExperimentLifecycleService $lifecycle,
        private readonly MailExperimentAssignmentService $assignments,
        private readonly ExperimentMetricsService $metrics,
        private readonly ExperimentEvidenceExporter $exports,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();

        $experiments = MailExperiment::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['cohorts', 'assignments'])
            ->with('cohorts')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MailExperiment $experiment): array => $this->experimentSummary($experiment))
            ->values()
            ->all();

        return Inertia::render('mail-experiments/Index', [
            'experiments' => $experiments,
            'canManage' => true,
        ]);
    }

    public function store(StoreMailExperimentRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $data = $request->validated();
        $cohortData = $this->cohortData($data['cohorts'] ?? null);
        $mailboxIds = $this->mailboxIds($cohortData);

        $this->assertMailboxesBelongToWorkspace($workspace, $mailboxIds);

        try {
            $experiment = DB::transaction(function () use ($workspace, $user, $data, $cohortData): MailExperiment {
                $experiment = MailExperiment::query()->create([
                    'workspace_id' => $workspace->id,
                    'created_by_user_id' => $user->id,
                    'name' => trim((string) $data['name']),
                    'hypothesis' => trim((string) $data['hypothesis']),
                    'status' => MailExperimentStatus::Draft,
                    'randomisation_seed' => $this->seed($data['randomisation_seed'] ?? null),
                    'primary_metric' => 'human_reply_rate',
                    'target_assignments_per_cohort' => isset($data['target_assignments_per_cohort'])
                        ? (int) $data['target_assignments_per_cohort']
                        : null,
                    'configuration' => $this->configuration($data['configuration'] ?? null),
                    'incidents' => [],
                    'incident_count' => 0,
                ]);

                foreach ($cohortData as $cohort) {
                    $experiment->cohorts()->create([
                        'key' => $cohort['key'],
                        'label' => $cohort['label'],
                        'domain_strategy' => $cohort['domain_strategy'],
                        'configuration' => [],
                    ]);
                }

                return $experiment;
            }, 3);

            foreach ($cohortData as $cohort) {
                $ids = array_map('intval', $cohort['mailbox_ids']);
                if ($ids === []) {
                    continue;
                }

                $model = $experiment->cohorts()->where('key', $cohort['key'])->firstOrFail();
                $this->lifecycle->attachMailboxes($model, $ids);
            }

            return redirect()->route('mail-experiments.show', $experiment->id)
                ->with('success', 'Mail experiment draft created.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return redirect()->route('mail-experiments.index')
                ->with('error', $this->safeMessage($exception, 'The mail experiment could not be created.'));
        }
    }

    public function show(Request $request, int $experimentId): Response
    {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $experiment = $this->experimentFor($workspace, $experimentId);
        $experiment->load([
            'cohorts.mailboxes',
            'cohorts.campaign',
        ]);

        $report = $this->metrics->report($experiment);
        $managedMailboxes = ManagedMailboxAccount::query()
            ->whereHas('managedMailDomain', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('status', '!=', 'deleted')
            ->with(['managedMailDomain', 'mailbox'])
            ->orderBy('id')
            ->get()
            ->map(fn (ManagedMailboxAccount $account): array => [
                'id' => $account->id,
                'mailbox_id' => $account->mailbox_id,
                'email' => $account->email_address,
                'domain' => $account->managedMailDomain?->domain,
                'status' => $this->value($account->status),
                'mailbox_status' => $this->value($account->mailbox?->status),
            ])
            ->values()
            ->all();
        $campaigns = Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'status'])
            ->map(fn (Campaign $campaign): array => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $this->value($campaign->status),
            ])
            ->values()
            ->all();

        return Inertia::render('mail-experiments/Show', [
            'experiment' => $this->experiment($experiment),
            'report' => $report,
            'managedMailboxes' => $managedMailboxes,
            'campaigns' => $campaigns,
            'candidateCount' => Person::query()->where('workspace_id', $workspace->id)->count(),
            'canManage' => true,
        ]);
    }

    public function attachMailboxes(
        AttachMailExperimentMailboxesRequest $request,
        int $experimentId,
        int $cohortId,
    ): RedirectResponse {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $experiment = $this->experimentFor($workspace, $experimentId);
        $cohort = MailExperimentCohort::query()
            ->whereKey($cohortId)
            ->where('mail_experiment_id', $experiment->id)
            ->firstOrFail();
        $ids = array_values(array_map('intval', $request->validated('mailbox_ids')));
        $this->assertMailboxesBelongToWorkspace($workspace, $ids);

        try {
            $this->lifecycle->attachMailboxes($cohort, $ids);

            return $this->experimentRedirect($experiment, 'Cohort mailboxes saved.');
        } catch (Throwable $exception) {
            return $this->experimentError($experiment, $exception, 'Cohort mailboxes could not be saved.');
        }
    }

    public function prepare(
        PrepareMailExperimentRequest $request,
        int $experimentId,
    ): RedirectResponse {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $experiment = $this->experimentFor($workspace, $experimentId);
        $data = $request->validated();

        $template = null;
        if (isset($data['template_campaign_id'])) {
            $template = Campaign::query()
                ->whereKey((int) $data['template_campaign_id'])
                ->where('workspace_id', $workspace->id)
                ->first();
            abort_unless($template instanceof Campaign, 404);
        }

        try {
            $prepared = $this->lifecycle->prepare(
                $experiment,
                Person::query()->where('workspace_id', $workspace->id)->with('account')->orderBy('id')->get(),
                $template,
                [
                    'compliance_review_confirmed' => $request->boolean('compliance_review_confirmed'),
                    'legal_review_confirmed' => $request->boolean('legal_review_confirmed'),
                    'source' => 'http',
                    'confirmed_by_user_id' => $user->id,
                ],
            );

            return $this->experimentRedirect(
                $prepared,
                'Assignments prepared: '.$prepared->assignments()->count().'.',
            );
        } catch (Throwable $exception) {
            return $this->experimentError($experiment, $exception, 'Assignments could not be prepared.');
        }
    }

    /** Preview provider/cohort balance without changing frozen evidence. */
    public function balance(Request $request, int $experimentId): RedirectResponse|JsonResponse
    {
        $user = $this->user($request);
        $workspace = $user->currentWorkspace();
        $experiment = $this->experimentFor($workspace, $experimentId);

        try {
            $decisions = $this->assignments->preview(
                $experiment,
                Person::query()->where('workspace_id', $workspace->id)->with('account')->orderBy('id')->get(),
            );
            $summary = $this->balanceSummary($decisions);

            if ($request->expectsJson()) {
                return response()->json([
                    'experiment_id' => $experiment->id,
                    'balance' => $summary,
                ]);
            }

            return $this->experimentRedirect($experiment, 'Balance preview refreshed.');
        } catch (Throwable $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $this->safeMessage($exception, 'Balance preview failed.')], 422);
            }

            return $this->experimentError($experiment, $exception, 'Balance preview failed.');
        }
    }

    public function freeze(Request $request, int $experimentId): RedirectResponse
    {
        return $this->transition($request, $experimentId, fn (MailExperiment $experiment): MailExperiment => $this->lifecycle->freeze($experiment), 'Experiment frozen for review.');
    }

    public function launch(Request $request, int $experimentId): RedirectResponse
    {
        return $this->transition($request, $experimentId, fn (MailExperiment $experiment): MailExperiment => $this->lifecycle->launch($experiment), 'Experiment launched.');
    }

    public function pause(Request $request, int $experimentId): RedirectResponse
    {
        return $this->transition($request, $experimentId, fn (MailExperiment $experiment): MailExperiment => $this->lifecycle->pause($experiment), 'Experiment paused.');
    }

    /**
     * Resume is deliberately routed through the lifecycle service.  That
     * operation revalidates the frozen campaign/assignment evidence while
     * holding the experiment lock before restoring cohort campaign work.
     */
    public function resume(Request $request, int $experimentId): RedirectResponse
    {
        return $this->transition($request, $experimentId, fn (MailExperiment $experiment): MailExperiment => $this->lifecycle->resume($experiment), 'Experiment resumed.');
    }

    public function complete(Request $request, int $experimentId): RedirectResponse
    {
        return $this->transition($request, $experimentId, fn (MailExperiment $experiment): MailExperiment => $this->lifecycle->complete($experiment), 'Experiment completed.');
    }

    public function report(Request $request, int $experimentId): JsonResponse
    {
        $user = $this->user($request);
        $experiment = $this->experimentFor($user->currentWorkspace(), $experimentId);

        return response()->json($this->metrics->report($experiment, $this->filters($request)));
    }

    public function export(Request $request, int $experimentId): JsonResponse|StreamedResponse
    {
        $user = $this->user($request);
        $experiment = $this->experimentFor($user->currentWorkspace(), $experimentId);
        $format = strtolower((string) $request->query('format', 'json'));

        if ($format === 'csv') {
            return $this->exports->downloadCsv($experiment, $this->filters($request));
        }

        if ($format !== 'json') {
            throw ValidationException::withMessages(['format' => 'Format must be json or csv.']);
        }

        return $this->exports->downloadJson($experiment, $this->filters($request));
    }

    private function transition(
        Request $request,
        int $experimentId,
        callable $operation,
        string $success,
    ): RedirectResponse {
        $user = $this->user($request);
        $experiment = $this->experimentFor($user->currentWorkspace(), $experimentId);

        try {
            $updated = $operation($experiment);

            return $this->experimentRedirect($updated, $success);
        } catch (Throwable $exception) {
            return $this->experimentError($experiment, $exception, 'Experiment lifecycle action failed.');
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function experimentFor(Workspace $workspace, int $experimentId): MailExperiment
    {
        return MailExperiment::query()
            ->whereKey($experimentId)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function experimentSummary(MailExperiment $experiment): array
    {
        return [
            'id' => $experiment->id,
            'name' => $experiment->name,
            'hypothesis' => $experiment->hypothesis,
            'status' => $this->value($experiment->status),
            'primary_metric' => $experiment->primary_metric,
            'target_assignments_per_cohort' => $experiment->target_assignments_per_cohort,
            'frozen_at' => $this->date($experiment->frozen_at),
            'cohort_count' => (int) ($experiment->cohorts_count ?? $experiment->cohorts()->count()),
            'assignment_count' => (int) ($experiment->assignments_count ?? $experiment->assignments()->count()),
        ];
    }

    /** @return array<string, mixed> */
    private function experiment(MailExperiment $experiment): array
    {
        return [
            ...$this->experimentSummary($experiment),
            'workspace_id' => $experiment->workspace_id,
            'created_by_user_id' => $experiment->created_by_user_id,
            'randomisation_seed' => '[stored encrypted]',
            'configuration' => $this->safeConfiguration(
                is_array($configuration = $experiment->getAttribute('configuration')) ? $configuration : [],
            ),
            'approval_evidence' => $this->safeValues(
                is_array($configuration['approval_evidence'] ?? null)
                    ? $configuration['approval_evidence']
                    : [],
            ),
            'operator_minutes' => $experiment->operator_minutes,
            'operator_cost_micros' => $experiment->operator_cost_micros,
            'infrastructure_cost_micros' => $experiment->infrastructure_cost_micros,
            'incident_count' => $experiment->incident_count,
            'incidents' => $experiment->incidents ?? [],
            'started_at' => $this->date($experiment->started_at),
            'completed_at' => $this->date($experiment->completed_at),
            'aborted_at' => $this->date($experiment->aborted_at),
            'cohorts' => $experiment->cohorts
                ->map(fn (MailExperimentCohort $cohort): array => [
                    'id' => $cohort->id,
                    'key' => $cohort->key,
                    'label' => $cohort->label,
                    'domain_strategy' => $this->value($cohort->domain_strategy),
                    'snapshot_hash' => $cohort->snapshot_hash,
                    'mailbox_topology_snapshot' => $cohort->mailbox_topology_snapshot,
                    'campaign_id' => $cohort->campaign_id,
                    'campaign_name' => $cohort->campaign?->name,
                    'mailboxes' => $cohort->mailboxes
                        ->map(fn (Mailbox $mailbox): array => [
                            'id' => $mailbox->id,
                            'email' => $mailbox->email_address,
                            'status' => $this->value($mailbox->status),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return list<array{key: string, label: string, domain_strategy: string, mailbox_ids: list<int>}> */
    private function cohortData(mixed $cohorts): array
    {
        if (! is_array($cohorts)) {
            return [
                ['key' => 'a', 'label' => 'Branded subdomain', 'domain_strategy' => MailExperimentDomainStrategy::Subdomain->value, 'mailbox_ids' => []],
                ['key' => 'b', 'label' => 'Separate root domains', 'domain_strategy' => MailExperimentDomainStrategy::SeparateRootDomains->value, 'mailbox_ids' => []],
            ];
        }

        if (count($cohorts) !== 2) {
            throw ValidationException::withMessages(['cohorts' => 'Exactly two strategy cohorts are required.']);
        }

        $result = [];
        $keys = [];
        $strategies = [];
        foreach ($cohorts as $cohort) {
            if (! is_array($cohort)) {
                throw ValidationException::withMessages(['cohorts' => 'Each cohort must be an object.']);
            }

            $key = strtolower(trim((string) ($cohort['key'] ?? '')));
            if (in_array($key, $keys, true)) {
                throw ValidationException::withMessages(['cohorts' => 'Cohort keys must be unique.']);
            }
            $keys[] = $key;
            $strategy = MailExperimentDomainStrategy::tryFrom((string) ($cohort['domain_strategy'] ?? ''));
            if ($strategy === null) {
                throw ValidationException::withMessages(['cohorts' => 'Choose a supported domain strategy.']);
            }
            if (in_array($strategy->value, $strategies, true)) {
                throw ValidationException::withMessages(['cohorts' => 'Choose one subdomain cohort and one separate-root-domains cohort.']);
            }
            $strategies[] = $strategy->value;
            $mailboxIds = [];
            $rawMailboxIds = $cohort['mailbox_ids'] ?? [];
            if (is_array($rawMailboxIds)) {
                foreach ($rawMailboxIds as $mailboxId) {
                    if (! is_int($mailboxId) && ! (is_string($mailboxId) && is_numeric($mailboxId))) {
                        throw ValidationException::withMessages(['cohorts' => 'Cohort mailbox IDs must be numeric.']);
                    }
                    $mailboxIds[] = (int) $mailboxId;
                }
            }

            $result[] = [
                'key' => $key,
                'label' => trim((string) ($cohort['label'] ?? '')),
                'domain_strategy' => $strategy->value,
                'mailbox_ids' => $mailboxIds,
            ];
        }

        sort($strategies, SORT_STRING);
        if ($strategies !== [
            MailExperimentDomainStrategy::SeparateRootDomains->value,
            MailExperimentDomainStrategy::Subdomain->value,
        ]) {
            throw ValidationException::withMessages(['cohorts' => 'Choose one subdomain cohort and one separate-root-domains cohort.']);
        }

        return $result;
    }

    /**
     * @param  list<array{key: string, label: string, domain_strategy: string, mailbox_ids: list<int>}>  $cohorts
     * @return list<int>
     */
    private function mailboxIds(array $cohorts): array
    {
        $ids = [];
        foreach ($cohorts as $cohort) {
            foreach ($cohort['mailbox_ids'] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param list<int> $mailboxIds */
    private function assertMailboxesBelongToWorkspace(Workspace $workspace, array $mailboxIds): void
    {
        if ($mailboxIds === []) {
            return;
        }

        $found = Mailbox::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $mailboxIds)
            ->count();
        if ($found !== count($mailboxIds)) {
            // Treat foreign IDs as absent objects, matching the domain and
            // experiment lookups above. This prevents an internal endpoint
            // from becoming an ownership oracle through validation text.
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function configuration(mixed $configuration): array
    {
        $configuration = is_array($configuration) ? $configuration : [];

        return $this->safeConfiguration($configuration);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function safeConfiguration(array $configuration): array
    {
        $allowed = [];
        foreach (['eligibility', 'sending', 'analysis', 'ramp'] as $key) {
            if (! array_key_exists($key, $configuration) || ! is_array($configuration[$key])) {
                continue;
            }
            $allowed[$key] = $this->safeValues($configuration[$key]);
        }

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function safeValues(array $values, int $depth = 0): array
    {
        if ($depth > 2) {
            return [];
        }

        $safe = [];
        foreach ($values as $key => $value) {
            $key = substr((string) $key, 0, 64);
            if ($key === '' || preg_match('/(?:pass(word)?|secret|token|credential|api[_-]?key|authorization)/i', $key) === 1) {
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->safeValues($value, $depth + 1);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value)
                    ? substr(str_replace(["\r", "\n"], ' ', trim($value)), 0, 1000)
                    : $value;
            }
        }

        return $safe;
    }

    private function seed(mixed $seed): string
    {
        $seed = is_string($seed) ? trim($seed) : '';

        return $seed !== '' ? $seed : Str::random(48);
    }

    /**
     * @param  list<AssignmentDecision>  $decisions
     * @return array<string, mixed>
     */
    private function balanceSummary(array $decisions): array
    {
        $cohorts = [];
        $providers = [];
        $excluded = 0;
        foreach ($decisions as $decision) {
            $cohort = $decision->cohortKey ?? 'excluded';
            $provider = $decision->recipientProvider->value;
            $cohorts[$cohort] = ($cohorts[$cohort] ?? 0) + 1;
            $providers[$provider] = ($providers[$provider] ?? 0) + 1;
            if ($decision->isExcluded()) {
                $excluded++;
            }
        }
        ksort($cohorts);
        ksort($providers);

        return [
            'assignment_count' => count($decisions),
            'excluded_count' => $excluded,
            'cohorts' => $cohorts,
            'recipient_providers' => $providers,
        ];
    }

    /** @return array<string, scalar|list<string>|null> */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (['cohort', 'recipient_provider', 'sender_domain', 'mailbox_id'] as $key) {
            $value = $request->query($key);
            if (is_scalar($value) && (string) $value !== '') {
                $filters[$key] = (string) $value;
            }
        }

        return $filters;
    }

    private function experimentRedirect(MailExperiment $experiment, string $message): RedirectResponse
    {
        return redirect()->route('mail-experiments.show', $experiment->id)->with('success', $message);
    }

    private function experimentError(MailExperiment $experiment, Throwable $exception, string $fallback): RedirectResponse
    {
        return redirect()->route('mail-experiments.show', $experiment->id)
            ->with('error', $this->safeMessage($exception, $fallback));
    }

    private function safeMessage(Throwable $exception, string $fallback): string
    {
        if (! $exception instanceof LogicException
            && ! $exception instanceof ManagedMailProvisioningException) {
            return $fallback;
        }

        $message = trim(str_replace(["\r", "\n"], ' ', $exception->getMessage()));

        return $message === '' ? $fallback : substr($message, 0, 500);
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (is_string($value) ? $value : null);
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
