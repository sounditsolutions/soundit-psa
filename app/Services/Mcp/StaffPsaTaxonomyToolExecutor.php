<?php

namespace App\Services\Mcp;

use App\Enums\RecordTypeHint;
use App\Enums\SopStatus;
use App\Enums\TechnicianTier;
use App\Models\TechnicianActionLog;
use App\Models\TicketCategory;
use App\Services\Taxonomy\TicketCategoryTreeGuard;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Chet's MCP CRUD surface for the so-0ftg ticket taxonomy (Category >
 * Subcategory > Item, depth <= 3) — the secondary authoring path beside the
 * human CRUD/list/filter UI. Grant-gated (`taxonomy` group), dormant until a
 * token is granted a tool by name, dispatched by McpStaffController.
 *
 * The taxonomy is GLOBAL: no tool here accepts a client_id (the controller
 * rejects a supplied one rather than silently ignoring it — a caller that
 * believes a listing was client-scoped has been misled, which is worse than
 * an error).
 *
 * SOP content rules follow the Charlie-locked design: sop_status is a SOFT
 * HINT that never gates serving; every default below keeps the coverage-gap
 * view truthful (status none <=> "no procedure authored yet") without adding
 * review friction. Structure edits (rename/reparent/reactivate) live on
 * update_ticket_category; SOP content lives on set_ticket_category_sop;
 * deactivation requires the typed-confirm retire_ticket_category.
 *
 * Tree shape (depth <= 3, acyclicity, no retired attach targets, no
 * reactivation under a retired parent) is decided by the shared
 * TicketCategoryTreeGuard — the ONE write guard both doors (this MCP
 * surface and the staff web UI) consult, per the tree-policy ruling
 * (psa-m4bki). Do not reintroduce a local reimplementation here:
 * divergent tree rules across the two write doors are a mis-write vector.
 */
class StaffPsaTaxonomyToolExecutor
{
    /** Every taxonomy tool — the single source for definitions(), handles(), and dispatch. */
    public const TOOLS = [
        'list_ticket_categories',
        'get_ticket_category',
        'create_ticket_category',
        'update_ticket_category',
        'retire_ticket_category',
        'set_ticket_category_sop',
    ];

    private const LIST_DEFAULT_LIMIT = 300;

    private const LIST_MAX_LIMIT = 500;

    public function __construct(private readonly TicketCategoryTreeGuard $treeGuard) {}

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::TOOLS, true);
    }

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'list_ticket_categories',
                'description' => 'List ticket taxonomy nodes (the ITIL-informed Category > Subcategory > Item tree, depth <= 3) as flat rows: id, parent_id, depth, path, sop_status, record_type_hint, has_sop, is_active, sort_order, updated_at. Never returns the SOP text itself — use get_ticket_category for the full markdown. Active nodes only by default. Filter by name search, sop_status (none = the coverage-gap view), parent_id (direct children), staleness, or include retired nodes. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string', 'description' => 'Case-insensitive name fragment to filter by.'],
                        'parent_id' => ['type' => 'integer', 'description' => 'Only direct children of this node. Omit for all nodes.'],
                        'sop_status' => ['type' => 'string', 'enum' => ['none', 'draft', 'reviewed'], 'description' => 'Filter by SOP authoring status. none = nodes with no procedure yet (coverage gaps).'],
                        'stale_days' => ['type' => 'integer', 'description' => 'Only nodes not updated in the last N days (staleness review).'],
                        'include_inactive' => ['type' => 'boolean', 'description' => 'Include retired nodes. Defaults to false.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max rows (default 300, cap 500). The response reports total and truncated so a cut-off list is never mistaken for the whole tree.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_ticket_category',
                'description' => 'Get one taxonomy node in full: ancestry path, description, the FULL SOP markdown (sop_text), sop_status (a soft hint that never gates serving), record_type_hint, active/leaf flags, provenance (source_runbook_slug), linked ticket count, last editor and updated_at, plus a summary of its children. Pass the returned updated_at back as expected_updated_at when editing the SOP. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'The taxonomy node ID to read.'],
                    ],
                    'required' => ['category_id'],
                ],
            ],
            [
                'name' => 'create_ticket_category',
                'description' => 'Create a new ticket taxonomy node immediately. The taxonomy is global (no client scope). The server enforces tree depth <= 3 (Category > Subcategory > Item), requires a given parent to exist and be active, refuses a duplicate sibling name, records the AI actor as the editor, and writes an action audit row. SOP text may be authored at create time: sop_status defaults to draft when text is present and none otherwise, and draft/reviewed without text is refused. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Node name, unique among its siblings.'],
                        'parent_id' => ['type' => 'integer', 'description' => 'Optional parent node ID. Omit to create a top-level category. The parent must be active and at depth <= 2.'],
                        'description' => ['type' => 'string', 'description' => 'Optional description of what belongs under this node.'],
                        'record_type_hint' => ['type' => 'string', 'enum' => ['incident', 'request', 'mixed'], 'description' => 'Advisory ITIL hint for how work under this node tends to behave. Never constrains a ticket\'s own type.'],
                        'sort_order' => ['type' => 'integer', 'description' => 'Optional ordering among siblings (lower sorts first, default 0).'],
                        'sop_text' => ['type' => 'string', 'description' => 'Optional full SOP markdown to author with the node.'],
                        'sop_status' => ['type' => 'string', 'enum' => ['none', 'draft', 'reviewed'], 'description' => 'Optional authoring-status hint. draft/reviewed require sop_text. Defaults to draft when sop_text is present, none otherwise.'],
                        'source_runbook_slug' => ['type' => 'string', 'description' => 'Optional provenance slug of a migrated wiki runbook.'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'update_ticket_category',
                'description' => 'Update a taxonomy node\'s structure or metadata immediately: rename, reparent (revalidating depth <= 3 for the node and its whole subtree, refusing cycles and retired parents), description, record_type_hint, sort_order, or reactivate a retired node with is_active=true (refused while the node\'s own parent is retired — reactivate the parent first, or reparent this node). Deactivation goes through retire_ticket_category; SOP text and status go through set_ticket_category_sop. Records the AI actor as the editor and writes an action audit row. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'The taxonomy node ID to update.'],
                        'name' => ['type' => 'string', 'description' => 'Optional replacement name, unique among its siblings.'],
                        'parent_id' => ['type' => ['integer', 'null'], 'description' => 'Optional new parent node ID, or null to make this a top-level category. Depth <= 3 is revalidated for the whole subtree.'],
                        'description' => ['type' => 'string', 'description' => 'Optional replacement description.'],
                        'record_type_hint' => ['type' => 'string', 'enum' => ['incident', 'request', 'mixed'], 'description' => 'Optional replacement ITIL hint.'],
                        'sort_order' => ['type' => 'integer', 'description' => 'Optional replacement sibling ordering.'],
                        'is_active' => ['type' => 'boolean', 'description' => 'Only true is accepted, to reactivate a retired node (refused while its parent is retired); deactivation requires retire_ticket_category.'],
                    ],
                    'required' => ['category_id'],
                ],
            ],
            [
                'name' => 'retire_ticket_category',
                'description' => 'Retire (deactivate) a taxonomy node immediately — it disappears from active listings and pickers while its history and any tickets pointing at it stay intact. The server requires a typed confirmation of the exact node name and refuses while the node still has active children (retire or move those first). Reactivate later via update_ticket_category is_active=true. Writes an action audit row. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'The taxonomy node ID to retire.'],
                        'confirm_category_name' => ['type' => 'string', 'description' => 'Typed confirmation — must exactly match the target node name.'],
                        'reason' => ['type' => 'string', 'description' => 'Optional reason for retiring the node, recorded in the audit log.'],
                    ],
                    'required' => ['category_id', 'confirm_category_name'],
                ],
            ],
            [
                'name' => 'set_ticket_category_sop',
                'description' => 'Author or update the SOP served inline for a taxonomy node immediately: replace the full markdown sop_text and/or set sop_status (none | draft | reviewed — a SOFT HINT that never gates serving). Defaults keep the coverage-gap view truthful: writing text onto a status-none node marks it draft unless you say otherwise, clearing the text (empty string) resets the status to none, and draft/reviewed with no text is refused. Pass expected_updated_at (the updated_at you last read) to reject a concurrent-edit overwrite. Records the AI actor as the editor and writes an action audit row; the SOP body is never logged. Requires an explicit token grant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'The taxonomy node ID to author the SOP for.'],
                        'sop_text' => ['type' => 'string', 'description' => 'Full replacement SOP markdown. Pass an empty string to clear the SOP (resets sop_status to none).'],
                        'sop_status' => ['type' => 'string', 'enum' => ['none', 'draft', 'reviewed'], 'description' => 'Optional authoring-status hint. draft/reviewed require the node to end up with SOP text.'],
                        'expected_updated_at' => ['type' => 'string', 'description' => 'Optional ISO-8601 updated_at you last read for this node. If the node changed since, the write is rejected as a concurrent edit.'],
                    ],
                    'required' => ['category_id'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, string $actorLabel): array
    {
        return match ($name) {
            'list_ticket_categories' => $this->listTicketCategories($arguments),
            'get_ticket_category' => $this->getTicketCategory($arguments),
            'create_ticket_category' => $this->createTicketCategory($arguments, $actorLabel),
            'update_ticket_category' => $this->updateTicketCategory($arguments, $actorLabel),
            'retire_ticket_category' => $this->retireTicketCategory($arguments, $actorLabel),
            'set_ticket_category_sop' => $this->setTicketCategorySop($arguments, $actorLabel),
            default => ['error' => "Unknown taxonomy tool: {$name}"],
        };
    }

    /** @return array<string, mixed> */
    private function listTicketCategories(array $arguments): array
    {
        $allowed = ['search', 'parent_id', 'sop_status', 'stale_days', 'include_inactive', 'limit'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'list_ticket_categories accepts only: '.implode(', ', $allowed).'.'];
        }

        $validator = Validator::make($arguments, [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'parent_id' => ['sometimes', 'integer', 'min:1'],
            'sop_status' => ['sometimes', Rule::enum(SopStatus::class)],
            'stale_days' => ['sometimes', 'integer', 'min:1'],
            'include_inactive' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer'],
        ]);
        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }
        $validated = $validator->validated();

        // parent.parent covers every ancestor at depth <= 3, so depth()/pathString()
        // walk loaded relations instead of lazy-loading one query per row.
        $query = TicketCategory::query()->with('parent.parent');

        if (! (bool) ($validated['include_inactive'] ?? false)) {
            $query->active();
        }
        if (($search = trim((string) ($validated['search'] ?? ''))) !== '') {
            $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%');
        }
        if (array_key_exists('parent_id', $validated)) {
            $query->where('parent_id', (int) $validated['parent_id']);
        }
        if (array_key_exists('sop_status', $validated)) {
            $query->where('sop_status', SopStatus::from((string) $validated['sop_status'])->value);
        }
        if (array_key_exists('stale_days', $validated)) {
            $query->stale((int) $validated['stale_days']);
        }

        $total = (clone $query)->count();
        $limit = min(max((int) ($validated['limit'] ?? self::LIST_DEFAULT_LIMIT), 1), self::LIST_MAX_LIMIT);

        $categories = $query
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (TicketCategory $node): array => [
                'id' => $node->id,
                'name' => $node->name,
                'parent_id' => $node->parent_id,
                'depth' => $node->depth(),
                'path' => $node->pathString(),
                'record_type_hint' => $node->record_type_hint?->value,
                'sop_status' => $node->sop_status->value,
                'has_sop' => $node->hasSop(),
                'is_active' => (bool) $node->is_active,
                'sort_order' => (int) $node->sort_order,
                'updated_at' => $node->updated_at?->toISOString(),
            ])
            ->all();

        return [
            'categories' => $categories,
            'count' => count($categories),
            'total' => $total,
            'truncated' => $total > count($categories),
        ];
    }

    /** @return array<string, mixed> */
    private function getTicketCategory(array $arguments): array
    {
        $category = $this->categoryFor($arguments);
        if (is_array($category)) {
            return $category;
        }

        $category->load(['parent.parent', 'children', 'editor']);

        return [
            'id' => $category->id,
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'depth' => $category->depth(),
            'path' => $category->pathString(),
            'ancestors' => $category->ancestors()
                ->map(fn (TicketCategory $node): array => ['id' => $node->id, 'name' => $node->name])
                ->values()
                ->all(),
            'description' => $category->description,
            'sop_text' => $category->sop_text,
            'sop_status' => $category->sop_status->value,
            'record_type_hint' => $category->record_type_hint?->value,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'is_leaf' => $category->isLeaf(),
            'has_sop' => $category->hasSop(),
            'source_runbook_slug' => $category->source_runbook_slug,
            'tickets_count' => $category->tickets()->count(),
            'updated_at' => $category->updated_at?->toISOString(),
            'updated_by' => $category->editor?->name,
            'children' => $category->children
                ->map(fn (TicketCategory $child): array => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'sop_status' => $child->sop_status->value,
                    'has_sop' => $child->hasSop(),
                    'is_active' => (bool) $child->is_active,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function createTicketCategory(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $allowed = ['name', 'parent_id', 'description', 'record_type_hint', 'sort_order', 'sop_text', 'sop_status', 'source_runbook_slug'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'create_ticket_category accepts only: '.implode(', ', $allowed).'.'];
        }

        $validator = Validator::make($arguments, [
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:ticket_categories,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'record_type_hint' => ['sometimes', 'nullable', Rule::enum(RecordTypeHint::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'sop_text' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'sop_status' => ['sometimes', 'nullable', Rule::enum(SopStatus::class)],
            'source_runbook_slug' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }
        $validated = $validator->validated();

        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        if ($parentId !== null) {
            $parent = TicketCategory::find($parentId);
            if (! $parent) {
                return ['error' => 'Parent category not found'];
            }
            if ($guardError = $this->treeGuard->attachmentError(null, $parent)) {
                return ['error' => $guardError];
            }
        }

        $name = trim((string) $validated['name']);
        if ($duplicateError = $this->duplicateSiblingNameError($name, $parentId)) {
            return $duplicateError;
        }

        $sopText = $this->trimmedOrNull($validated['sop_text'] ?? null);
        $sopStatus = isset($validated['sop_status']) && $validated['sop_status'] !== null
            ? SopStatus::from((string) $validated['sop_status'])
            : null;
        if ($statusError = $this->sopStatusRequiresTextError($sopStatus, $sopText)) {
            return $statusError;
        }
        // Coverage-gap truth: text with no explicit status is a draft, no text is none.
        $sopStatus ??= ($sopText !== null ? SopStatus::Draft : SopStatus::None);

        $category = DB::transaction(function () use ($validated, $parentId, $name, $sopText, $sopStatus, $actorLabel): TicketCategory {
            $category = TicketCategory::create([
                'name' => $name,
                'parent_id' => $parentId,
                'description' => $this->trimmedOrNull($validated['description'] ?? null),
                'record_type_hint' => $validated['record_type_hint'] ?? null,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
                'sop_text' => $sopText,
                'sop_status' => $sopStatus,
                'source_runbook_slug' => $this->trimmedOrNull($validated['source_runbook_slug'] ?? null),
                'updated_by' => TechnicianConfig::requiredAiActorUserId(),
            ]);

            $this->auditTaxonomyExecution(
                'create_ticket_category',
                (int) $category->id,
                $actorLabel,
                $this->taxonomyContentHash('create_ticket_category', null, [
                    'name' => $name,
                    'parent_id' => $parentId,
                    'sop_text_length' => $sopText !== null ? mb_strlen($sopText) : 0,
                    'sop_status' => $sopStatus->value,
                ]),
                'Category created: '.$category->pathString().' (sop_status '.$sopStatus->value.').',
            );

            return $category;
        });

        return [
            'success' => true,
            'category_id' => $category->id,
            'name' => $category->name,
            'path' => $category->pathString(),
            'depth' => $category->depth(),
            'sop_status' => $category->sop_status->value,
            'message' => 'Ticket category created.',
        ];
    }

    /** @return array<string, mixed> */
    private function updateTicketCategory(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $category = $this->categoryFor($arguments);
        if (is_array($category)) {
            return $category;
        }

        if (array_key_exists('sop_text', $arguments) || array_key_exists('sop_status', $arguments)) {
            return ['error' => 'SOP text and status are managed by set_ticket_category_sop, not update_ticket_category.'];
        }

        $allowed = ['category_id', 'name', 'parent_id', 'description', 'record_type_hint', 'sort_order', 'is_active'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'update_ticket_category accepts only: '.implode(', ', $allowed).'.'];
        }

        $validator = Validator::make($arguments, [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:ticket_categories,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'record_type_hint' => ['sometimes', 'nullable', Rule::enum(RecordTypeHint::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }
        $validated = $validator->validated();
        unset($validated['category_id']);
        if ($validated === []) {
            return ['error' => 'update_ticket_category requires at least one editable field.'];
        }

        if (array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            return ['error' => 'Deactivation goes through retire_ticket_category (typed confirmation), not update_ticket_category.'];
        }

        // A retired node may not come back to life under a retired parent
        // (psa-9mirx ruling) — the shared guard owns the rule. Only a genuine
        // revival is checked: a redundant is_active=true on an already-active
        // node is soft-retirement's legal state, not a reactivation.
        if ((bool) ($validated['is_active'] ?? false) && ! $category->is_active) {
            if ($reactivationError = $this->treeGuard->reactivationError($category)) {
                return ['error' => $reactivationError];
            }
        }

        $currentParentId = $category->parent_id !== null ? (int) $category->parent_id : null;
        $reparenting = array_key_exists('parent_id', $validated);
        $newParentId = $reparenting
            ? ($validated['parent_id'] !== null ? (int) $validated['parent_id'] : null)
            : $currentParentId;
        if ($reparenting && $newParentId !== $currentParentId) {
            if ($reparentError = $this->reparentError($category, $newParentId)) {
                return $reparentError;
            }
        }

        $newName = array_key_exists('name', $validated) ? trim((string) $validated['name']) : $category->name;
        if (($newName !== $category->name || $newParentId !== $currentParentId)
            && ($duplicateError = $this->duplicateSiblingNameError($newName, $newParentId, excludeId: (int) $category->id))) {
            return $duplicateError;
        }

        $before = [
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'description' => $category->description,
            'record_type_hint' => $category->record_type_hint?->value,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
        ];

        $payload = $validated;
        if (array_key_exists('name', $payload)) {
            $payload['name'] = $newName;
        }
        if (array_key_exists('description', $payload)) {
            $payload['description'] = $this->trimmedOrNull($payload['description']);
        }
        $payload['updated_by'] = TechnicianConfig::requiredAiActorUserId();

        $category = DB::transaction(function () use ($category, $payload, $actorLabel, $before): TicketCategory {
            $category->update($payload);
            $category->refresh();

            $after = [
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'description' => $category->description,
                'record_type_hint' => $category->record_type_hint?->value,
                'sort_order' => (int) $category->sort_order,
                'is_active' => (bool) $category->is_active,
            ];
            $diff = $this->fieldDiff($before, $after);

            $this->auditTaxonomyExecution(
                'update_ticket_category',
                (int) $category->id,
                $actorLabel,
                $this->taxonomyContentHash('update_ticket_category', (int) $category->id, $diff),
                'Category updated: '.$category->pathString().($diff !== [] ? '. Changes: '.$this->stringifyDiff($diff) : '').'.',
            );

            return $category;
        });

        return [
            'success' => true,
            'category_id' => $category->id,
            'name' => $category->name,
            'path' => $category->pathString(),
            'depth' => $category->depth(),
            'is_active' => (bool) $category->is_active,
            'message' => 'Ticket category updated.',
        ];
    }

    /** @return array<string, mixed> */
    private function retireTicketCategory(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $category = $this->categoryFor($arguments);
        if (is_array($category)) {
            return $category;
        }

        $allowed = ['category_id', 'confirm_category_name', 'reason'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'retire_ticket_category accepts only: '.implode(', ', $allowed).'.'];
        }

        if (! $category->is_active) {
            return ['error' => 'This category is already retired.'];
        }

        $confirm = $this->optionalString($arguments, 'confirm_category_name');
        if ($confirm === null || strcasecmp(trim($confirm), (string) $category->name) !== 0) {
            return ['error' => 'The typed confirm_category_name does not match the target category. Retirement cancelled.'];
        }

        $activeChildren = $category->children()->active()->count();
        if ($activeChildren > 0) {
            return ['error' => 'This category still has '.$activeChildren.' active child '.($activeChildren === 1 ? 'node' : 'nodes').'. Retire or move them first.'];
        }

        $reason = $this->optionalString($arguments, 'reason');

        DB::transaction(function () use ($category, $actorLabel, $reason): void {
            $category->update([
                'is_active' => false,
                'updated_by' => TechnicianConfig::requiredAiActorUserId(),
            ]);

            $this->auditTaxonomyExecution(
                'retire_ticket_category',
                (int) $category->id,
                $actorLabel,
                $this->taxonomyContentHash('retire_ticket_category', (int) $category->id, ['name' => $category->name], $reason),
                'Category retired: '.$category->pathString().($reason ? ' — '.$reason : '').'.',
            );
        });

        return [
            'success' => true,
            'category_id' => $category->id,
            'name' => $category->name,
            'tickets_count' => $category->tickets()->count(),
            'message' => 'Ticket category retired. Existing tickets keep pointing at it.',
        ];
    }

    /** @return array<string, mixed> */
    private function setTicketCategorySop(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) {
            return $error;
        }

        $category = $this->categoryFor($arguments);
        if (is_array($category)) {
            return $category;
        }

        $allowed = ['category_id', 'sop_text', 'sop_status', 'expected_updated_at'];
        $unexpected = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unexpected !== []) {
            return ['error' => 'set_ticket_category_sop accepts only: '.implode(', ', $allowed).'.'];
        }

        $hasText = array_key_exists('sop_text', $arguments);
        $hasStatus = array_key_exists('sop_status', $arguments);
        if (! $hasText && ! $hasStatus) {
            return ['error' => 'set_ticket_category_sop requires sop_text and/or sop_status.'];
        }

        if ($hasText && $arguments['sop_text'] !== null && ! is_string($arguments['sop_text'])) {
            return ['error' => 'sop_text must be a string or null.'];
        }
        if ($hasText && is_string($arguments['sop_text']) && mb_strlen($arguments['sop_text']) > 200000) {
            return ['error' => 'sop_text must not exceed 200000 characters.'];
        }
        $status = null;
        if ($hasStatus) {
            $status = is_string($arguments['sop_status']) ? SopStatus::tryFrom($arguments['sop_status']) : null;
            if ($status === null) {
                return ['error' => 'sop_status must be one of: none, draft, reviewed.'];
            }
        }

        $expectedUpdatedAt = $this->optionalString($arguments, 'expected_updated_at');
        if ($expectedUpdatedAt !== null
            && Validator::make(['expected_updated_at' => $expectedUpdatedAt], ['expected_updated_at' => ['date']])->fails()) {
            return ['error' => 'expected_updated_at must be a valid ISO-8601 timestamp.'];
        }
        if ($expectedUpdatedAt !== null && $category->updated_at !== null
            && ! \Carbon\Carbon::parse($expectedUpdatedAt)->equalTo($category->updated_at)) {
            $editor = $category->editor?->name ?? 'someone';

            return ['error' => 'This category was updated by '.$editor.' '.$category->updated_at->diffForHumans().' while you were editing. Re-read it with get_ticket_category and retry.'];
        }

        $newText = $hasText ? $this->trimmedOrNull($arguments['sop_text']) : $category->sop_text;
        if ($statusError = $this->sopStatusRequiresTextError($status, $newText)) {
            return $statusError;
        }

        // Coverage-gap truth when no explicit status is given: cleared text is a
        // gap again (none); fresh text on a gap node is a draft; an existing
        // draft/reviewed hint survives an in-place text correction.
        $newStatus = $status
            ?? ($newText === null
                ? SopStatus::None
                : ($category->sop_status === SopStatus::None ? SopStatus::Draft : $category->sop_status));

        $statusBefore = $category->sop_status->value;
        $textLength = $newText !== null ? mb_strlen($newText) : 0;

        $category = DB::transaction(function () use ($category, $newText, $newStatus, $actorLabel, $statusBefore, $textLength, $hasText, $expectedUpdatedAt): TicketCategory {
            $category->update([
                'sop_text' => $newText,
                'sop_status' => $newStatus,
                'updated_by' => TechnicianConfig::requiredAiActorUserId(),
            ]);
            $category->refresh();

            $summary = $hasText
                ? 'SOP text set ('.$textLength.' chars), status '.$statusBefore.' -> '.$newStatus->value.'.'
                : 'SOP status set '.$statusBefore.' -> '.$newStatus->value.'.';
            $this->auditTaxonomyExecution(
                'set_ticket_category_sop',
                (int) $category->id,
                $actorLabel,
                $this->taxonomyContentHash('set_ticket_category_sop', (int) $category->id, [
                    'sop_text_length' => $textLength,
                    'sop_status' => $newStatus->value,
                ], $expectedUpdatedAt),
                'Category '.$category->pathString().': '.$summary,
            );

            return $category;
        });

        return [
            'success' => true,
            'category_id' => $category->id,
            'name' => $category->name,
            'sop_status' => $category->sop_status->value,
            'has_sop' => $category->hasSop(),
            'updated_at' => $category->updated_at?->toISOString(),
            'message' => 'Category SOP updated.',
        ];
    }

    // ── shared guards & helpers ──────────────────────────────────────────────

    /** @return array<string, string>|null */
    private function guardDirectAction(): ?array
    {
        if (TechnicianConfig::killSwitchEngaged()) {
            return ['error' => 'Technician kill-switch engaged; direct client-facing action refused'];
        }

        return null;
    }

    /** @return TicketCategory|array<string, string> */
    private function categoryFor(array $arguments): TicketCategory|array
    {
        $categoryId = $this->positiveInteger($arguments['category_id'] ?? null);
        if ($categoryId === null) {
            return ['error' => 'category_id is required'];
        }

        $category = TicketCategory::find($categoryId);
        if (! $category) {
            return ['error' => 'Ticket category not found'];
        }

        return $category;
    }

    /**
     * Refuse a reparent that would break the tree. Existence is checked here
     * (the guard takes a resolved model); the shape rules — self-parent,
     * retired target, descendant cycle, subtree depth — are the shared
     * TicketCategoryTreeGuard's, verbatim on both write doors.
     *
     * @return array<string, string>|null
     */
    private function reparentError(TicketCategory $category, ?int $newParentId): ?array
    {
        if ($newParentId === null) {
            return null;
        }

        $parent = TicketCategory::find($newParentId);
        if (! $parent) {
            return ['error' => 'Parent category not found'];
        }

        if ($guardError = $this->treeGuard->attachmentError($category, $parent)) {
            return ['error' => $guardError];
        }

        return null;
    }

    /**
     * Sibling names are unique case-insensitively — the taxonomy's nodes are
     * mutually exclusive, and a same-named retired sibling should be
     * reactivated, not shadowed by a duplicate.
     *
     * @return array<string, string>|null
     */
    private function duplicateSiblingNameError(string $name, ?int $parentId, ?int $excludeId = null): ?array
    {
        $sibling = TicketCategory::query()
            ->where('parent_id', $parentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->first();

        if ($sibling === null) {
            return null;
        }

        return $sibling->is_active
            ? ['error' => 'A category named "'.$sibling->name.'" already exists under the same parent.']
            : ['error' => 'A retired category named "'.$sibling->name.'" (id '.$sibling->id.') already exists under the same parent. Reactivate it via update_ticket_category is_active=true instead of creating a duplicate.'];
    }

    /** @return array<string, string>|null */
    private function sopStatusRequiresTextError(?SopStatus $status, ?string $sopText): ?array
    {
        if ($status !== null && $status !== SopStatus::None && $sopText === null) {
            return ['error' => 'sop_status '.$status->value.' requires SOP text — a status hint on an empty SOP would hide a coverage gap.'];
        }

        return null;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function optionalString(array $arguments, string $key): ?string
    {
        if (! array_key_exists($key, $arguments) || ! is_scalar($arguments[$key])) {
            return null;
        }

        $value = trim((string) $arguments[$key]);

        return $value !== '' ? $value : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function fieldDiff(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $diff[$field] = ['before' => $before[$field] ?? null, 'after' => $value];
            }
        }

        return $diff;
    }

    /** @param array<string, array{before: mixed, after: mixed}> $diff */
    private function stringifyDiff(array $diff): string
    {
        $parts = [];
        foreach ($diff as $field => $change) {
            $parts[] = $field.': '.json_encode($change['before']).' -> '.json_encode($change['after']);
        }

        return implode('; ', $parts);
    }

    /** @param array<string, mixed>|string|null $payload */
    private function taxonomyContentHash(string $actionType, ?int $categoryId, mixed $payload, ?string $reason = null): string
    {
        return hash('sha256', json_encode([
            'action' => $actionType,
            'category_id' => $categoryId,
            'payload' => $payload,
            'reason' => $reason,
        ]));
    }

    /**
     * Append-only audit for a taxonomy mutation. Mirrors StaffPsaActionToolExecutor::
     * auditEntityExecution: ticket_id and client_id stay null (the taxonomy is
     * global) and the entity id rides in the summary tag, since
     * technician_action_logs has no entity_type/entity_id columns (psa-wsje).
     */
    private function auditTaxonomyExecution(string $actionType, ?int $categoryId, string $actorLabel, string $contentHash, string $summary): void
    {
        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::requiredAiActorUserId(),
            'approver_user_id' => null,
            'actor_label' => $actorLabel,
            'action_type' => $actionType,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => null,
            'run_id' => null,
            'content_hash' => $contentHash,
            'summary' => mb_substr('[ticket_category'.($categoryId !== null ? '#'.$categoryId : '').'] '.$summary, 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
