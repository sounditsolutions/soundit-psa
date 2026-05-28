<?php

namespace App\Http\Controllers\Web;

use App\Enums\AssignmentRuleType;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\ContractAssignmentRule;
use App\Models\Person;
use App\Services\ContractAssignmentService;
use Illuminate\Http\Request;

class ContractAssignmentController extends Controller
{
    public function __construct(
        private readonly ContractAssignmentService $assignmentService,
    ) {}

    // ── Asset Assignment ──

    public function assignAsset(Request $request, Contract $contract)
    {
        $request->validate(['asset_id' => ['required', 'exists:assets,id']]);
        $asset = Asset::findOrFail($request->asset_id);

        // Guard: asset must belong to the same client as the contract
        if ($asset->client_id !== $contract->client_id) {
            return back()->with('error', 'Asset does not belong to this contract\'s client.');
        }

        $this->assignmentService->assignAsset($contract, $asset);

        return back()->with('success', "Asset \"{$asset->hostname}\" assigned to contract.");
    }

    public function unassignAsset(Contract $contract, Asset $asset)
    {
        $this->assignmentService->unassignAsset($contract, $asset);

        return back()->with('success', "Asset \"{$asset->hostname}\" removed from contract.");
    }

    // ── Person Assignment ──

    public function assignPerson(Request $request, Contract $contract)
    {
        $request->validate(['person_id' => ['required', 'exists:people,id']]);
        $person = Person::findOrFail($request->person_id);

        // Guard: person must belong to the same client as the contract
        if ($person->client_id !== $contract->client_id) {
            return back()->with('error', 'Person does not belong to this contract\'s client.');
        }

        $this->assignmentService->assignPerson($contract, $person);

        return back()->with('success', "\"{$person->full_name}\" assigned to contract.");
    }

    public function unassignPerson(Contract $contract, Person $person)
    {
        $this->assignmentService->unassignPerson($contract, $person);

        return back()->with('success', "\"{$person->full_name}\" removed from contract.");
    }

    // ── Rules ──

    public function storeRule(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'rule_type' => ['required', 'string', 'in:' . implode(',', array_column(AssignmentRuleType::cases(), 'value'))],
            'filter_values' => ['nullable', 'string', 'max:500'],
        ]);

        $ruleType = AssignmentRuleType::from($validated['rule_type']);
        $name = ! empty($validated['name']) ? $validated['name'] : $ruleType->label();

        $filterValues = null;
        if (! empty($validated['filter_values'])) {
            $filterValues = array_map('trim', explode(',', $validated['filter_values']));
        }

        $rule = ContractAssignmentRule::create([
            'contract_id' => $contract->id,
            'name' => $name,
            'rule_type' => $validated['rule_type'],
            'filter_values' => $filterValues,
            'is_active' => true,
        ]);

        $msg = "Rule \"{$name}\" created.";

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $msg,
                'rule' => [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'type_label' => $rule->rule_type->label(),
                    'filter_values' => $rule->filter_values,
                    'is_active' => $rule->is_active,
                    'destroy_url' => route('rules.destroy', $rule),
                ],
                'rule_count' => $contract->assignmentRules()->count(),
            ]);
        }

        return back()->with('success', $msg);
    }

    public function destroyRule(Request $request, ContractAssignmentRule $rule)
    {
        $contractId = $rule->contract_id;
        $name = $rule->name;
        $rule->delete();

        $msg = "Rule \"{$name}\" deleted.";

        if ($request->wantsJson()) {
            $ruleCount = ContractAssignmentRule::where('contract_id', $contractId)->count();

            return response()->json([
                'message' => $msg,
                'rule_count' => $ruleCount,
            ]);
        }

        return back()->with('success', $msg);
    }

    public function evaluateRules(Request $request, Contract $contract)
    {
        $result = $this->assignmentService->evaluateRules($contract);

        $msg = "Rules evaluated: {$result['assets_added']} assets added, {$result['assets_removed']} removed; {$result['people_added']} people added, {$result['people_removed']} removed.";

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $msg,
                'assets_added' => $result['assets_added'],
                'assets_removed' => $result['assets_removed'],
                'people_added' => $result['people_added'],
                'people_removed' => $result['people_removed'],
            ]);
        }

        return back()->with('success', $msg);
    }
}
