<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Email;
use App\Services\Graph\GraphClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    /**
     * Store an uploaded file and create an Attachment record.
     *
     * Creates the record first to get the ID for the storage path:
     *   attachments/{id}/{sanitized_filename}
     */
    public function storeUpload(UploadedFile $file, ?int $uploadedBy = null): Attachment
    {
        $sanitized = $this->sanitizeFilename($file->getClientOriginalName());

        $attachment = Attachment::create([
            'filename' => $sanitized,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'storage_path' => 'attachments/tmp', // placeholder, updated below
            'uploaded_by' => $uploadedBy,
        ]);

        $path = "attachments/{$attachment->id}/{$sanitized}";
        Storage::disk('local')->putFileAs(
            "attachments/{$attachment->id}",
            $file,
            $sanitized,
        );

        $attachment->update(['storage_path' => $path]);

        Log::info('[Attachment] Stored upload', [
            'attachment_id' => $attachment->id,
            'filename' => $sanitized,
            'size' => $file->getSize(),
            'mime' => $attachment->mime_type,
        ]);

        return $attachment;
    }

    /**
     * Store raw content (e.g. from Graph API) and create an Attachment record.
     */
    public function storeFromContent(
        string $content,
        string $originalFilename,
        string $mimeType,
        ?bool $isInline = false,
        ?string $contentId = null,
    ): Attachment {
        $sanitized = $this->sanitizeFilename($originalFilename);

        $attachment = Attachment::create([
            'filename' => $sanitized,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($content),
            'storage_path' => 'attachments/tmp', // placeholder, updated below
            'is_inline' => $isInline ?? false,
            'content_id' => $contentId,
        ]);

        $path = "attachments/{$attachment->id}/{$sanitized}";
        Storage::disk('local')->put($path, $content);

        $attachment->update(['storage_path' => $path]);

        Log::info('[Attachment] Stored from content', [
            'attachment_id' => $attachment->id,
            'filename' => $sanitized,
            'size' => strlen($content),
            'mime' => $mimeType,
            'is_inline' => $isInline,
            'content_id' => $contentId,
        ]);

        return $attachment;
    }

    /**
     * Link an attachment to a parent model (ticket, ticket_note, etc.).
     */
    public function linkTo(Attachment $attachment, string $attachableType, int $attachableId): void
    {
        $attachment->update([
            'attachable_type' => $attachableType,
            'attachable_id' => $attachableId,
        ]);
    }

    /**
     * Replace cid: references in HTML with local attachment URLs.
     *
     * For each inline attachment with a content_id, replaces
     * "cid:{contentId}" with the attachment's serving URL.
     *
     * @param  array<Attachment>  $attachments
     */
    public function replaceCidReferences(string $html, array $attachments): string
    {
        foreach ($attachments as $attachment) {
            if (! $attachment->is_inline || ! $attachment->content_id) {
                continue;
            }

            // Content IDs may be stored with or without angle brackets;
            // strip them for matching against the cid: URI scheme.
            $cid = trim($attachment->content_id, '<>');

            $html = str_replace(
                "cid:{$cid}",
                $attachment->url,
                $html,
            );
        }

        return $html;
    }

    /**
     * Read file contents from disk.
     */
    public function getContent(Attachment $attachment): ?string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($attachment->storage_path)) {
            Log::warning('[Attachment] File not found on disk', [
                'attachment_id' => $attachment->id,
                'storage_path' => $attachment->storage_path,
            ]);

            return null;
        }

        return $disk->get($attachment->storage_path);
    }

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

        if ($width > $maxDim || $height > $maxDim) {
            if ($width >= $height) {
                $newWidth = $maxDim;
                $newHeight = (int) round($height * ($maxDim / $width));
            } else {
                $newHeight = $maxDim;
                $newWidth = (int) round($width * ($maxDim / $height));
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            if (in_array($attachment->mime_type, ['image/png', 'image/webp', 'image/gif'])) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        if ($attachment->mime_type === 'image/png') {
            imagepng($image);
        } elseif ($attachment->mime_type === 'image/webp') {
            imagewebp($image);
        } elseif ($attachment->mime_type === 'image/gif') {
            imagepng($image); // GIF → PNG for Anthropic compatibility
        } else {
            imagejpeg($image, null, 85);
        }
        $output = ob_get_clean();
        imagedestroy($image);

        return base64_encode($output);
    }

    /**
     * Sanitize a filename: slugify the name portion, preserve extension.
     *
     * Examples:
     *   "My Report (2).pdf" → "my-report-2.pdf"
     *   "screen shot 2024.png" → "screen-shot-2024.png"
     *   "../../etc/passwd" → "etc-passwd"
     */
    private function sanitizeFilename(string $filename): string
    {
        // Strip any directory components for safety
        $filename = basename($filename);

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Slugify the name portion
        $slug = Str::slug($name);

        // If slugify produced an empty string, use a UUID
        if ($slug === '') {
            $slug = (string) Str::uuid();
        }

        // Re-attach extension if present
        if ($extension !== '') {
            return $slug . '.' . Str::lower($extension);
        }

        return $slug;
    }
}
