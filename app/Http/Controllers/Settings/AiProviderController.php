<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Ai\Enums\AiOperationStatus;
use App\Domain\Ai\Enums\AiProviderName;
use App\Domain\Ai\Enums\AiTask;
use App\Domain\Ai\Exceptions\SafeProviderException;
use App\Domain\Ai\Services\AiCredentialManager;
use App\Domain\Ai\Services\AiWorkspaceSettings;
use App\Domain\Ai\Support\UsdMicros;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ConnectAiProviderRequest;
use App\Http\Requests\Settings\RotateAiProviderRequest;
use App\Http\Requests\Settings\UpdateAiBudgetRequest;
use App\Http\Requests\Settings\UpdateAiProviderStatusRequest;
use App\Http\Requests\Settings\UpdateAiTaskRequest;
use App\Models\AiBudgetPeriod;
use App\Models\AiBudgetSetting;
use App\Models\AiProviderCredential;
use App\Models\AiProviderEvent;
use App\Models\AiTaskSetting;
use App\Models\AiUsageLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AiProviderController extends Controller
{
    public function __construct(
        private readonly AiWorkspaceSettings $settings,
        private readonly AiCredentialManager $credentials,
    ) {}

    public function index(Request $request): Response
    {
        return $this->page($request, 'overview');
    }

    public function usage(Request $request): Response
    {
        return $this->page($request, 'activity', 'usage');
    }

    public function audit(Request $request): Response
    {
        return $this->page($request, 'activity', 'history');
    }

    public function connect(ConnectAiProviderRequest $request, string $provider): RedirectResponse
    {
        $name = AiProviderName::tryFrom($provider);
        abort_if($name === null, 404);

        try {
            $this->credentials->connect(
                $request->user()->currentWorkspace(),
                $request->user(),
                $name,
                (string) $request->validated('secret'),
            );
        } catch (SafeProviderException|RuntimeException $exception) {
            return back()->withErrors(['secret' => $exception->getMessage()]);
        }

        return to_route('providers.index')->with('success', "{$name->displayName()} connected.");
    }

    public function test(Request $request, AiProviderCredential $credential): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->authorizeCredential($request, $credential);

        try {
            $this->credentials->test($workspace, $request->user(), $credential);
        } catch (SafeProviderException|RuntimeException $exception) {
            return back()->withErrors(['provider' => $exception->getMessage()]);
        }

        return back()->with('success', 'Connection test completed and recorded in usage.');
    }

    public function rotate(RotateAiProviderRequest $request, AiProviderCredential $credential): RedirectResponse
    {
        $this->authorizeCredential($request, $credential);

        try {
            $this->credentials->rotate(
                $request->user()->currentWorkspace(),
                $request->user(),
                $credential,
                (string) $request->validated('secret'),
            );
        } catch (SafeProviderException|RuntimeException $exception) {
            return back()->withErrors(['secret' => $exception->getMessage()]);
        }

        return back()->with('success', 'The provider credential was rotated. Revoke the old key in the provider console.');
    }

    public function status(UpdateAiProviderStatusRequest $request, AiProviderCredential $credential): RedirectResponse
    {
        $this->authorizeCredential($request, $credential);
        try {
            $this->credentials->setEnabled(
                $request->user()->currentWorkspace(),
                $request->user(),
                $credential,
                (bool) $request->validated('enabled'),
            );
        } catch (SafeProviderException|RuntimeException $exception) {
            return back()->withErrors(['provider' => $exception->getMessage()]);
        }

        return back()->with('success', (bool) $request->validated('enabled') ? 'Provider enabled.' : 'Provider disabled. The encrypted key was retained.');
    }

    public function destroy(Request $request, AiProviderCredential $credential): RedirectResponse
    {
        $this->authorizeCredential($request, $credential);
        $this->credentials->remove($request->user()->currentWorkspace(), $request->user(), $credential);

        return back()->with('success', 'Provider removed from Outboss. Revoke the key separately in the provider console.');
    }

    public function updateTask(UpdateAiTaskRequest $request, string $task): RedirectResponse
    {
        $taskName = AiTask::tryFrom($task);
        abort_if($taskName === null || ! in_array($task, AiTask::productValues(), true), 404);
        $this->settings->ensureDefaults($request->user()->currentWorkspace());
        $this->settings->updateTask(
            $request->user()->currentWorkspace(),
            $request->user(),
            $taskName,
            (string) $request->validated('model_id'),
            UsdMicros::parse((string) $request->validated('per_run_limit_usd')),
        );

        return back()->with('success', 'Task model and per-run limit updated.');
    }

    public function updateBudget(UpdateAiBudgetRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $this->settings->ensureDefaults($workspace);
        $monthly = UsdMicros::parse((string) $request->validated('monthly_limit_usd'));
        $tavily = UsdMicros::parse((string) $request->validated('tavily_credit_price_usd'));

        if ($this->settings->expandsSpendingAuthority($workspace, $monthly, $tavily) && ! $this->passwordRecentlyConfirmed($request)) {
            return redirect()->guest(route('password.confirm'));
        }

        $this->settings->updateBudget($workspace, $request->user(), $monthly, $tavily);

        return back()->with('success', 'Workspace AI budget updated for new calls. Existing reservations were not cancelled.');
    }

    private function page(Request $request, string $initialTab, string $initialActivityView = 'usage'): Response
    {
        $workspace = $request->user()->currentWorkspace();
        abort_unless($workspace->membershipFor($request->user()) !== null, 403);
        $this->settings->ensureDefaults($workspace);
        $owner = $workspace->isOwner($request->user());
        if ($initialActivityView === 'history') {
            abort_unless($owner, 403);
        }

        $credentials = AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->with('activeVersion:id,credential_id,status')
            ->get()
            ->keyBy(fn (AiProviderCredential $credential) => $credential->provider->value);
        $budget = AiBudgetSetting::query()->whereKey($workspace->id)->firstOrFail();
        $period = AiBudgetPeriod::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('period_start_utc', Date::now('UTC')->startOfMonth()->toDateString())
            ->first();

        $providerRows = [];
        foreach (AiProviderName::cases() as $provider) {
            $credential = $credentials->get($provider->value);
            $connected = $credential instanceof AiProviderCredential && $credential->status->value !== 'removed';
            $providerRows[] = [
                'id' => $connected ? $credential->id : null,
                'provider' => $provider->value,
                'displayName' => $provider->displayName(),
                'capability' => $provider->capability(),
                'status' => $connected ? $credential->status->value : 'disconnected',
                'credentialDisplay' => $connected ? '••••••••••••' : null,
                'lastTestedAt' => $connected && $credential->last_tested_at !== null ? $credential->last_tested_at->toIso8601String() : null,
                'lastHealthyAt' => $connected && $credential->last_healthy_at !== null ? $credential->last_healthy_at->toIso8601String() : null,
                'retryAfterAt' => $connected && $credential->retry_after_at !== null ? $credential->retry_after_at->toIso8601String() : null,
                'safeError' => $connected && $credential->last_error_code !== null ? [
                    'code' => $credential->last_error_code,
                    'message' => $credential->last_error_message,
                ] : null,
                'balance' => $connected ? $credential->provider_balance_summary : null,
                'balanceCheckedAt' => $connected && $credential->balance_checked_at !== null ? $credential->balance_checked_at->toIso8601String() : null,
            ];
        }

        $modelRows = [];
        $configuredModels = config('ai.models');
        if (is_array($configuredModels)) {
            foreach ($configuredModels as $id => $model) {
                if (! is_string($id) || ! is_array($model)) {
                    continue;
                }
                $modelRows[] = [
                    'id' => $id,
                    'displayName' => $model['display_name'],
                    'tasks' => $model['tasks'],
                    'contextTokens' => $model['context_tokens'],
                    'validatedAt' => $model['validated_at'],
                ];
            }
        }

        $taskRows = [];
        foreach (AiTaskSetting::query()->where('workspace_id', $workspace->id)->orderBy('id')->get() as $task) {
            $taskRows[] = [
                'task' => $task->task->value,
                'displayName' => config("ai.tasks.{$task->task->value}.display_name"),
                'modelId' => $task->model_id,
                'perRunLimitMicros' => $task->per_run_limit_micros,
                'policyVersion' => $task->policy_version,
                'requiresResearch' => (bool) config("ai.tasks.{$task->task->value}.requires_research"),
            ];
        }

        $providerFilter = in_array($request->query('provider'), array_column(AiProviderName::cases(), 'value'), true)
            ? (string) $request->query('provider')
            : null;
        $taskFilter = in_array($request->query('task'), array_column(AiTask::cases(), 'value'), true)
            ? (string) $request->query('task')
            : null;
        $statusFilter = in_array($request->query('status'), array_column(AiOperationStatus::cases(), 'value'), true)
            ? (string) $request->query('status')
            : null;
        $usageQuery = AiUsageLedger::query()
            ->where('workspace_id', $workspace->id)
            ->with('providerOperation:id,status,reserved_cost_micros')
            ->when($providerFilter, fn ($query, $provider) => $query->where('provider', $provider))
            ->when($taskFilter, fn ($query, $task) => $query->where('task', $task))
            ->when($statusFilter, fn ($query, $status) => $query->whereHas(
                'providerOperation',
                fn ($operation) => $operation->where('status', $status),
            ))
            ->latest('occurred_at')
            ->paginate(25, ['*'], 'usage_page')
            ->withQueryString();

        $usageRows = [];
        foreach ($usageQuery->items() as $entry) {
            $usageRows[] = [
                'id' => $entry->id,
                'occurredAt' => $entry->occurred_at->toIso8601String(),
                'provider' => $entry->provider->value,
                'operation' => $entry->operation,
                'task' => $entry->task->value,
                'modelOrEndpoint' => $entry->model_or_endpoint,
                'nativeUnit' => $entry->native_unit,
                'nativeUnits' => $entry->tavily_credits ?? (($entry->input_tokens ?? 0) + ($entry->output_tokens ?? 0)),
                'costMicros' => $entry->cost_micros,
                'costBasis' => $entry->cost_basis,
                'providerRequestId' => $entry->provider_request_id,
                'estimatedCostMicros' => $entry->providerOperation->reserved_cost_micros,
                'status' => $entry->providerOperation->status->value,
                'correlationId' => $entry->operation_id,
            ];
        }

        $auditRows = [];
        if ($owner) {
            foreach (AiProviderEvent::query()->where('workspace_id', $workspace->id)->latest('created_at')->limit(50)->get() as $event) {
                $auditRows[] = [
                    'id' => $event->id,
                    'event' => $event->event,
                    'actorType' => $event->actor_type,
                    'fromState' => $event->from_state,
                    'toState' => $event->to_state,
                    'metadata' => $event->safe_metadata,
                    'createdAt' => $event->created_at->toIso8601String(),
                ];
            }
        }

        $committedMicros = $period instanceof AiBudgetPeriod ? $period->committed_micros : 0;
        $heldMicros = $period instanceof AiBudgetPeriod ? $period->held_micros : 0;
        $overLimit = $period instanceof AiBudgetPeriod && $period->over_limit;
        $budgetBreach = $period instanceof AiBudgetPeriod && $period->budget_breach;

        return Inertia::render('providers/Index', [
            'initialTab' => $initialTab,
            'initialActivityView' => $initialActivityView,
            'isOwner' => $owner,
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'providers' => $providerRows,
            'models' => $modelRows,
            'tasks' => $taskRows,
            'budget' => [
                'currency' => 'USD',
                'monthlyLimitMicros' => $budget->monthly_limit_micros,
                'tavilyCreditPriceMicros' => $budget->tavily_credit_price_micros,
                'committedMicros' => $committedMicros,
                'heldMicros' => $heldMicros,
                'remainingMicros' => max(0, (int) $budget->monthly_limit_micros - $committedMicros - $heldMicros),
                'overLimit' => $overLimit,
                'budgetBreach' => $budgetBreach,
                'resetsAt' => Date::now('UTC')->addMonthNoOverflow()->startOfMonth()->toIso8601String(),
            ],
            'usage' => $usageRows,
            'usageFilters' => [
                'provider' => $providerFilter,
                'task' => $taskFilter,
                'status' => $statusFilter,
            ],
            'usagePagination' => [
                'currentPage' => $usageQuery->currentPage(),
                'lastPage' => $usageQuery->lastPage(),
                'total' => $usageQuery->total(),
                'previousUrl' => $usageQuery->previousPageUrl(),
                'nextUrl' => $usageQuery->nextPageUrl(),
            ],
            'audit' => $auditRows,
        ]);
    }

    private function authorizeCredential(Request $request, AiProviderCredential $credential): void
    {
        $workspace = $request->user()->currentWorkspace();
        abort_unless((int) $credential->workspace_id === (int) $workspace->id && $workspace->isOwner($request->user()), 403);
    }

    private function passwordRecentlyConfirmed(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt > 0 && time() - $confirmedAt < (int) config('auth.password_timeout', 10_800);
    }
}
