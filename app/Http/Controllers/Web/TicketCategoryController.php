<?php

namespace App\Http\Controllers\Web;

use App\Enums\RecordTypeHint;
use App\Enums\SopStatus;
use App\Helpers\MarkdownRenderer;
use App\Http\Controllers\Controller;
use App\Models\TicketCategory;
use App\Services\Taxonomy\TicketCategoryTreeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Staff CRUD for the so-0ftg ticket-category taxonomy. Deliberately open to
 * every authenticated staff user (no RBAC) with edit-in-place as the primary
 * UX — the design goal is frictionless correction-through-use, so the show
 * page doubles as the editor and there is no separate edit screen.
 */
class TicketCategoryController extends Controller
{
    public function __construct(private readonly TicketCategoryTreeGuard $guard) {}

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $sopStatus = (string) $request->query('sop_status', '');
        $stale = (int) $request->query('stale', 0);
        $active = (string) $request->query('active', '1');

        // Any content filter switches from the structural tree to a flat,
        // path-labelled result list. The tree always shows every node (retired
        // ones dimmed) so a retired parent can never hide its active children.
        $filtering = $q !== '' || $sopStatus !== '' || $stale > 0 || $active !== '1';

        $nodes = null;
        $roots = null;

        if ($filtering) {
            $query = TicketCategory::withCount('tickets')
                ->with('parent.parent')
                ->orderBy('name');

            if ($q !== '') {
                $query->where('name', 'like', '%'.$q.'%');
            }
            if ($sopStatus !== '' && SopStatus::tryFrom($sopStatus) !== null) {
                $query->where('sop_status', $sopStatus);
            }
            if ($stale > 0) {
                $query->stale($stale);
            }
            if ($active === '1') {
                $query->active();
            } elseif ($active === '0') {
                $query->where('is_active', false);
            }

            $nodes = $query->get();
        } else {
            $roots = TicketCategory::whereNull('parent_id')
                ->withCount('tickets')
                ->with([
                    'children' => fn ($cq) => $cq->withCount('tickets'),
                    'children.children' => fn ($cq) => $cq->withCount('tickets'),
                ])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        return view('ticket-categories.index', [
            'roots' => $roots,
            'nodes' => $nodes,
            'filtering' => $filtering,
            'q' => $q,
            'sopStatus' => $sopStatus,
            'stale' => $stale,
            'active' => $active,
            'stats' => [
                'active' => TicketCategory::active()->count(),
                'gaps' => TicketCategory::active()->coverageGap()->count(),
                'stale' => TicketCategory::active()->stale(90)->count(),
            ],
        ]);
    }

    public function create(Request $request)
    {
        return view('ticket-categories.create', [
            // A new node has height 1, so only nodes above the bottom tier
            // can parent it.
            'parentOptions' => $this->parentOptions()
                ->filter(fn (array $opt) => $opt['depth'] < TicketCategoryTreeGuard::MAX_DEPTH),
            'preselectedParent' => (int) $request->query('parent', 0),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:ticket_categories,id'],
            'description' => ['nullable', 'string', 'max:10000'],
            'sop_text' => ['nullable', 'string', 'max:200000'],
            'sop_status' => ['nullable', Rule::enum(SopStatus::class)],
            'record_type_hint' => ['nullable', Rule::enum(RecordTypeHint::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $parent = ! empty($validated['parent_id'])
            ? TicketCategory::find($validated['parent_id'])
            : null;

        if ($error = $this->guard->attachmentError(null, $parent)) {
            return back()->withInput()->withErrors(['parent_id' => $error]);
        }

        $validated['parent_id'] = $parent?->id;
        $validated['sop_status'] = $validated['sop_status'] ?? SopStatus::None->value;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['updated_by'] = $request->user()->id;

        $node = TicketCategory::create($validated);

        return redirect()->route('ticket-categories.show', $node)
            ->with('success', "Category \"{$node->name}\" created.");
    }

    public function show(TicketCategory $ticketCategory)
    {
        $ticketCategory->load([
            'parent',
            'editor',
            'children' => fn ($cq) => $cq->withCount('tickets'),
        ]);
        $ticketCategory->loadCount('tickets');

        return view('ticket-categories.show', [
            'node' => $ticketCategory,
            'descriptionHtml' => MarkdownRenderer::render($ticketCategory->description),
            'sopHtml' => MarkdownRenderer::render($ticketCategory->sop_text),
            // Legal re-parent targets only, so the dropdown cannot offer a
            // move the guard would refuse (self, descendants, depth overflow).
            'parentOptions' => $this->parentOptions()
                ->filter(fn (array $opt) => $this->guard->attachmentError($ticketCategory, $opt['node']) === null),
            'canHaveChildren' => $ticketCategory->depth() < TicketCategoryTreeGuard::MAX_DEPTH,
        ]);
    }

    public function edit(TicketCategory $ticketCategory)
    {
        // Edit-in-place lives on the show page; there is no separate editor.
        return redirect()->route('ticket-categories.show', $ticketCategory);
    }

    public function update(Request $request, TicketCategory $ticketCategory)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:ticket_categories,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'sop_text' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'sop_status' => ['sometimes', Rule::enum(SopStatus::class)],
            'record_type_hint' => ['sometimes', 'nullable', Rule::enum(RecordTypeHint::class)],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'source_runbook_slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('parent_id', $validated)) {
            $parent = ! empty($validated['parent_id'])
                ? TicketCategory::find($validated['parent_id'])
                : null;

            if ($parent?->id !== $ticketCategory->parent_id) {
                if ($error = $this->guard->attachmentError($ticketCategory, $parent)) {
                    return redirect()->route('ticket-categories.show', $ticketCategory)
                        ->withInput()->withErrors(['parent_id' => $error]);
                }
            }

            $validated['parent_id'] = $parent?->id;
        }

        if (array_key_exists('sort_order', $validated)) {
            $validated['sort_order'] = $validated['sort_order'] ?? 0;
        }

        $validated['updated_by'] = $request->user()->id;
        $ticketCategory->update($validated);

        return redirect()->route('ticket-categories.show', $ticketCategory)
            ->with('success', 'Category updated.');
    }

    /**
     * Every node flattened depth-first with its path label and depth, for
     * parent <select>s. Retired nodes are included (labelled by the view) —
     * structure and retirement are independent concerns.
     *
     * @return Collection<int, array{node: TicketCategory, label: string, depth: int}>
     */
    private function parentOptions(): Collection
    {
        $roots = TicketCategory::whereNull('parent_id')
            ->with('children.children')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $flat = collect();
        $walk = function ($nodes, string $prefix, int $depth) use (&$walk, $flat) {
            foreach ($nodes as $node) {
                $flat->push(['node' => $node, 'label' => $prefix.$node->name, 'depth' => $depth]);
                $walk($node->children, $prefix.$node->name.' / ', $depth + 1);
            }
        };
        $walk($roots, '', 1);

        return $flat;
    }
}
