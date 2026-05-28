# Ticket Image & Attachment System — Design

## Goal

Support images in ticket descriptions and notes from three sources: email ingestion (inline + attachments), technician paste/drag-drop, and portal replies. Make images visible to AI triage and draft generation.

## Storage

- **`attachments` table** — polymorphic: `attachable_type` (Ticket, TicketNote, Email), `attachable_id`, `filename`, `original_filename`, `mime_type`, `size_bytes`, `storage_path`, `is_inline` (bool), `content_id` (for email CID references). Soft deletes with disk cleanup.
- **Disk location**: `storage/app/attachments/{id}/{filename}` on local disk (same pattern as contract-documents).
- **`AttachmentService`** — handles upload, Graph download, CID replacement, disk cleanup.

## Email Ingestion

When `linkEmailToTicket()` creates a note from an email:

1. Fetch attachments via Graph API (`GET /messages/{id}/attachments`).
2. Save each to disk, create `Attachment` records on the note.
3. For inline attachments (`isInline=true` with `contentId`): replace `cid:{contentId}` references in the HTML body with local URLs (`/attachments/{id}/{filename}`).
4. Store **sanitized HTML** in the note's `body_html` (not plain-text-only as today).
5. Non-inline attachments render as download links below the note.

When an email auto-creates a ticket, the description gets the same treatment: download attachments, replace CIDs, store sanitized HTML.

## Ticket Description from Email

Ticket description rendering already uses `{!! MarkdownRenderer::render() !!}`. Add a path for pre-rendered HTML: when description came from email (already HTML), skip markdown parsing, just sanitize. Could use a `description_html` column or detect HTML content.

## Technician Note Editor

- Intercept `paste` and `dragover`/`drop` events on the note body textarea.
- Upload image via AJAX to `POST /tickets/{ticket}/attachments` → returns `{url, markdown}`.
- Insert `![filename](url)` at cursor position in the textarea.
- MarkdownRenderer converts `![](url)` to `<img>` (already allowed by HtmlSanitizer).
- Uploaded attachments are initially unlinked (no `attachable`); after the note is saved, link them by matching URLs in the body.

## Serving Files

- Route: `GET /attachments/{attachment}/{filename}` — authenticated.
- Staff: `web` middleware (any logged-in user).
- Portal: `portal` middleware, scoped to client's tickets.
- Streams file from disk with correct `Content-Type` and `Content-Disposition`.

## AI Visibility

Images are interleaved with note context so the AI understands which images belong to which note:

```
[text: Note #1 by client — "My screen looks like this:"]
[image: screenshot.png from Note #1]
[text: Note #2 by tech — "Try this fix, here's what it should look like:"]
[image: expected-result.png from Note #2]
```

- `ContextBuilder` builds chronological notes; for each note, check for image attachments and include them as base64 image content blocks immediately after the note text.
- Same for ticket description — its images go right after the description text.
- Filter to image MIME types (PNG, JPEG, GIF, WebP). Skip PDFs and other files.
- Cap: 10 images per triage/draft run, prioritizing newest.
- Resize to max 1568px longest side before encoding (GD, already available in PHP 8.3).
- `AiClient` already accepts content arrays — pass image blocks alongside text.

## What Doesn't Change

- `HtmlSanitizer` already allows `img[src|alt|width|height]`.
- `MarkdownRenderer` already converts `![](url)` to `<img>`.
- `TicketNote::rendered_body` accessor already prefers `body_html` when set.
- Timeline rendering already uses `{!! $note->rendered_body !!}`.
