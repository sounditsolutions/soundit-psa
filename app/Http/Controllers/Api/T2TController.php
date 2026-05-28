<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\T2T\T2TFieldMapper;
use App\Services\T2T\T2TService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class T2TController extends Controller
{
    public function __construct(
        private readonly T2TService $service,
    ) {}

    // ── Company / Companies ──

    public function getCompany(Request $request, int $id): JsonResponse
    {
        $this->logRequest("GET /company/companies/{$id}", $request);

        // Synthetic "Unregistered" company for T2T fallback contact
        if ($id === 0) {
            return response()->json([
                'id' => 0,
                'identifier' => 'Unregistered',
                'name' => 'Unregistered',
                'status' => ['id' => 1, 'name' => 'Active'],
                'deletedFlag' => false,
            ]);
        }

        $client = \App\Models\Client::find($id);

        if (! $client) {
            return response()->json(['code' => 'NotFound', 'message' => 'Company not found'], 404);
        }

        return response()->json(T2TFieldMapper::clientToCwCompany($client));
    }

    // ── Company / Contacts ──

    public function listContacts(Request $request): JsonResponse
    {
        $this->logRequest('GET /company/contacts', $request);

        $conditions = $request->query('conditions', '');
        $childConditions = $request->query('childconditions', '');
        $email = T2TService::parseEmailFromConditions($childConditions)
              ?? T2TService::parseEmailFromConditions($conditions);

        if (! $email) {
            return response()->json([]);
        }

        $companyId = T2TService::parseCompanyIdFromConditions($conditions);

        return response()->json($this->service->findContactsByEmail($email, $companyId));
    }

    public function createContact(Request $request): JsonResponse
    {
        $this->logRequest('POST /company/contacts', $request);

        $result = $this->service->createContact($request->all());

        if ($result === null) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        return response()->json($result, 201);
    }

    // ── Company / Configurations (Assets) ──

    public function listConfigurations(Request $request): JsonResponse
    {
        $this->logRequest('GET /company/configurations', $request);

        $conditions = $request->query('conditions', '');
        $hostname = T2TService::parseHostnameFromConditions($conditions);

        if (! $hostname) {
            return response()->json([]);
        }

        $companyId = T2TService::parseCompanyIdFromConditions($conditions);

        return response()->json($this->service->findConfigurationsByHostname($hostname, $companyId));
    }

    // ── Service / Boards ──

    public function listBoards(Request $request): JsonResponse
    {
        $this->logRequest('GET /service/boards', $request);

        return response()->json(T2TFieldMapper::boards());
    }

    public function listBoardStatuses(Request $request, int $boardId): JsonResponse
    {
        $this->logRequest("GET /service/boards/{$boardId}/statuses", $request);

        if ($boardId !== 1) {
            Log::warning('[T2T] Unexpected board ID', ['board_id' => $boardId]);
        }

        return response()->json(T2TFieldMapper::boardStatuses($boardId));
    }

    public function listBoardTypes(Request $request, int $boardId): JsonResponse
    {
        $this->logRequest("GET /service/boards/{$boardId}/types", $request);

        return response()->json(T2TFieldMapper::boardTypes($boardId));
    }

    public function listBoardTeams(Request $request, int $boardId): JsonResponse
    {
        $this->logRequest("GET /service/boards/{$boardId}/teams", $request);

        return response()->json(T2TFieldMapper::boardTeams($boardId));
    }

    public function listBoardSubTypes(Request $request, int $boardId): JsonResponse
    {
        $this->logRequest("GET /service/boards/{$boardId}/subtypes", $request);

        return response()->json(T2TFieldMapper::boardSubTypes($boardId));
    }

    // ── Service / Priorities & Sources ──

    public function listPriorities(Request $request): JsonResponse
    {
        $this->logRequest('GET /service/priorities', $request);

        return response()->json(T2TFieldMapper::priorities());
    }

    public function listSources(Request $request): JsonResponse
    {
        $this->logRequest('GET /service/sources', $request);

        return response()->json(T2TFieldMapper::sources());
    }

    public function listSeverities(Request $request): JsonResponse
    {
        $this->logRequest('GET /service/severities', $request);

        return response()->json(T2TFieldMapper::severities());
    }

    public function listImpacts(Request $request): JsonResponse
    {
        $this->logRequest('GET /service/impacts', $request);

        return response()->json(T2TFieldMapper::impacts());
    }

    // ── System / Info ──

    public function systemInfo(Request $request): JsonResponse
    {
        $this->logRequest('GET /system/info', $request);

        return response()->json(T2TFieldMapper::systemInfo());
    }

    // ── Service / Tickets ──

    public function listTickets(Request $request): JsonResponse
    {
        $this->logRequest('GET /service/tickets', $request);

        $conditions = $request->query('conditions', '');
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min((int) $request->query('pageSize', 25), 100);

        $query = Ticket::with(['client', 'contact'])
            ->where('source', \App\Enums\TicketSource::HelpdeskButton->value);

        if ($conditions) {
            $ticketId = T2TService::parseTicketIdFromConditions($conditions);
            if ($ticketId) {
                $query->where('id', $ticketId);
            }
        }

        $tickets = $query->orderByDesc('created_at')
            ->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        return response()->json(
            $tickets->map(fn (Ticket $t) => T2TFieldMapper::ticketToCwFormat($t))->values()->all()
        );
    }

    public function createTicket(Request $request): JsonResponse
    {
        $this->logRequest('POST /service/tickets', $request);

        try {
            $result = $this->service->createTicketFromCw($request->all());

            return response()->json($result, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'code' => 'InvalidArgument',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateTicket(Request $request, int $id): JsonResponse
    {
        $this->logRequest("PATCH /service/tickets/{$id}", $request);

        $ticket = Ticket::with(['client', 'contact'])->find($id);

        if (! $ticket) {
            return response()->json(['code' => 'NotFound', 'message' => 'Ticket not found'], 404);
        }

        if ($ticket->source !== \App\Enums\TicketSource::HelpdeskButton) {
            return response()->json(['code' => 'Forbidden', 'message' => 'Cannot modify this ticket'], 403);
        }

        try {
            $result = $this->service->updateTicketFromCw($ticket, $request->all());

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'code' => 'InvalidArgument',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function addTicketNote(Request $request, int $id): JsonResponse
    {
        $this->logRequest("POST /service/tickets/{$id}/notes", $request);

        $ticket = Ticket::find($id);

        if (! $ticket) {
            return response()->json(['code' => 'NotFound', 'message' => 'Ticket not found'], 404);
        }

        $systemUserId = \App\Support\T2TConfig::systemUserId();

        if (! $systemUserId) {
            return response()->json(['code' => 'ServerError', 'message' => 'System user not configured'], 500);
        }

        $body = $request->input('text', $request->input('noteText', ''));
        $isInternal = $request->boolean('internalAnalysisFlag', true);

        if (! $body) {
            return response()->json(['code' => 'InvalidArgument', 'message' => 'Note text required'], 400);
        }

        $note = $this->service->addNoteFromCw($ticket, $body, $isInternal, $systemUserId);

        return response()->json($note, 201);
    }

    // ── System / Callbacks ──

    public function registerCallback(Request $request): JsonResponse
    {
        $this->logRequest('POST /system/callbacks', $request);

        $url = $request->input('url');

        if (! $url) {
            return response()->json(['code' => 'InvalidArgument', 'message' => 'URL required'], 400);
        }

        try {
            $this->service->registerCallback($url);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'code' => 'InvalidArgument',
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'id' => 1,
            'description' => $request->input('description', 'T2T callback'),
            'url' => $url,
            'objectId' => $request->input('objectId'),
            'type' => $request->input('type'),
        ], 201);
    }

    // ── Catch-All ──

    public function catchAll(Request $request, string $path = ''): JsonResponse
    {
        Log::info('[T2T] Unmapped endpoint called', [
            'method' => $request->method(),
            'path' => $path,
            'query' => $request->query(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'code' => 'NotImplemented',
            'message' => "Endpoint not implemented: {$request->method()} /{$path}",
        ], 501);
    }

    // ── Logging ──

    private function logRequest(string $endpoint, Request $request): void
    {
        Log::info('[T2T] ' . $endpoint, [
            'query' => $request->query(),
            'ip' => $request->ip(),
        ]);

        if (config('app.debug')) {
            Log::debug('[T2T] Request body: ' . $endpoint, [
                'body' => $request->all(),
            ]);
        }
    }
}
