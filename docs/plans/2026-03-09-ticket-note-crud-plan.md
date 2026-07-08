# Ticket Note Edit & Delete Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow staff to edit and soft-delete ticket notes, with automatic prepay debit correction and edit tracking.

**Architecture:** Add SoftDeletes + edit tracking columns to `ticket_notes`. Add `update()` and `destroy()` methods to `TicketNoteController`. Modify the timeline Blade to show edit/delete buttons, an edit modal, and deleted note placeholders. The existing `TicketNoteObserver` already handles prepay re-sync on update and reversal on delete — no observer changes needed.

**Tech Stack:** Laravel 12, Blade, Bootstrap 5.3 CDN, MariaDB

---

### Task 1: Migration — Add soft-delete and edit-tracking columns

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_soft_deletes_and_edit_tracking_to_ticket_notes.php`

**Step 1: Create migration**

```bash
php artisan make:migration add_soft_deletes_and_edit_tracking_to_ticket_notes --table=ticket_notes
```

**Step 2: Write migration**

```php
public function up(): void
{
    Schema::table('ticket_notes', function (Blueprint $table) {
        $table->softDeletes();
        $table->timestamp('edited_at')->nullable()->after('noted_at');
        $table->foreignId('edited_by')->nullable()->after('edited_at')
            ->constrained('users')->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('ticket_notes', function (Blueprint $table) {
        $table->dropSoftDeletes();
        $table->dropForeign(['edited_by']);
        $table->dropColumn(['edited_at', 'edited_by']);
    });
}
```

**Step 3: Run migration**

```bash
php artisan migrate
```

**Step 4: Commit**

```bash
git add database/migrations/*soft_deletes*ticket_notes*
git commit -m "Add soft deletes and edit tracking columns to ticket_notes"
```

---

### Task 2: Update TicketNote model

**Files:**
- Modify: `app/Models/TicketNote.php`

**Step 1: Add SoftDeletes trait**

Add `use Illuminate\Database\Eloquent\SoftDeletes;` to the imports (after the existing `use` statements at line 8-10).

Add `use SoftDeletes;` inside the class (after `class TicketNote extends Model` on line 15, before `$fillable`).

**Step 2: Add new fields to $fillable**

Add after `'noted_at'` (line 31):
```php
'edited_at',
'edited_by',
```

**Step 3: Add new casts**

Add after `'noted_at' => 'datetime'` (line 44):
```php
'edited_at' => 'datetime',
```

**Step 4: Add editor relation**

Add after the existing `email()` relation (after line 63):
```php
public function editor(): BelongsTo
{
    return $this->belongsTo(User::class, 'edited_by');
}
```

**Step 5: Commit**

```bash
git add app/Models/TicketNote.php
git commit -m "Add SoftDeletes, edit tracking fields, and editor relation to TicketNote"
```

---

### Task 3: Update Ticket model to include trashed notes in timeline

**Files:**
- Modify: `app/Models/Ticket.php`

**Step 1: Update notes() relationship**

In `app/Models/Ticket.php`, the `notes()` relationship at line 131-134 currently returns:
```php
return $this->hasMany(TicketNote::class)->orderByDesc('noted_at');
```

Change to include soft-deleted notes:
```php
return $this->hasMany(TicketNote::class)->withTrashed()->orderByDesc('noted_at');
```

Soft-deleted notes must appear in the timeline (rendered as "Note deleted" placeholders). Without `withTrashed()`, they would be invisible.

**Step 2: Commit**

```bash
git add app/Models/Ticket.php
git commit -m "Include soft-deleted notes in ticket timeline"
```

---

### Task 4: Add update and destroy controller methods

**Files:**
- Modify: `app/Http/Controllers/Web/TicketNoteController.php`
- Modify: `routes/web.php`

**Step 1: Add routes**

In `routes/web.php`, find line 154:
```php
Route::post('/tickets/{ticket}/notes', [TicketNoteController::class, 'store'])->name('tickets.notes.store');
```

Add after it:
```php
Route::put('/tickets/{ticket}/notes/{note}', [TicketNoteController::class, 'update'])->name('tickets.notes.update');
Route::delete('/tickets/{ticket}/notes/{note}', [TicketNoteController::class, 'destroy'])->name('tickets.notes.destroy');
```

**Step 2: Add update() method**

In `app/Http/Controllers/Web/TicketNoteController.php`, add after `store()` (before `parseCcEmails()`):

```php
public function update(Request $request, Ticket $ticket, TicketNote $note)
{
    if ($note->ticket_id !== $ticket->id) {
        abort(404);
    }

    if ($note->note_type->isSystemGenerated()) {
        return redirect()->route('tickets.show', $ticket)
            ->with('error', 'System-generated notes cannot be edited.');
    }

    $request->validate([
        'body' => ['required', 'string'],
        'note_type' => ['required', 'in:note,reply,phone_call,resolution'],
        'is_private' => ['boolean'],
        'time' => ['nullable', 'string'],
        'is_billable' => ['nullable'],
    ]);

    $timeMinutes = $this->ticketService->parseTimeInput($request->input('time'));

    if ($timeMinutes !== null && $timeMinutes > 1440) {
        return redirect()->route('tickets.show', $ticket)
            ->with('error', 'Time logged cannot exceed 24 hours per entry.');
    }

    $isBillable = $timeMinutes ? $request->boolean('is_billable') : null;

    $note->update([
        'body' => $request->input('body'),
        'body_html' => \App\Helpers\MarkdownRenderer::render($request->input('body')),
        'note_type' => $request->input('note_type'),
        'is_private' => $request->boolean('is_private'),
        'time_minutes' => $timeMinutes,
        'is_billable' => $isBillable,
        'edited_at' => now(),
        'edited_by' => auth()->id(),
    ]);

    return redirect()->route('tickets.show', $ticket)
        ->with('success', 'Note updated.');
}
```

**Step 3: Add destroy() method**

Add after `update()`:

```php
public function destroy(Request $request, Ticket $ticket, TicketNote $note)
{
    if ($note->ticket_id !== $ticket->id) {
        abort(404);
    }

    if ($note->note_type->isSystemGenerated()) {
        return redirect()->route('tickets.show', $ticket)
            ->with('error', 'System-generated notes cannot be deleted.');
    }

    // Mark as private so portal users never see deleted notes
    $note->update([
        'is_private' => true,
        'edited_at' => now(),
        'edited_by' => auth()->id(),
    ]);

    $note->delete();

    return redirect()->route('tickets.show', $ticket)
        ->with('success', 'Note deleted.');
}
```

**Step 4: Add TicketNote import**

Add to the use statements at top of file:
```php
use App\Models\TicketNote;
```

**Step 5: Commit**

```bash
git add app/Http/Controllers/Web/TicketNoteController.php routes/web.php
git commit -m "Add update and destroy methods for ticket notes"
```

---

### Task 5: Update timeline UI — edit/delete buttons, deleted note rendering, edit modal

**Files:**
- Modify: `resources/views/tickets/show.blade.php`

This is the largest task. Three changes to the timeline:

**Step 1: Add deleted note rendering**

In `resources/views/tickets/show.blade.php`, find line 207-211:
```blade
@php
    $note = $item;
    $isAiTriage = $note->note_type === App\Enums\NoteType::AiTriage;
```

Add after the `$isAiTriage` line:
```php
$isDeleted = $note->trashed();
```

Then, right after the `$isSystem` / `$isResolution` variable declarations (find them near lines 207-213), BEFORE the `@if($isAiTriage)` check, add a new block for deleted notes:

```blade
@if($isDeleted)
    {{-- Deleted note placeholder --}}
    <div class="d-flex gap-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }}" style="opacity: 0.5;">
        <div class="flex-shrink-0">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-light text-muted"
                 style="width: 36px; height: 36px;">
                <i class="bi bi-trash"></i>
            </div>
        </div>
        <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="small text-muted">
                    <i class="bi bi-trash me-1"></i>Note deleted
                    @if($note->editor)
                        by {{ $note->editor->name }}
                    @endif
                    @if($note->edited_at)
                        {{ $note->edited_at->diffForHumans() }}
                    @endif
                </span>
                <a href="#" class="small text-muted text-decoration-none ms-auto"
                   data-bs-toggle="collapse" data-bs-target="#deletedNote{{ $note->id }}">
                    <i class="bi bi-eye me-1"></i>Show
                </a>
            </div>
            <div class="collapse" id="deletedNote{{ $note->id }}">
                <div class="small text-muted mt-1 p-2 bg-light rounded">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <strong>{{ $note->display_author }}</strong>
                        <span class="badge bg-light text-dark small">
                            <i class="bi {{ $note->note_type->icon() }} me-1"></i>{{ $note->note_type->label() }}
                        </span>
                        @if($note->formatted_time)
                            <span class="badge bg-light text-dark small">
                                <i class="bi bi-clock me-1"></i>{{ $note->formatted_time }}
                            </span>
                        @endif
                    </div>
                    <div class="note-body">{!! $note->rendered_body !!}</div>
                </div>
            </div>
        </div>
    </div>
@elseif($isAiTriage)
```

This replaces the existing `@if($isAiTriage)` with `@elseif($isAiTriage)` — deleted notes get their own rendering branch.

**Step 2: Add edit/delete buttons to regular notes**

In the regular note header row (around line 304-306), find:
```blade
<span class="text-muted small ms-auto" title="{{ $note->noted_at?->toAppTz()->format('Y-m-d H:i T') }}">
    {{ $note->noted_at?->diffForHumans() }}
</span>
```

Replace with:
```blade
<span class="text-muted small ms-auto" title="{{ $note->noted_at?->toAppTz()->format('Y-m-d H:i T') }}">
    {{ $note->noted_at?->diffForHumans() }}
    @if($note->edited_at)
        <span class="text-muted" title="Edited {{ $note->edited_at->toAppTz()->format('Y-m-d H:i T') }} by {{ $note->editor?->name ?? 'unknown' }}">(edited)</span>
    @endif
</span>
@if(!$note->note_type->isSystemGenerated())
    <div class="btn-group btn-group-sm ms-2">
        <button type="button" class="btn btn-link btn-sm text-muted p-0 px-1"
                data-bs-toggle="modal" data-bs-target="#editNoteModal{{ $note->id }}"
                title="Edit note">
            <i class="bi bi-pencil"></i>
        </button>
        <button type="button" class="btn btn-link btn-sm text-muted p-0 px-1"
                onclick="if(confirm('Delete this note? It will be hidden from clients and its time will stop counting.')) document.getElementById('deleteNote{{ $note->id }}').submit();"
                title="Delete note">
            <i class="bi bi-trash"></i>
        </button>
        <form id="deleteNote{{ $note->id }}" method="POST"
              action="{{ route('tickets.notes.destroy', [$ticket, $note]) }}" style="display:none;">
            @csrf @method('DELETE')
        </form>
    </div>
@endif
```

**Step 3: Add edit modals**

After the timeline `</div>` closing tag (after line 316-317, after the `@endforelse` and the card closing tags), add the edit modals for all editable notes. Place this before the sidebar column or after the main content card:

```blade
{{-- Edit note modals --}}
@foreach($timeline as $item)
    @if($item instanceof App\Models\TicketNote && !$item->trashed() && !$item->note_type->isSystemGenerated())
        <div class="modal fade" id="editNoteModal{{ $item->id }}" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('tickets.notes.update', [$ticket, $item]) }}">
                    @csrf @method('PUT')
                    <div class="modal-content">
                        <div class="modal-header">
                            <h6 class="modal-title">Edit Note</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label small">Content</label>
                                <textarea name="body" class="form-control" rows="6" required>{{ $item->body }}</textarea>
                            </div>
                            <div class="row g-3">
                                <div class="col-auto">
                                    <label class="form-label small">Type</label>
                                    <select name="note_type" class="form-select form-select-sm">
                                        <option value="note" {{ $item->note_type === App\Enums\NoteType::Note ? 'selected' : '' }}>Note</option>
                                        <option value="reply" {{ $item->note_type === App\Enums\NoteType::Reply ? 'selected' : '' }}>Reply</option>
                                        <option value="phone_call" {{ $item->note_type === App\Enums\NoteType::PhoneCall ? 'selected' : '' }}>Phone Call</option>
                                        <option value="resolution" {{ $item->note_type === App\Enums\NoteType::Resolution ? 'selected' : '' }}>Resolution</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <label class="form-label small">Time</label>
                                    <input type="text" name="time" class="form-control form-control-sm"
                                           value="{{ $item->formatted_time }}" placeholder="0h 15m" style="width: 100px;">
                                </div>
                                <div class="col-auto d-flex align-items-end gap-3">
                                    <div class="form-check">
                                        <input type="hidden" name="is_private" value="0">
                                        <input type="checkbox" name="is_private" value="1" class="form-check-input"
                                               id="editPrivate{{ $item->id }}" {{ $item->is_private ? 'checked' : '' }}>
                                        <label class="form-check-label small" for="editPrivate{{ $item->id }}">Private</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="hidden" name="is_billable" value="0">
                                        <input type="checkbox" name="is_billable" value="1" class="form-check-input"
                                               id="editBillable{{ $item->id }}" {{ $item->is_billable ? 'checked' : '' }}>
                                        <label class="form-check-label small" for="editBillable{{ $item->id }}">Billable</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endforeach
```

**Step 4: Commit**

```bash
git add resources/views/tickets/show.blade.php
git commit -m "Add edit/delete UI for ticket notes in timeline"
```

---

### Task 6: Deploy and verify

**Step 1: Deploy**

```bash
/deploy
```

**Step 2: Manual testing checklist**

- [ ] Visit a ticket with notes — edit (pencil) and delete (trash) buttons appear on non-system notes
- [ ] System-generated notes (AI Triage, Status Change) do NOT show edit/delete buttons
- [ ] Click edit — modal opens with current values pre-populated
- [ ] Edit body text, save — note updates, "(edited)" indicator appears
- [ ] Edit time on a prepay contract ticket — verify prepay balance adjusts
- [ ] Change billable from checked to unchecked — verify prepay debit is reversed
- [ ] Delete a note — shows "Note deleted by [name]" placeholder in timeline
- [ ] Click "Show" on deleted note — original content expands
- [ ] Delete a note with billable time — verify prepay balance credits back
- [ ] Portal view — deleted notes do NOT appear (is_private = true hides them)
- [ ] Portal view — no edit/delete buttons visible on any notes
