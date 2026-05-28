<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function store(Request $request, \App\Models\Ticket $ticket, AttachmentService $attachmentService): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimetypes:image/png,image/jpeg,image/gif,image/webp'],
            'note_id' => ['nullable', 'integer'],
        ]);

        $attachment = $attachmentService->storeUpload($request->file('file'), auth()->id());

        // When editing an existing note, link directly to it; otherwise link to ticket
        // (will be re-linked to note after save if URL appears in body)
        $noteId = $request->input('note_id');
        if ($noteId && $ticket->notes()->where('id', $noteId)->exists()) {
            $attachmentService->linkTo($attachment, 'App\\Models\\TicketNote', $noteId);
        } else {
            $attachmentService->linkTo($attachment, 'App\\Models\\Ticket', $ticket->id);
        }

        return response()->json([
            'url' => $attachment->url,
            'markdown' => "![{$attachment->original_filename}]({$attachment->url})",
            'id' => $attachment->id,
        ]);
    }

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
                'Content-Disposition' => "{$disposition}; filename=\"" . str_replace(['"', "\r", "\n"], '', $attachment->original_filename) . "\"",
            ],
        );
    }
}
