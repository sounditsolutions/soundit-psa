<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ClientIntegrationService;
use App\Support\CometConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientIntegrationController extends Controller
{
    public function __construct(
        private readonly ClientIntegrationService $integrationService,
    ) {}

    /**
     * AJAX: Fetch available vendor entities for linking.
     */
    public function entities(Client $client, string $vendor): JsonResponse
    {
        $this->resolveVendor($vendor);

        try {
            $entities = $this->integrationService->fetchEntities($client, $vendor);

            return response()->json($entities);
        } catch (\Throwable $e) {
            $registry = $this->vendorLabel($vendor);

            return response()->json([
                'error' => "Could not load {$registry} entities. The vendor API may be unavailable.",
            ], 502);
        }
    }

    /**
     * Link a client to a vendor entity.
     */
    public function link(Request $request, Client $client, string $vendor)
    {
        $this->resolveVendor($vendor);

        $request->validate([
            'entity_id' => 'required',
            'display_name' => 'nullable|string|max:255',
        ]);

        $this->integrationService->linkEntity(
            $client,
            $vendor,
            $request->input('entity_id'),
            $request->input('display_name'),
        );

        // Comet: create a backup user if the group has no user yet
        if ($vendor === 'comet' && CometConfig::isConfigured() && ! $client->comet_backup_user) {
            try {
                $cometClient = new \App\Services\Comet\CometClient();
                $username = \Illuminate\Support\Str::slug($client->name, '-');
                $password = \Illuminate\Support\Str::random(32);
                $created = $cometClient->ensureUser($username, $password);
                if ($created) {
                    $cometClient->setGroupUsers($request->input('entity_id'), [$username]);
                    $client->update([
                        'comet_backup_user' => $username,
                        'comet_backup_password' => $password,
                    ]);
                    $this->pushCometCredsToTactical($client);
                }
                // If not created (already exists), credentials remain unknown — user must
                // enter them manually or use "Create Backup User" with a new username
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('[Comet] Failed to create user on link: ' . $e->getMessage());
            }
        }

        return redirect()->route('clients.show', $client)
            ->with('success', "{$this->vendorLabel($vendor)} linked successfully.");
    }

    /**
     * Unlink a client from a vendor.
     */
    public function unlink(Client $client, string $vendor)
    {
        $this->resolveVendor($vendor);

        $this->integrationService->unlinkEntity($client, $vendor);

        return redirect()->route('clients.show', $client)
            ->with('success', "{$this->vendorLabel($vendor)} unlinked successfully.");
    }

    /**
     * Create a Comet User Group and backup user for a client.
     */
    public function provisionComet(Client $client)
    {
        if (! CometConfig::isConfigured()) {
            return back()->with('error', 'Comet Backup is not configured.');
        }

        if ($client->comet_group_id) {
            return back()->with('error', 'Client already has a Comet group linked.');
        }

        try {
            $cometClient = new \App\Services\Comet\CometClient();

            // Create user group named after the client
            $groupId = $cometClient->createUserGroup($client->name);

            // Create a backup user for the client
            $username = \Illuminate\Support\Str::slug($client->name, '-');
            $password = \Illuminate\Support\Str::random(32);
            $cometClient->ensureUser($username, $password);

            // Add user to the group
            $cometClient->setGroupUsers($groupId, [$username]);

            // Save the group mapping AND credentials
            $client->update([
                'comet_group_id' => $groupId,
                'comet_backup_user' => $username,
                'comet_backup_password' => $password,
            ]);

            // Push credentials to Tactical client custom fields
            $this->pushCometCredsToTactical($client);

            return back()->with('success', "Comet group \"{$client->name}\" and backup user \"{$username}\" created.");
        } catch (\Exception $e) {
            return back()->with('error', 'Comet provisioning failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a Comet backup user for a client that already has a group linked.
     */
    public function provisionCometUser(Client $client)
    {
        if (! CometConfig::isConfigured()) {
            return back()->with('error', 'Comet Backup is not configured.');
        }

        if (! $client->comet_group_id) {
            return back()->with('error', 'Client has no Comet group linked.');
        }

        if ($client->comet_backup_user) {
            return back()->with('error', 'Client already has a Comet backup user.');
        }

        try {
            $cometClient = new \App\Services\Comet\CometClient();

            $username = \Illuminate\Support\Str::slug($client->name, '-');
            $password = \Illuminate\Support\Str::random(32);
            $cometClient->ensureUser($username, $password);
            $cometClient->setGroupUsers($client->comet_group_id, [$username]);

            $client->update([
                'comet_backup_user' => $username,
                'comet_backup_password' => $password,
            ]);

            // Push credentials to Tactical client custom fields
            $this->pushCometCredsToTactical($client);

            return back()->with('success', "Backup user \"{$username}\" created and added to group.");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to create backup user: ' . $e->getMessage());
        }
    }

    /**
     * Create a Tactical RMM client + default site named after this PSA client,
     * then map the PSA client to it. Idempotent: if a Tactical client with the
     * same name already exists (e.g. from a previously-failed attempt), link
     * to its first site instead of recreating.
     */
    public function provisionTactical(\Illuminate\Http\Request $request, Client $client)
    {
        if (! \App\Support\TacticalConfig::isConfigured()) {
            return back()->with('error', 'Tactical RMM is not configured.');
        }

        if ($client->tactical_site_id) {
            return back()->with('error', 'Client already has a Tactical site linked.');
        }

        $validated = $request->validate([
            'workstation_policy_id' => ['nullable', 'integer'],
            'server_policy_id' => ['nullable', 'integer'],
        ]);

        try {
            $tacticalClient = new \App\Services\Tactical\TacticalClient();

            $existing = collect($tacticalClient->getClients())
                ->firstWhere('name', $client->name);

            if ($existing) {
                $siteName = $existing['sites'][0]['name'] ?? 'Main';
                $client->update(['tactical_site_id' => $client->name . '|' . $siteName]);

                return back()->with('success', "Linked to existing Tactical client \"{$client->name}\" (site \"{$siteName}\"). Policy is unchanged on the existing client; edit it in Tactical's UI if needed.");
            }

            $created = $tacticalClient->createClient(
                $client->name,
                'Main',
                $validated['workstation_policy_id'] ?? null,
                $validated['server_policy_id'] ?? null,
            );
            $client->update(['tactical_site_id' => $created['client_name'] . '|' . $created['site_name']]);

            return back()->with('success', "Tactical client \"{$created['client_name']}\" with site \"{$created['site_name']}\" created and linked.");
        } catch (\App\Services\Tactical\TacticalClientException $e) {
            return back()->with('error', 'Tactical provisioning failed: ' . $e->getMessage());
        }
    }

    /**
     * Push Comet backup credentials to Tactical client custom fields.
     * Tactical PUT /clients/{id}/ requires {client: {id, name}, custom_fields: [...]} at top level.
     */
    private function pushCometCredsToTactical(Client $client): void
    {
        if (!\App\Support\TacticalConfig::isConfigured() || !$client->tactical_site_id) {
            return;
        }

        try {
            $tacticalClient = new \App\Services\Tactical\TacticalClient();
            $tacticalClients = $tacticalClient->getClients();
            $parts = explode('|', $client->tactical_site_id);
            $tacticalClientName = $parts[0] ?? null;

            if (!$tacticalClientName) {
                return;
            }

            foreach ($tacticalClients as $tc) {
                if (($tc['name'] ?? '') !== $tacticalClientName) {
                    continue;
                }

                // GET the full client to preserve existing custom fields
                $fullClient = $tacticalClient->get("clients/{$tc['id']}/");

                // Build custom_fields array with existing values + our updates
                $customFields = [];
                foreach ($fullClient['custom_fields'] ?? [] as $cf) {
                    $customFields[] = ['field' => $cf['field'], 'string_value' => $cf['value'] ?? ''];
                }

                // Add/update Comet fields
                $found29 = $found30 = false;
                foreach ($customFields as &$cf) {
                    if ($cf['field'] === 29) { $cf['string_value'] = $client->comet_backup_user; $found29 = true; }
                    if ($cf['field'] === 30) { $cf['string_value'] = $client->comet_backup_password; $found30 = true; }
                }
                unset($cf);
                if (!$found29) $customFields[] = ['field' => 29, 'string_value' => $client->comet_backup_user];
                if (!$found30) $customFields[] = ['field' => 30, 'string_value' => $client->comet_backup_password];

                $tacticalClient->put("clients/{$tc['id']}/", [
                    'client' => ['id' => $tc['id'], 'name' => $tc['name']],
                    'custom_fields' => $customFields,
                ]);

                \Illuminate\Support\Facades\Log::info("[Comet] Pushed credentials to Tactical client {$tacticalClientName}");
                return;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("[Comet] Failed to push credentials to Tactical: " . $e->getMessage());
        }
    }

    /**
     * Validate vendor is in the allowlist.
     */
    private function resolveVendor(string $vendor): void
    {
        if (! in_array($vendor, ClientIntegrationService::VENDORS)) {
            abort(404, "Unknown integration vendor: {$vendor}");
        }
    }

    /**
     * Get human-readable label for a vendor.
     */
    private function vendorLabel(string $vendor): string
    {
        $labels = [
            'ninja' => 'NinjaRMM',
            'mesh' => 'Mesh',
            'cipp' => 'CIPP',
            'huntress' => 'Huntress',
            'level' => 'Level RMM',
            'controld' => 'Control D',
            'zorus' => 'Zorus',
            'servosity' => 'Servosity',
            'stripe' => 'Stripe',
            'qbo' => 'QuickBooks Online',
            'comet' => 'Comet Backup',
        ];

        return $labels[$vendor] ?? $vendor;
    }
}
