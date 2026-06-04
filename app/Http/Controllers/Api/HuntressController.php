<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\Huntress\HuntressService;
use App\Services\T2T\T2TFieldMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HuntressController extends Controller
{
    public function __construct(
        private readonly HuntressService $service,
    ) {}

    // ── Unauthenticated: Static Metadata (CW compat discovery) ──

    public function listBoards(Request $request): JsonResponse
    {
        $this->logRequest('GET /service/boards', $request);

        return response()->json(T2TFieldMapper::boards());
    }

    public function listBoardStatuses(Request $request, int $boardId): JsonResponse
    {
        $this->logRequest("GET /service/boards/{$boardId}/statuses", $request);

        return response()->json(T2TFieldMapper::boardStatuses($boardId));
    }

    public function getBoardStatus(Request $request, int $boardId, int $statusId): JsonResponse
    {
        $this->logRequest("GET /service/boards/{$boardId}/statuses/{$statusId}", $request);

        $statuses = T2TFieldMapper::boardStatuses($boardId);
        $status = collect($statuses)->firstWhere('id', $statusId);

        if (! $status) {
            return response()->json(['code' => 'NotFound', 'message' => 'Status not found'], 404);
        }

        return response()->json($status);
    }

    public function listBoardTypes(Request $request, int $boardId): JsonResponse
    {
        $this->logRequest("GET /service/boards/{$boardId}/types", $request);

        return response()->json(T2TFieldMapper::boardTypes($boardId));
    }

    public function listBoardSubTypes(Request $request, int $boardId): JsonResponse
    {
        $this->logRequest("GET /service/boards/{$boardId}/subtypes", $request);

        return response()->json(T2TFieldMapper::boardSubTypes($boardId));
    }

    public function listBoardItems(Request $request, int $boardId): JsonResponse
    {
        $this->logRequest("GET /service/boards/{$boardId}/items", $request);

        return response()->json([]);
    }

    public function listPriorities(Request $request): JsonResponse
    {
        $this->logRequest('GET /service/priorities', $request);

        return response()->json(T2TFieldMapper::priorities());
    }

    public function listSources(Request $request): JsonResponse
    {
        $this->logRequest('GET /service/sources', $request);

        return response()->json([
            ['id' => 1, 'name' => 'Helpdesk Button', 'defaultFlag' => false, 'enteredByFlag' => false],
            ['id' => 2, 'name' => 'Huntress', 'defaultFlag' => true, 'enteredByFlag' => false],
        ]);
    }

    public function systemInfo(Request $request): JsonResponse
    {
        $this->logRequest('GET /system/info', $request);

        return response()->json(T2TFieldMapper::systemInfo());
    }

    // ── Authenticated: Company & Ticket Operations ──

    public function listCompanies(Request $request): JsonResponse
    {
        $this->logRequest('GET /company/companies', $request);

        $query = \App\Models\Client::active()->orderBy('name');

        // Parse CW-style conditions: name LIKE '%term%', id IN (1,2)
        $conditions = $request->query('conditions', '');
        if (preg_match('/name\s+LIKE\s+\'%(.+?)%\'/i', $conditions, $m)) {
            $query->where('name', 'like', '%'.trim($m[1]).'%');
        }
        if (preg_match('/id\s+IN\s*\(([0-9,\s]+)\)/i', $conditions, $m)) {
            $ids = array_map('intval', explode(',', $m[1]));
            $query->whereIn('id', $ids);
        }

        $pageSize = (int) ($request->query('pagesize') ?: 100);
        $page = (int) ($request->query('page') ?: 1);
        $query->limit($pageSize)->offset(($page - 1) * $pageSize);

        return response()->json(
            $query->get()->map(fn ($c) => T2TFieldMapper::clientToCwCompany($c))->values()
        );
    }

    public function countCompanies(Request $request): JsonResponse
    {
        $this->logRequest('GET /company/companies/count', $request);

        return response()->json([
            'count' => \App\Models\Client::active()->count(),
        ]);
    }

    public function getTicket(Request $request, int $id): JsonResponse
    {
        $this->logRequest("GET /service/tickets/{$id}", $request);

        $ticket = Ticket::with(['client', 'contact'])->find($id);

        if (! $ticket) {
            return response()->json(['code' => 'NotFound', 'message' => 'Ticket not found'], 404);
        }

        return response()->json(T2TFieldMapper::ticketToCwFormat($ticket));
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

        if ($ticket->source !== \App\Enums\TicketSource::Huntress) {
            return response()->json(['code' => 'Forbidden', 'message' => 'Cannot modify this ticket'], 403);
        }

        // Use json() to handle JSON Patch arrays ([{op, path, value}])
        // $request->all() doesn't parse root-level JSON arrays correctly
        $data = $request->json()->all();

        try {
            $result = $this->service->updateTicketFromCw($ticket, $data);

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'code' => 'InvalidArgument',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ── Catch-All ──

    public function catchAll(Request $request, string $path = ''): JsonResponse
    {
        Log::info('[Huntress CW] Unmapped endpoint called', [
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
        Log::info('[Huntress CW] '.$endpoint, [
            'query' => $request->query(),
            'ip' => $request->ip(),
        ]);

        if (config('app.debug')) {
            Log::debug('[Huntress CW] Request body: '.$endpoint, [
                'body' => $request->all(),
            ]);
        }
    }
}
