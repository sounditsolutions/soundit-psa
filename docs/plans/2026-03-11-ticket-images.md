# Ticket Image & Attachment System — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Support images in ticket descriptions and notes from email ingestion (inline CID + file attachments), technician paste/drag-drop, and portal replies — and make those images visible to AI triage and draft generation.

**Architecture:** Polymorphic `attachments` table stores metadata; files live on local disk at `storage/app/private/attachments/{id}/{filename}`. An `AttachmentService` handles upload, Graph API download, CID replacement in HTML bodies, and disk cleanup. `ContextBuilder` interleaves base64-encoded images with note text in AI content arrays. The EasyMDE markdown editor intercepts paste/drop events to upload images via AJAX and insert `![](url)` markdown.

**Tech Stack:** Laravel 12, PHP 8.3 (GD for image resize), Microsoft Graph API (attachment download), EasyMDE (markdown editor), Bootstrap 5.3, Anthropic Messages API (multimodal content blocks).

**Design doc:** `docs/plans/2026-03-11-ticket-images-design.md`

---

## Task 1: Migration & Attachment Model

Create the `attachments` table and Eloquent model with polymorphic relationship.

**Files:**
- Create: `database/migrations/2026_03_12_100000_create_attachments_table.php`
- Create: `app/Models/Attachment.php`
- Modify: `app/Models/TicketNote.php` (add `attachments()` relation)
- Modify: `app/Models/Ticket.php` (add `attachments()` relation)
- Modify: `app/Models/Email.php` (add `attachments()` relation)

**Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 50)->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();
            $table->string('filename');           // disk filename (sanitized)
            $table->string('original_filename');   // user-facing name
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_path');        // relative to local disk root
            $table->boolean('is_inline')->default(false);
            $table->string('content_id')->nullable(); // email CID reference
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index('content_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
```

**Step 2: Create model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'size_bytes' => 'integer',
        'is_inline' => 'boolean',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Whether this is an image (for AI visibility and inline rendering).
     */
    public function isImage(): bool
    {
        return in_array($this->mime_type, [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        ], true);
    }

    /**
     * URL for serving this attachment.
     */
    public function getUrlAttribute(): string
    {
        return route('attachments.show', [$this->id, $this->filename]);
    }

    /**
     * Disk cleanup on soft delete.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (Attachment $attachment) {
            Storage::disk('local')->delete($attachment->storage_path);
        });
    }
}
```

**Step 3: Add morphMany relations to existing models**

In `app/Models/TicketNote.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;

public function attachments(): MorphMany
{
    return $this->morphMany(Attachment::class, 'attachable');
}
```

In `app/Models/Ticket.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;

public function attachments(): MorphMany
{
    return $this->morphMany(Attachment::class, 'attachable');
}
```

In `app/Models/Email.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;

public function attachments(): MorphMany
{
    return $this->morphMany(Attachment::class, 'attachable');
}
```

**Step 4: Run migration**

Run: `php artisan migrate`
Expected: `attachments` table created.

**Step 5: Commit**

```bash
git add database/migrations/2026_03_12_100000_create_attachments_table.php app/Models/Attachment.php app/Models/TicketNote.php app/Models/Ticket.php app/Models/Email.php
git commit -m "Add attachments table and polymorphic Attachment model"
```

---

## Task 2: AttachmentService — Upload & Disk Management

Create the service that handles file uploads, disk storage, and cleanup.

**Files:**
- Create: `app/Services/AttachmentService.php`

**Step 1: Create service**

```php
<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    /**
     * Store an uploaded file and create an Attachment record.
     * The attachment is initially unlinked (null attachable) — caller links it later.
     */
    public function storeUpload(UploadedFile $file, ?int $uploadedBy = null): Attachment
    {
        $filename = $this->sanitizeFilename($file->getClientOriginalName());
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $size = $file->getSize();

        // Create record first to get the ID for the storage path
        $attachment = Attachment::create([
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'storage_path' => '', // placeholder
            'uploaded_by' => $uploadedBy,
        ]);

        $dir = "attachments/{$attachment->id}";
        $path = "{$dir}/{$filename}";
        Storage::disk('local')->putFileAs($dir, $file, $filename);

        $attachment->update(['storage_path' => $path]);

        return $attachment;
    }

    /**
     * Store raw file content (e.g., from Graph API download) and create an Attachment record.
     */
    public function storeFromContent(
        string $content,
        string $originalFilename,
        string $mimeType,
        ?bool $isInline = false,
        ?string $contentId = null,
    ): Attachment {
        $filename = $this->sanitizeFilename($originalFilename);

        $attachment = Attachment::create([
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($content),
            'storage_path' => '',
            'is_inline' => $isInline,
            'content_id' => $contentId,
        ]);

        $dir = "attachments/{$attachment->id}";
        $path = "{$dir}/{$filename}";
        Storage::disk('local')->put($path, $content);

        $attachment->update(['storage_path' => $path]);

        return $attachment;
    }

    /**
     * Link an attachment to a parent model (Ticket, TicketNote, Email).
     */
    public function linkTo(Attachment $attachment, string $attachableType, int $attachableId): void
    {
        $attachment->update([
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
        ]);
    }

    /**
     * Replace CID references in HTML body with local attachment URLs.
     * Returns the modified HTML.
     */
    public function replaceCidReferences(string $html, array $attachments): string
    {
        foreach ($attachments as $attachment) {
            if ($attachment->content_id && $attachment->is_inline) {
                $cid = $attachment->content_id;
                // CID in HTML: src="cid:xyz" — replace with local URL
                $html = str_ireplace(
                    "cid:{$cid}",
                    $attachment->url,
                    $html,
                );
            }
        }

        return $html;
    }

    /**
     * Read file contents from disk for a given attachment.
     */
    public function getContent(Attachment $attachment): ?string
    {
        if (Storage::disk('local')->exists($attachment->storage_path)) {
            return Storage::disk('local')->get($attachment->storage_path);
        }

        return null;
    }

    /**
     * Sanitize a filename: keep extension, slugify the name portion, ensure uniqueness.
     */
    private function sanitizeFilename(string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $slug = Str::slug($name) ?: 'file';

        if ($ext) {
            return "{$slug}.{$ext}";
        }

        return $slug;
    }
}
```

**Step 2: Commit**

```bash
git add app/Services/AttachmentService.php
git commit -m "Add AttachmentService for file upload and CID replacement"
```

---

## Task 3: Attachment Serving Route & Controller

Create the authenticated route that serves attachment files from disk.

**Files:**
- Create: `app/Http/Controllers/Web/AttachmentController.php`
- Modify: `routes/web.php` (add route)
- Modify: `routes/portal.php` (add portal route)

**Step 1: Create controller**

```php
<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function show(Attachment $attachment, string $filename): StreamedResponse
    {
        if ($attachment->filename !== $filename) {
            abort(404);
        }

        if (!Storage::disk('local')->exists($attachment->storage_path)) {
            abort(404);
        }

        $disposition = $attachment->isImage() ? 'inline' : 'attachment';

        return Storage::disk('local')->response(
            $attachment->storage_path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => "{$disposition}; filename=\"{$attachment->original_filename}\"",
            ],
        );
    }
}
```

**Step 2: Add route to `routes/web.php`**

Add near the other resource routes (after the `auth` middleware group):

```php
Route::get('/attachments/{attachment}/{filename}', [Web\AttachmentController::class, 'show'])
    ->name('attachments.show')
    ->middleware('auth');
```

**Step 3: Add portal route to `routes/portal.php`**

Portal users need access to attachments on their tickets. Add a portal-scoped route that verifies the attachment belongs to one of the client's tickets:

```php
Route::get('/portal/attachments/{attachment}/{filename}', function (
    \App\Models\Attachment $attachment,
    string $filename,
    \Illuminate\Http\Request $request,
) {
    if ($attachment->filename !== $filename) {
        abort(404);
    }

    // Verify attachment belongs to this client's ticket or ticket note
    $clientId = $request->attributes->get('portal_client_id');
    $allowed = false;

    if ($attachment->attachable_type === 'App\\Models\\Ticket') {
        $allowed = \App\Models\Ticket::where('id', $attachment->attachable_id)
            ->where('client_id', $clientId)->exists();
    } elseif ($attachment->attachable_type === 'App\\Models\\TicketNote') {
        $note = \App\Models\TicketNote::find($attachment->attachable_id);
        $allowed = $note && \App\Models\Ticket::where('id', $note->ticket_id)
            ->where('client_id', $clientId)->exists();
    }

    if (!$allowed) {
        abort(403);
    }

    if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($attachment->storage_path)) {
        abort(404);
    }

    $disposition = $attachment->isImage() ? 'inline' : 'attachment';

    return \Illuminate\Support\Facades\Storage::disk('local')->response(
        $attachment->storage_path,
        $attachment->original_filename,
        [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => "{$disposition}; filename=\"{$attachment->original_filename}\"",
        ],
    );
})->name('portal.attachments.show');
```

**Step 4: Commit**

```bash
git add app/Http/Controllers/Web/AttachmentController.php routes/web.php routes/portal.php
git commit -m "Add authenticated attachment serving routes for staff and portal"
```

---

## Task 4: Technician Image Upload (AJAX Endpoint)

Add the AJAX endpoint for uploading images from the note editor.

**Files:**
- Modify: `app/Http/Controllers/Web/AttachmentController.php` (add `store` method)
- Modify: `routes/web.php` (add upload route)

**Step 1: Add store method to AttachmentController**

```php
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

public function store(Request $request, \App\Models\Ticket $ticket, AttachmentService $attachmentService): JsonResponse
{
    $request->validate([
        'file' => ['required', 'file', 'max:10240', 'mimetypes:image/png,image/jpeg,image/gif,image/webp'],
    ]);

    $attachment = $attachmentService->storeUpload($request->file('file'), auth()->id());

    // Link to ticket initially — will be re-linked to note after save if URL appears in body
    $attachmentService->linkTo($attachment, 'App\\Models\\Ticket', $ticket->id);

    return response()->json([
        'url' => $attachment->url,
        'markdown' => "![{$attachment->original_filename}]({$attachment->url})",
        'id' => $attachment->id,
    ]);
}
```

**Step 2: Add route**

In `routes/web.php`, inside the authenticated group:

```php
Route::post('/tickets/{ticket}/attachments', [Web\AttachmentController::class, 'store'])
    ->name('tickets.attachments.store')
    ->middleware('auth');
```

**Step 3: Commit**

```bash
git add app/Http/Controllers/Web/AttachmentController.php routes/web.php
git commit -m "Add AJAX image upload endpoint for ticket notes"
```

---

## Task 5: EasyMDE Paste/Drop Image Support

Intercept paste and drag-drop events on the markdown editor to upload images and insert markdown links.

**Files:**
- Modify: `resources/views/components/markdown-editor.blade.php` (add paste/drop handlers)

**Step 1: Add image upload handling to the EasyMDE init script**

After the EasyMDE instance is created (after `el.easyMDE = editor;`), add paste/drop handlers. The upload URL comes from a `data-upload-url` attribute on the textarea:

```javascript
// Image paste/drop upload
var uploadUrl = el.dataset.uploadUrl;
if (uploadUrl && editor.codemirror) {
    var cm = editor.codemirror;

    function uploadImage(file) {
        var formData = new FormData();
        formData.append('file', file);
        formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

        // Insert placeholder
        var placeholder = '![Uploading ' + file.name + '...]()';
        cm.replaceSelection(placeholder);

        fetch(uploadUrl, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var content = cm.getValue();
            cm.setValue(content.replace(placeholder, data.markdown));
        })
        .catch(function(err) {
            var content = cm.getValue();
            cm.setValue(content.replace(placeholder, ''));
            console.error('Image upload failed:', err);
        });
    }

    cm.on('paste', function(cm, event) {
        var items = (event.clipboardData || event.originalEvent.clipboardData).items;
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') === 0) {
                event.preventDefault();
                uploadImage(items[i].getAsFile());
                return;
            }
        }
    });

    cm.on('drop', function(cm, event) {
        var files = event.dataTransfer.files;
        for (var i = 0; i < files.length; i++) {
            if (files[i].type.indexOf('image') === 0) {
                event.preventDefault();
                uploadImage(files[i]);
                return;
            }
        }
    });
}
```

**Step 2: Pass upload URL on the ticket note form**

In `resources/views/tickets/show.blade.php`, update the `<x-markdown-editor>` invocation to include the upload URL:

```blade
<x-markdown-editor name="body" rows="3" placeholder="Add a note..." :required="true"
    :upload-url="route('tickets.attachments.store', $ticket)" />
```

In the component, accept and render the prop:

```php
@props([
    'name',
    'id' => null,
    'value' => '',
    'rows' => 4,
    'placeholder' => '',
    'required' => false,
    'toolbar' => 'standard',
    'lazy' => false,
    'uploadUrl' => null,
])
```

And on the textarea:

```html
<textarea
    ...
    @if($uploadUrl) data-upload-url="{{ $uploadUrl }}" @endif
>
```

**Step 3: Commit**

```bash
git add resources/views/components/markdown-editor.blade.php resources/views/tickets/show.blade.php
git commit -m "Add paste/drop image upload to EasyMDE markdown editor"
```

---

## Task 6: Link Uploaded Attachments to Notes After Save

When a note is created, scan its body for attachment URLs and re-link those attachments from the ticket to the note.

**Files:**
- Modify: `app/Services/TicketService.php` (add attachment linking after note creation)
- Modify: `app/Services/AttachmentService.php` (add `linkAttachmentsFromBody` method)

**Step 1: Add method to AttachmentService**

```php
/**
 * Scan a body for attachment URLs and link matching unlinked attachments to the given model.
 * Used after note creation to re-link ticket-level uploads to the specific note.
 */
public function linkAttachmentsFromBody(string $body, string $attachableType, int $attachableId, int $ticketId): void
{
    // Find all attachment URLs in the body: /attachments/{id}/{filename}
    preg_match_all('#/attachments/(\d+)/#', $body, $matches);

    if (empty($matches[1])) {
        return;
    }

    $ids = array_unique($matches[1]);

    // Only re-link attachments that are currently linked to this ticket (not to other notes)
    Attachment::whereIn('id', $ids)
        ->where('attachable_type', 'App\\Models\\Ticket')
        ->where('attachable_id', $ticketId)
        ->update([
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
        ]);
}
```

**Step 2: Call from TicketService::addNote()**

In `app/Services/TicketService.php`, after the note is created (after the `TicketNote::create(...)` call in `addNote()`), add:

```php
// Link any uploaded attachments referenced in the body
app(AttachmentService::class)->linkAttachmentsFromBody(
    $body,
    'App\\Models\\TicketNote',
    $note->id,
    $ticket->id,
);
```

**Step 3: Do the same for `addPortalReply()`**

After the TicketNote is created in `addPortalReply()`:

```php
app(AttachmentService::class)->linkAttachmentsFromBody(
    $body,
    'App\\Models\\TicketNote',
    $note->id,
    $ticket->id,
);
```

**Step 4: Commit**

```bash
git add app/Services/AttachmentService.php app/Services/TicketService.php
git commit -m "Link uploaded attachments to notes after save by scanning body URLs"
```

---

## Task 7: Email Attachment Download via Graph API

Add the ability to download email attachments from Graph API and store them locally.

**Files:**
- Modify: `app/Services/Graph/GraphClient.php` (add `getMessageAttachments` method)
- Modify: `app/Services/AttachmentService.php` (add `downloadEmailAttachments` method)

**Step 1: Add Graph API method**

In `app/Services/Graph/GraphClient.php`:

```php
/**
 * Fetch attachments for a message. Returns array of attachment metadata + content.
 * Graph returns base64-encoded contentBytes for file attachments.
 */
public function getMessageAttachments(string $mailbox, string $messageId): array
{
    return $this->getAllPages(
        "users/{$mailbox}/messages/{$messageId}/attachments",
        ['$select' => 'id,name,contentType,size,isInline,contentId,contentBytes'],
    );
}
```

**Step 2: Add download method to AttachmentService**

```php
use App\Services\Graph\GraphClient;
use App\Models\Email;
use Illuminate\Support\Facades\Log;

/**
 * Download all attachments from a Graph email and store them locally.
 * Returns array of created Attachment models.
 *
 * @return Attachment[]
 */
public function downloadEmailAttachments(Email $email, GraphClient $graph, string $mailbox): array
{
    if (!$email->graph_id) {
        return [];
    }

    try {
        $graphAttachments = $graph->getMessageAttachments($mailbox, $email->graph_id);
    } catch (\Throwable $e) {
        Log::warning('[AttachmentService] Failed to fetch email attachments', [
            'email_id' => $email->id,
            'graph_id' => $email->graph_id,
            'error' => $e->getMessage(),
        ]);
        return [];
    }

    $attachments = [];

    foreach ($graphAttachments as $ga) {
        // Skip item attachments (attached emails) and reference attachments
        if (($ga['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') {
            continue;
        }

        $contentBytes = $ga['contentBytes'] ?? null;
        if (!$contentBytes) {
            continue;
        }

        $content = base64_decode($contentBytes);
        if ($content === false) {
            continue;
        }

        $attachment = $this->storeFromContent(
            $content,
            $ga['name'] ?? 'attachment',
            $ga['contentType'] ?? 'application/octet-stream',
            isInline: $ga['isInline'] ?? false,
            contentId: $ga['contentId'] ?? null,
        );

        $attachments[] = $attachment;
    }

    return $attachments;
}
```

**Step 3: Commit**

```bash
git add app/Services/Graph/GraphClient.php app/Services/AttachmentService.php
git commit -m "Add Graph API email attachment download to AttachmentService"
```

---

## Task 8: Email Ingestion — Attachments on Notes

When `linkEmailToTicket()` creates a note, download email attachments and replace CID references.

**Files:**
- Modify: `app/Services/EmailService.php` (update `linkEmailToTicket` and `autoCreateTicketFromEmail`)

**Step 1: Update `linkEmailToTicket()`**

Currently `linkEmailToTicket()` creates a note with plain-text body (line 519). Change it to:

1. Download attachments from Graph.
2. If there are inline attachments and the email has HTML body, replace CID references and store as `body_html`.
3. Link attachments to the note.

Replace the note creation block (lines 519-532) with:

```php
$body = trim($email->body_text ?? $this->extractPlainText($email->body_html) ?? '');
if ($body !== '' || $email->has_attachments) {
    // Download email attachments
    $attachmentService = app(AttachmentService::class);
    $emailAttachments = [];
    if ($email->has_attachments && $email->graph_id) {
        $graph = app(GraphClient::class);
        $mailbox = Setting::getValue('graph_mailbox');
        if ($mailbox) {
            $emailAttachments = $attachmentService->downloadEmailAttachments($email, $graph, $mailbox);
        }
    }

    // Build body_html: use email HTML with CID replacement if we have inline attachments
    $bodyHtml = null;
    $hasInline = collect($emailAttachments)->contains(fn ($a) => $a->is_inline);
    if ($hasInline && $email->body_html) {
        $bodyHtml = $attachmentService->replaceCidReferences($email->body_html, $emailAttachments);
        $bodyHtml = \App\Helpers\HtmlSanitizer::sanitize($bodyHtml);
    }

    $note = TicketNote::create([
        'ticket_id'   => $ticket->id,
        'author_id'   => null,
        'author_name' => $email->from_name ?? $email->from_address,
        'who_type'    => WhoType::EndUser,
        'email_id'    => $email->id,
        'body'        => $body ?: '[see attachments]',
        'body_html'   => $bodyHtml,
        'note_type'   => NoteType::Reply,
        'is_private'  => false,
        'noted_at'    => $email->received_at,
    ]);

    // Link attachments to the note
    foreach ($emailAttachments as $attachment) {
        $attachmentService->linkTo($attachment, 'App\\Models\\TicketNote', $note->id);
    }
}
```

Add `use App\Services\AttachmentService;` and `use App\Models\Setting;` at the top if not already imported.

**Step 2: Update `autoCreateTicketFromEmail()` similarly**

After the ticket is created in `autoCreateTicketFromEmail()`, download attachments and store sanitized HTML as `description_html` (or update the description rendering). Since ticket descriptions use `MarkdownRenderer::render()` in the Blade view, the simplest approach is to store attachments on the ticket and include a `description_html` column.

This is covered in Task 9.

**Step 3: Commit**

```bash
git add app/Services/EmailService.php
git commit -m "Download email attachments and replace CID references in ticket notes"
```

---

## Task 9: Ticket Description from Email (HTML Passthrough)

When a ticket is auto-created from an email, store the sanitized HTML body with CID replacements as `description_html` and render it directly (skipping markdown parsing).

**Files:**
- Create: `database/migrations/2026_03_12_100001_add_description_html_to_tickets.php`
- Modify: `app/Models/Ticket.php` (add `rendered_description` accessor)
- Modify: `app/Services/EmailService.php` (store `description_html` on auto-created tickets)
- Modify: `resources/views/tickets/show.blade.php` (use `rendered_description`)

**Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->longText('description_html')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('description_html');
        });
    }
};
```

**Step 2: Add accessor to Ticket model**

```php
/**
 * Rendered description: prefer pre-rendered HTML (from email), fall back to markdown.
 */
public function getRenderedDescriptionAttribute(): ?string
{
    if ($this->description_html) {
        return $this->description_html;
    }

    if ($this->description) {
        return \App\Helpers\MarkdownRenderer::render($this->description);
    }

    return null;
}
```

**Step 3: Update `autoCreateTicketFromEmail()` in EmailService**

After the ticket is created, download attachments and set `description_html`:

```php
// After: $ticket = $this->ticketService->createTicket($ticketData, null);
// Before: $this->linkEmailToTicket($email, $ticket);

// Download email attachments for the description
$attachmentService = app(AttachmentService::class);
if ($email->has_attachments && $email->graph_id) {
    $graph = app(GraphClient::class);
    $mailbox = Setting::getValue('graph_mailbox');
    if ($mailbox) {
        $emailAttachments = $attachmentService->downloadEmailAttachments($email, $graph, $mailbox);

        if (!empty($emailAttachments)) {
            // Replace CID references in HTML body, sanitize, and store
            $hasInline = collect($emailAttachments)->contains(fn ($a) => $a->is_inline);
            if ($hasInline && $email->body_html) {
                $descHtml = $attachmentService->replaceCidReferences($email->body_html, $emailAttachments);
                $descHtml = \App\Helpers\HtmlSanitizer::sanitize($descHtml);
                $ticket->update(['description_html' => $descHtml]);
            }

            // Link attachments to the ticket
            foreach ($emailAttachments as $attachment) {
                $attachmentService->linkTo($attachment, 'App\\Models\\Ticket', $ticket->id);
            }
        }
    }
}
```

**Step 4: Update Blade view**

In `resources/views/tickets/show.blade.php`, replace:

```blade
<div class="note-body">{!! App\Helpers\MarkdownRenderer::render($ticket->description) !!}</div>
```

with:

```blade
<div class="note-body">{!! $ticket->rendered_description !!}</div>
```

**Step 5: Display non-inline attachments below description**

After the description `</div>`, add:

```blade
@if($ticket->attachments->where('is_inline', false)->isNotEmpty())
    <div class="mt-2">
        @foreach($ticket->attachments->where('is_inline', false) as $att)
            <a href="{{ $att->url }}" class="badge bg-light text-dark border me-1" target="_blank">
                <i class="bi bi-paperclip me-1"></i>{{ $att->original_filename }}
                <span class="text-muted">({{ number_format($att->size_bytes / 1024, 0) }} KB)</span>
            </a>
        @endforeach
    </div>
@endif
```

**Step 6: Run migration, commit**

```bash
php artisan migrate
git add database/migrations/2026_03_12_100001_add_description_html_to_tickets.php app/Models/Ticket.php app/Services/EmailService.php resources/views/tickets/show.blade.php
git commit -m "Add description_html for email-sourced tickets with inline images"
```

---

## Task 10: Display Note Attachments in Timeline

Show non-inline attachments as download links below each note, and ensure inline images render in the HTML body.

**Files:**
- Modify: `resources/views/tickets/show.blade.php` (attachment display in note timeline)
- Modify: `app/Http/Controllers/Web/TicketController.php` (eager-load attachments)

**Step 1: Eager-load attachments**

In the ticket `show()` method of `TicketController`, ensure notes include attachments. Find where notes are loaded and add `.attachments`:

```php
// In the show method, where $ticket is loaded with relations
$ticket->load(['notes.attachments']);
```

Or if notes are loaded via the Ticket model's eager loading, add `'notes.attachments'` to the `with` array.

Also load ticket-level attachments:

```php
$ticket->load(['attachments']);
```

**Step 2: Add attachment display in note timeline**

In the note rendering section of `show.blade.php`, after the note body is displayed, add:

```blade
@if($note->attachments->where('is_inline', false)->isNotEmpty())
    <div class="mt-2">
        @foreach($note->attachments->where('is_inline', false) as $att)
            <a href="{{ $att->url }}" class="badge bg-light text-dark border me-1" target="_blank">
                <i class="bi bi-paperclip me-1"></i>{{ $att->original_filename }}
                <span class="text-muted">({{ number_format($att->size_bytes / 1024, 0) }} KB)</span>
            </a>
        @endforeach
    </div>
@endif
```

**Step 3: Commit**

```bash
git add resources/views/tickets/show.blade.php app/Http/Controllers/Web/TicketController.php
git commit -m "Display non-inline note attachments in ticket timeline"
```

---

## Task 11: AI Image Context — ContextBuilder Multimodal

Update ContextBuilder to return content arrays (not plain strings) that include base64-encoded images interleaved with note text, for Anthropic's multimodal API.

**Files:**
- Modify: `app/Services/Triage/ContextBuilder.php` (add `buildMultimodalContent` method)
- Create: `app/Services/AttachmentService.php` (add `resizeImageForAi` method — append to existing file)

**Step 1: Add image resize method to AttachmentService**

```php
/**
 * Resize an image to max 1568px on longest side and return base64-encoded content.
 * Returns null if the file isn't a valid image or GD processing fails.
 */
public function resizeImageForAi(Attachment $attachment): ?string
{
    $content = $this->getContent($attachment);
    if (!$content) {
        return null;
    }

    $image = @imagecreatefromstring($content);
    if (!$image) {
        return null;
    }

    $maxDim = 1568;
    $width = imagesx($image);
    $height = imagesy($image);

    // Only resize if needed
    if ($width > $maxDim || $height > $maxDim) {
        if ($width >= $height) {
            $newWidth = $maxDim;
            $newHeight = (int) round($height * ($maxDim / $width));
        } else {
            $newHeight = $maxDim;
            $newWidth = (int) round($width * ($maxDim / $height));
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG/WebP
        if (in_array($attachment->mime_type, ['image/png', 'image/webp'])) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    ob_start();
    // Output as JPEG for smaller size (unless PNG/WebP transparency matters)
    if ($attachment->mime_type === 'image/png') {
        imagepng($image);
    } elseif ($attachment->mime_type === 'image/webp') {
        imagewebp($image);
    } else {
        imagejpeg($image, null, 85);
    }
    $output = ob_get_clean();
    imagedestroy($image);

    return base64_encode($output);
}
```

**Step 2: Add multimodal context builder method**

In `app/Services/Triage/ContextBuilder.php`, add a new method that returns Anthropic content block arrays instead of plain strings:

```php
use App\Models\Attachment;
use App\Services\AttachmentService;

private const MAX_AI_IMAGES = 10;

/**
 * Build multimodal content array for AI (text + image blocks interleaved).
 * Returns an array of Anthropic content blocks: [{type: 'text', text: ...}, {type: 'image', ...}].
 * Falls back to text-only if no images are available.
 */
public static function buildMultimodalContent(Ticket $ticket): array
{
    $ticket->loadMissing([
        'attachments',
        'notes' => fn ($q) => $q->orderBy('noted_at', 'asc')->limit(self::MAX_NOTES),
        'notes.author',
        'notes.attachments',
    ]);

    $blocks = [];
    $imageCount = 0;
    $attachmentService = app(AttachmentService::class);

    // Ticket description text
    $descText = self::buildTicketSection($ticket);
    if ($ticket->client) {
        $descText .= "\n\n" . self::buildClientSection($ticket);
    }
    $blocks[] = ['type' => 'text', 'text' => $descText];

    // Ticket description images
    foreach ($ticket->attachments->filter(fn ($a) => $a->isImage()) as $att) {
        if ($imageCount >= self::MAX_AI_IMAGES) break;
        $base64 = $attachmentService->resizeImageForAi($att);
        if ($base64) {
            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $att->mime_type === 'image/gif' ? 'image/png' : $att->mime_type,
                    'data' => $base64,
                ],
            ];
            $imageCount++;
        }
    }

    // Notes with interleaved images
    foreach ($ticket->notes as $note) {
        $author = $note->author?->name ?? $note->author_name ?? 'System';
        $date = $note->noted_at?->toDateTimeString() ?? $note->created_at->toDateTimeString();
        $type = $note->note_type?->label() ?? 'Note';
        $sender = $note->who_type === \App\Enums\WhoType::EndUser ? 'CLIENT' : 'TECHNICIAN';

        $body = strip_tags($note->body ?? '');
        if (strlen($body) > self::MAX_NOTE_LENGTH) {
            $body = substr($body, 0, self::MAX_NOTE_LENGTH) . ' [TRUNCATED]';
        }

        $noteText = "### [{$sender}] {$type} by {$author} ({$date})\n{$body}";
        $blocks[] = ['type' => 'text', 'text' => $noteText];

        // Note images (newest notes have been loaded last — images from newer notes are added first due to asc ordering)
        if ($imageCount < self::MAX_AI_IMAGES) {
            foreach ($note->attachments->filter(fn ($a) => $a->isImage()) as $att) {
                if ($imageCount >= self::MAX_AI_IMAGES) break;
                $base64 = $attachmentService->resizeImageForAi($att);
                if ($base64) {
                    $blocks[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $att->mime_type === 'image/gif' ? 'image/png' : $att->mime_type,
                            'data' => $base64,
                        ],
                    ];
                    $imageCount++;
                }
            }
        }
    }

    return $blocks;
}
```

**Step 3: Commit**

```bash
git add app/Services/AttachmentService.php app/Services/Triage/ContextBuilder.php
git commit -m "Add multimodal AI context: interleave base64 images with note text"
```

---

## Task 12: Wire Multimodal Content into Triage Pipeline

Update the triage pipeline to use multimodal content when images are available.

**Files:**
- Modify: `app/Services/Triage/TechnicalTriager.php` (use multimodal content)
- Modify: `app/Services/Ai/AiClient.php` (ensure `complete()` accepts content arrays)

**Step 1: Update AiClient::complete() to accept content arrays**

Currently `complete()` takes `string $userMessage`. Add an overload or change the method to accept `string|array`:

```php
/**
 * Send a simple completion request. Works with both Anthropic and OpenAI.
 * $userMessage can be a string or an array of Anthropic content blocks (for multimodal).
 */
public function complete(string $system, string|array $userMessage, int $maxTokens = 4096): AiResponse
{
    $provider = AiConfig::provider();
    $apiKey = AiConfig::get('api_key');
    $model = $this->modelOverride ?? AiConfig::model();

    if ($provider === 'anthropic') {
        $content = is_array($userMessage) ? $userMessage : $userMessage;
        return $this->callAnthropic($apiKey, $model, $system, [
            ['role' => 'user', 'content' => $content],
        ], $maxTokens);
    }

    // OpenAI doesn't support image blocks in the same way — fall back to text only
    $text = is_array($userMessage)
        ? implode("\n", array_map(fn ($b) => $b['type'] === 'text' ? $b['text'] : '[image]', $userMessage))
        : $userMessage;

    return $this->callOpenAi($apiKey, $model, $system, [
        ['role' => 'user', 'content' => $text],
    ], $maxTokens);
}
```

**Step 2: Update TechnicalTriager to use multimodal content**

Find where `ContextBuilder::buildForTicket()` is called in the triage pipeline and add a check: if the ticket has image attachments, use `buildMultimodalContent()` to construct the user message as a content array instead of a plain string.

In `app/Services/Triage/TechnicalTriager.php`, locate where the context is built and the AI is called. Update the user message construction:

```php
// Check if ticket has images for multimodal context
$hasImages = $ticket->attachments->contains(fn ($a) => $a->isImage())
    || $ticket->notes->flatMap->attachments->contains(fn ($a) => $a->isImage());

if ($hasImages && AiConfig::provider() === 'anthropic') {
    $userContent = ContextBuilder::buildMultimodalContent($ticket);
} else {
    $userContent = ContextBuilder::buildForTicket($ticket);
}
```

Pass `$userContent` (string or array) to the AI call. For `runToolLoop`, the first user message in the messages array needs to use the content array format:

```php
// In the messages array for runToolLoop:
$messages = [['role' => 'user', 'content' => $userContent]];
```

This already works because `callAnthropic` passes messages directly to the API.

**Step 3: Commit**

```bash
git add app/Services/Ai/AiClient.php app/Services/Triage/TechnicalTriager.php
git commit -m "Wire multimodal image context into AI triage pipeline"
```

---

## Task 13: Portal Image Upload

Allow portal users to upload images when creating tickets or replying.

**Files:**
- Modify: `app/Http/Controllers/Portal/PortalTicketController.php` (add attachment upload endpoint)
- Modify: `routes/portal.php` (add upload route)
- Modify: portal ticket views (add upload URL to markdown editors)

**Step 1: Add portal upload endpoint**

In `PortalTicketController` or a new `PortalAttachmentController`:

```php
public function uploadAttachment(Request $request, Ticket $ticket, AttachmentService $attachmentService): JsonResponse
{
    $clientId = $request->attributes->get('portal_client_id');
    if ($ticket->client_id !== $clientId) {
        abort(403);
    }

    $request->validate([
        'file' => ['required', 'file', 'max:10240', 'mimetypes:image/png,image/jpeg,image/gif,image/webp'],
    ]);

    $attachment = $attachmentService->storeUpload($request->file('file'));
    $attachmentService->linkTo($attachment, 'App\\Models\\Ticket', $ticket->id);

    return response()->json([
        'url' => route('portal.attachments.show', [$attachment->id, $attachment->filename]),
        'markdown' => "![{$attachment->original_filename}](" . route('portal.attachments.show', [$attachment->id, $attachment->filename]) . ")",
        'id' => $attachment->id,
    ]);
}
```

**Step 2: Add route**

```php
Route::post('/portal/tickets/{ticket}/attachments', [PortalTicketController::class, 'uploadAttachment'])
    ->name('portal.tickets.attachments.store');
```

**Step 3: Pass upload URL in portal ticket views**

In the portal ticket reply form, add the `upload-url` attribute to the markdown editor textarea.

**Step 4: Commit**

```bash
git add app/Http/Controllers/Portal/PortalTicketController.php routes/portal.php
git commit -m "Add portal image upload endpoint for ticket replies"
```

---

## Task 14: Cleanup Orphan Attachments

Add an artisan command to clean up unlinked attachments older than 24 hours (orphans from abandoned editor sessions).

**Files:**
- Create: `app/Console/Commands/CleanOrphanAttachments.php`
- Modify: `routes/console.php` (schedule daily)

**Step 1: Create command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanOrphanAttachments extends Command
{
    protected $signature = 'attachments:clean-orphans';
    protected $description = 'Delete unlinked attachments older than 24 hours';

    public function handle(): int
    {
        $orphans = Attachment::whereNull('attachable_type')
            ->where('created_at', '<', now()->subHours(24))
            ->get();

        $count = 0;
        foreach ($orphans as $orphan) {
            Storage::disk('local')->delete($orphan->storage_path);
            $orphan->forceDelete();
            $count++;
        }

        $this->info("Cleaned {$count} orphan attachments.");

        return self::SUCCESS;
    }
}
```

**Step 2: Schedule in `routes/console.php`**

```php
Schedule::command('attachments:clean-orphans')->daily()->at('04:00');
```

**Step 3: Update `docs/INSTALL.md`** with the new scheduled command.

**Step 4: Commit**

```bash
git add app/Console/Commands/CleanOrphanAttachments.php routes/console.php docs/INSTALL.md
git commit -m "Add daily cleanup of orphan attachments"
```

---

## Task Summary

| Task | Component | Key Files |
|------|-----------|-----------|
| 1 | Migration & Model | `Attachment.php`, migration |
| 2 | AttachmentService | `AttachmentService.php` |
| 3 | Serving Route | `AttachmentController.php`, routes |
| 4 | AJAX Upload | `AttachmentController::store()`, route |
| 5 | EasyMDE Paste/Drop | `markdown-editor.blade.php` |
| 6 | Note Linking | `TicketService.php`, `AttachmentService.php` |
| 7 | Graph Download | `GraphClient.php`, `AttachmentService.php` |
| 8 | Email Note Attachments | `EmailService::linkEmailToTicket()` |
| 9 | Email Description HTML | `description_html` column, `Ticket::rendered_description` |
| 10 | Timeline Display | `show.blade.php`, eager loading |
| 11 | AI Multimodal Context | `ContextBuilder::buildMultimodalContent()` |
| 12 | Triage Pipeline | `TechnicalTriager.php`, `AiClient.php` |
| 13 | Portal Upload | `PortalTicketController.php`, portal routes |
| 14 | Orphan Cleanup | `CleanOrphanAttachments.php`, schedule |
