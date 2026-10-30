<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InventoryWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    private const MAX_QUANTITY = 99999999999.999;

    public function __construct(private readonly InventoryWorkflowService $workflow) {}

    public function items(): JsonResponse
    {
        $items = DB::table('inventory_items')
            ->where('is_approved', true)
            ->orderBy('name')
            ->get()
            ->map(fn (object $item): array => [
                ...((array) $item),
                'below_threshold' => (float) $item->on_hand_quantity <= (float) $item->reorder_threshold,
            ]);

        return response()->json(['data' => $items]);
    }

    public function item(int $itemId): JsonResponse
    {
        $item = DB::table('inventory_items')->where('id', $itemId)->where('is_approved', true)->first();
        if (! $item) {
            abort(404, 'Inventory item not found.');
        }

        $alert = DB::table('inventory_alerts')
            ->where('item_id', $itemId)
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('episode_number')
            ->first();
        $reorder = DB::table('reorder_requests')
            ->where('item_id', $itemId)
            ->whereIn('status', ['pending_review', 'approved', 'ordered', 'partially_received', 'received'])
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => [
                'item' => $item,
                'below_threshold' => (float) $item->on_hand_quantity <= (float) $item->reorder_threshold,
                'current_alert' => $alert,
                'active_reorder' => $reorder,
            ],
        ]);
    }

    public function createItem(Request $request): JsonResponse
    {
        $values = $request->validate([
            'sku' => ['required', 'string', 'max:40', 'unique:inventory_items,sku'],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:30'],
            'on_hand_quantity' => ['required', 'numeric', 'decimal:0,3', 'between:0,'.self::MAX_QUANTITY],
            'reorder_threshold' => ['required', 'numeric', 'decimal:0,3', 'between:0,'.self::MAX_QUANTITY],
            'suggested_reorder_quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:'.self::MAX_QUANTITY],
        ]);

        $itemId = $this->workflow->createItem($values);

        return response()->json(['data' => $this->itemData($itemId)], 201);
    }

    public function createStockTransaction(Request $request, int $itemId): JsonResponse
    {
        $values = $request->validate([
            'type' => ['required', 'in:issue,receipt,adjustment'],
            'quantity' => [
                'required',
                'numeric',
                'decimal:0,3',
                'between:-'.self::MAX_QUANTITY.','.self::MAX_QUANTITY,
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $quantity = (float) $values['quantity'];
        if ($quantity === 0.0 || ($values['type'] !== 'adjustment' && $quantity < 0)) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be non-zero; issue and receipt quantities must be positive.',
            ]);
        }

        $result = $this->workflow->recordStockTransaction(
            $itemId,
            $values['type'],
            $quantity,
            $values['reason'] ?? null,
        );

        return response()->json(['data' => $result], 201);
    }

    public function alerts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,acknowledged,resolved'],
            'item_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = DB::table('inventory_alerts')
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_alerts.item_id')
            ->select('inventory_alerts.*', 'inventory_items.sku', 'inventory_items.name as item_name')
            ->orderByDesc('inventory_alerts.triggered_at');
        if (isset($filters['status'])) {
            $query->where('inventory_alerts.status', $filters['status']);
        }
        if (isset($filters['item_id'])) {
            $query->where('inventory_alerts.item_id', $filters['item_id']);
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }

    public function acknowledgeAlert(int $alertId): JsonResponse
    {
        return response()->json(['data' => $this->workflow->acknowledgeAlert($alertId)]);
    }

    public function reorders(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending_review,approved,rejected,ordered,partially_received,received,closed,cancelled'],
            'item_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = DB::table('reorder_requests')
            ->join('inventory_items', 'inventory_items.id', '=', 'reorder_requests.item_id')
            ->select('reorder_requests.*', 'inventory_items.sku', 'inventory_items.name as item_name', 'inventory_items.unit')
            ->orderByDesc('reorder_requests.created_at');
        if (isset($filters['status'])) {
            $query->where('reorder_requests.status', $filters['status']);
        }
        if (isset($filters['item_id'])) {
            $query->where('reorder_requests.item_id', $filters['item_id']);
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }

    public function reviewReorder(Request $request, int $requestId): JsonResponse
    {
        $values = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'data' => $this->workflow->reviewReorder($requestId, $values['decision'], $values['note'] ?? null),
        ]);
    }

    public function updateProcurementStatus(Request $request, int $requestId): JsonResponse
    {
        $values = $request->validate(['status' => ['required', 'in:ordered']]);

        return response()->json([
            'data' => $this->workflow->updateProcurementStatus($requestId, $values['status']),
        ]);
    }

    public function receiveReorder(Request $request, int $requestId): JsonResponse
    {
        $values = $request->validate([
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:'.self::MAX_QUANTITY],
        ]);

        return response()->json([
            'data' => $this->workflow->receiveReorder($requestId, (float) $values['quantity']),
        ]);
    }

    public function closeReorder(Request $request, int $requestId): JsonResponse
    {
        $values = $request->validate(['resolution_note' => ['required', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->workflow->closeReorder($requestId, $values['resolution_note']),
        ]);
    }

    public function weeklySummaries(Request $request): JsonResponse
    {
        $filters = $request->validate(['week_start' => ['nullable', 'date_format:Y-m-d']]);
        $query = DB::table('weekly_stock_summaries')->orderByDesc('week_start');
        if (isset($filters['week_start'])) {
            $query->where('week_start', $filters['week_start']);
        }

        $summaries = $query->limit(52)->get()->map(function (object $summary): array {
            $summary->items = DB::table('weekly_stock_summary_items')
                ->join('inventory_items', 'inventory_items.id', '=', 'weekly_stock_summary_items.item_id')
                ->where('summary_id', $summary->id)
                ->select('weekly_stock_summary_items.*', 'inventory_items.sku', 'inventory_items.name as item_name', 'inventory_items.unit')
                ->orderBy('inventory_items.name')
                ->get();

            return (array) $summary;
        });

        return response()->json(['data' => $summaries]);
    }

    public function manualActions(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', 'in:open,resolved']]);
        $query = DB::table('manual_actions')
            ->leftJoin('reorder_requests', 'reorder_requests.id', '=', 'manual_actions.reorder_request_id')
            ->leftJoin('inventory_items', 'inventory_items.id', '=', 'reorder_requests.item_id')
            ->select('manual_actions.*', 'inventory_items.sku', 'inventory_items.name as item_name')
            ->orderByDesc('manual_actions.created_at');
        if (isset($filters['status'])) {
            $query->where('manual_actions.status', $filters['status']);
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }

    public function resolveManualAction(Request $request, int $actionId): JsonResponse
    {
        $values = $request->validate(['resolution_note' => ['required', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->workflow->resolveManualAction($actionId, $values['resolution_note']),
        ]);
    }

    private function itemData(int $itemId): object
    {
        return DB::table('inventory_items')->where('id', $itemId)->firstOrFail();
    }
}
