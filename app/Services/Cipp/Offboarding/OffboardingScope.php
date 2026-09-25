<?php

namespace App\Services\Cipp\Offboarding;

use App\Models\Client;
use App\Models\McpToken;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Cipp\CippWriteScopeResolver;
use App\Support\CippConfig;
use App\Support\McpToolModes;
use App\Support\TechnicianConfig;
use RuntimeException;

/** Source-bound identities and fail-closed live dependencies, never caller-supplied routes. */
class OffboardingScope
{
    public function __construct(private readonly CippRestWriteClient $client, private readonly CippWriteScopeResolver $resolver) {}

    public function token(int $id): McpToken
    {
        $token = McpToken::query()->authenticatable()->find($id);
        $grants = $token && is_array($token->tools) ? McpToolModes::parseGrants($token->tools) : null;
        if (! $token || ! $grants || ! in_array('cipp_offboard_user', $grants['tools'], true)) {
            throw new RuntimeException('An active explicit offboarding grant is required.');
        }
        $this->enabled();

        return $token;
    }

    public function approver(int $id, TechnicianRun $run): void
    {
        $this->reader($id, $run);
        $ticket = Ticket::automationVisible()->findOrFail($run->ticket_id);
        // Bearer-token proposals and human approval are separate principal types. Also
        // refuse the ticket's recorded human requester; never compare token IDs to user IDs.
        if ($ticket->created_by === $id) {
            throw new RuntimeException('A separate human approver is required.');
        }
        $this->enabled();
    }

    /** Local staff ACL: admins, or the technician assigned to this same-client ticket. */
    public function reader(int $id, TechnicianRun $run): void
    {
        $user = User::find($id);
        $ticket = Ticket::automationVisible()->find($run->ticket_id);
        if (! $user || ! $user->is_active || ! $ticket || ! $run->client_id
            || $ticket->client_id !== $run->client_id
            || (! $user->isAdmin() && (! $user->isTech() || $ticket->assignee_id !== $id))) {
            throw new RuntimeException('An authorized same-client staff reader is required.');
        }
    }

    public function recoveryIntegration(array $snapshot): void
    {
        $integration = hash('sha256', OffboardingPlan::canonical([
            CippConfig::get('api_url'), CippConfig::get('tenant_id'), CippConfig::get('client_id'), CippConfig::get('application_id'),
        ]));
        if (! hash_equals($snapshot['namespace'][1], $integration)) {
            throw new RuntimeException('Original CIPP integration is unavailable; do not retarget recovery.');
        }
    }

    public function enabled(): void
    {
        if (! CippConfig::isEnabled() || ! CippConfig::isConfigured() || TechnicianConfig::killSwitchEngaged()) {
            throw new RuntimeException('Offboarding is disabled, unconfigured or paused.');
        }
    }

    public function resolve(array $input, string $reference): array
    {
        $input = OffboardingPlan::validate($input);
        $this->enabled();
        $client = Client::find($input['client_id']);
        if (! $client) {
            throw new RuntimeException('Client could not be verified.');
        }
        $tenant = strtolower($this->resolver->resolveCippTenant($client));
        $this->resolver->resolveTicketForHeldAction($client->id, $input['ticket_id']);
        $target = $this->resolver->resolveCippPerson($client->id, $input['person_id']);
        $successor = isset($input['successor_person_id'])
            ? $this->resolver->resolveActiveCippPerson($client->id, $input['successor_person_id'], 'user') : null;
        if ($successor && (strtolower($successor->userId) === strtolower($target->userId)
            || strcasecmp($successor->userPrincipalName, $target->userPrincipalName) === 0
            || $successor->person->m365_user_type !== 'Member')) {
            throw new RuntimeException('Successor must be a different active member in the same tenant.');
        }
        // Resolve aliases to a canonical tenant ID from a complete current tenant listing.
        $tenants = $this->client->offboardingRead('tenants');
        $matches = array_values(array_filter($tenants, fn (array $row) => in_array($tenant, $this->aliases($row), true)));
        if (count($matches) !== 1 || ! is_string($matches[0]['customerId'] ?? null)
            || ! preg_match('/^[0-9a-f-]{36}$/iD', $matches[0]['customerId'])) {
            throw new RuntimeException('Canonical tenant identity is unresolved or ambiguous.');
        }
        $canonical = strtolower($matches[0]['customerId']);
        $aliases = $this->aliases($matches[0]);
        foreach (Client::whereNotNull('cipp_tenant_domain')->where('id', '!=', $client->id)->get() as $other) {
            if (in_array(strtolower(trim($other->cipp_tenant_domain)), $aliases, true)) {
                throw new RuntimeException('Multiple local clients map to this tenant; resolve the mapping before offboarding.');
            }
        }
        $users = $this->client->offboardingRead('users', ['tenantFilter' => $tenant]);
        $this->verifyPerson($target->person, $users, false);
        if ($successor) {
            $this->verifyPerson($successor->person, $users, true);
        }
        $installation = Setting::getValue('cipp_offboarding_installation_id');
        if (! is_string($installation) || ! preg_match('/^[0-9a-f-]{36}$/iD', $installation)) {
            throw new RuntimeException('Offboarding installation identity has not been established.');
        }
        $integration = hash('sha256', OffboardingPlan::canonical([
            CippConfig::get('api_url'), CippConfig::get('tenant_id'), CippConfig::get('client_id'), CippConfig::get('application_id'),
        ]));
        $snapshot = [
            'namespace' => [strtolower($installation), $integration, $canonical],
            'target_id' => strtolower($target->userId), 'target_upn' => $target->userPrincipalName,
            'successor_id' => $successor ? strtolower($successor->userId) : null,
            'successor_upn' => $successor?->userPrincipalName,
            'input' => $input, 'adapter_version' => 1, 'revision' => 1,
            'body' => OffboardingPlan::serialize($input, $tenant, $target->userPrincipalName, $successor?->userPrincipalName, $reference),
        ];

        return $snapshot;
    }

    public function dependenciesAndScheduler(array $snapshot): void
    {
        // Operator-recorded, credential/version-bound attestation. No installation or grants here.
        $raw = Setting::getValue('cipp_offboarding_sequential_evidence');
        $evidence = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($evidence) || ($evidence['integration'] ?? null) !== $snapshot['namespace'][1]
            || ($evidence['sequential'] ?? null) !== true || ! is_string($evidence['version'] ?? null)
            || trim($evidence['version']) === '' || ! is_string($evidence['checked_at'] ?? null)
            || ! is_string($evidence['expires_at'] ?? null)) {
            throw new RuntimeException('Pre-live blocker: installed Craft/CIPP Sequential compatibility is not established.');
        }
        try {
            $checked = \Carbon\CarbonImmutable::parse($evidence['checked_at']);
            $expires = \Carbon\CarbonImmutable::parse($evidence['expires_at']);
        } catch (\Throwable) {
            throw new RuntimeException('Sequential compatibility evidence is invalid.');
        }
        if ($checked->isFuture() || $expires->isPast() || $expires->gt($checked->addDay())) {
            throw new RuntimeException('Sequential compatibility evidence is stale or invalid.');
        }
        // A successful scoped Scheduler.Read is mandatory, not a boolean granting permission.
        // Upstream ShowHidden selects ONLY hidden rows, not hidden plus visible.
        foreach (['false', 'true'] as $hidden) {
            $rows = $this->client->offboardingRead('scheduled', [
                'tenantFilter' => $snapshot['body']['tenantFilter'],
                'Name' => 'Offboarding: '.$snapshot['target_upn'], 'Type' => 'Invoke-CIPPOffboardingJob',
                ...($hidden === 'true' ? ['ShowHidden' => 'true'] : []),
            ]);
            foreach ($rows as $row) {
                if (($row['Reference'] ?? null) === $snapshot['body']['reference']
                    || ! in_array($row['TaskState'] ?? null, ['Completed', 'Failed'], true)) {
                    throw new RuntimeException('An existing or unclassified scheduler task blocks admission; reconcile on a separate card.');
                }
            }
        }
    }

    private function verifyPerson(Person $person, array $users, bool $recipient): void
    {
        $id = strtolower(trim($person->cipp_user_id));
        $upn = strtolower(trim($person->cipp_upn));
        $matches = array_values(array_filter($users, fn (array $row) => strtolower((string) ($row['id'] ?? '')) === $id || strtolower((string) ($row['userPrincipalName'] ?? '')) === $upn));
        if (count($matches) !== 1 || strtolower((string) ($matches[0]['id'] ?? '')) !== $id
            || strtolower((string) ($matches[0]['userPrincipalName'] ?? '')) !== $upn
            || ($recipient && (($matches[0]['userType'] ?? null) !== 'Member' || ($matches[0]['accountEnabled'] ?? null) !== true))) {
            throw new RuntimeException('Current tenant-scoped identity does not match the local mapping.');
        }
        if (Person::where('client_id', $person->client_id)->where('id', '!=', $person->id)
            ->where(fn ($q) => $q->whereRaw('LOWER(cipp_user_id) = ?', [$id])->orWhereRaw('LOWER(cipp_upn) = ?', [$upn]))->exists()) {
            throw new RuntimeException('Ambiguous local person mapping.');
        }
    }

    private function aliases(array $row): array
    {
        $aliases = [];
        foreach (['customerId', 'defaultDomainName', 'initialDomainName'] as $key) {
            if (is_string($row[$key] ?? null)) {
                $aliases[] = strtolower(trim($row[$key]));
            }
        }
        foreach (is_array($row['domains'] ?? null) ? $row['domains'] : [] as $domain) {
            if (is_string($domain)) {
                $aliases[] = strtolower(trim($domain));
            }
        }

        return array_values(array_unique($aliases));
    }
}
