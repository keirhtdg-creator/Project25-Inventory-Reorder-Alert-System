<?php

namespace App\Services;

use App\Logging\AutomationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryWorkflowService
{
    private const ACTIVE_REORDER_STATUSES = [
        'pending_review',
        'approved',
        'ordered',
        'partially_received',
        'received',
    ];

    public function __construct(private readonly AutomationLog $log) {}

    public function createItem(array $values): int
    {
        return DB::transaction(function () use ($values): int {
            $now = now();
            $itemId = DB::table('inventory_items')->insertGetId([
                'sku' => $values['sku'],
                'name' => $values['name'],
                'unit' => $values['unit'],
                'on_hand_quantity' => $values['on_hand_quantity'],
                'reorder_threshold' => $values['reorder_threshold'],
                'suggested_reorder_quantity' => $values['suggested_reorder_quantity'],
                'is_approved' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('stock_transactions')->insert([
                'item_id' => $itemId,
                'transaction_type' => 'initial',
                'quantity_delta' => $values['on_hand_quantity'],
                'resulting_quantity' => $values['on_hand_quantity'],
                'reason' => 'Initial inventory balance',
                'actor_label' => 'Demo user',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordEvent('inventory_item', $itemId, 'item.created', null, null, [
                'sku' => $values['sku'],
            ]);
            $this->evaluateThreshold($itemId);

            return $itemId;
        });
    }

    public function recordStockTransaction(int $itemId, string $type, float $quantity, ?string $reason): array
    {
        return DB::transaction(function () use ($itemId, $type, $quantity, $reason): array {
            $item = DB::table('inventory_items')->where('id', $itemId)->lockForUpdate()->first();
            if (! $item) {
                abort(404, 'Inventory item not found.');
            }

            $delta = match ($type) {
                'issue' => -$quantity,
                'receipt' => $quantity,
                'adjustment' => $quantity,
                default => throw ValidationException::withMessages(['type' => 'Unsupported transaction type.']),
            };
            $resultingQuantity = (float) $item->on_hand_quantity + $delta;
            if ($resultingQuantity < 0) {
                throw ValidationException::withMessages(['quantity' => 'The transaction would make stock negative.']);
            }

            $transactionId = $this->insertStockTransaction($itemId, $type, $delta, $resultingQuantity, $reason);
            DB::table('inventory_items')->where('id', $itemId)->update([
                'on_hand_quantity' => $resultingQuantity,
                'updated_at' => now(),
            ]);
            $this->recordEvent('stock_transaction', $transactionId, 'stock.transaction_recorded', null, null, [
                'item_id' => $itemId,
                'type' => $type,
                'quantity_delta' => $delta,
                'resulting_quantity' => $resultingQuantity,
            ]);
            $decision = $this->evaluateThreshold($itemId);

            return [
                'transaction_id' => $transactionId,
                'item_id' => $itemId,
                'on_hand_quantity' => $resultingQuantity,
                'threshold_alert' => $decision['alert'],
                'reorder_recommendation' => $decision['reorder'],
            ];
        });
    }

    public function acknowledgeAlert(int $alertId): object
    {
        return DB::transaction(function () use ($alertId): object {
            $alert = DB::table('inventory_alerts')->where('id', $alertId)->lockForUpdate()->first();
            if (! $alert) {
                abort(404, 'Alert not found.');
            }
            if ($alert->status === 'acknowledged') {
                return $alert;
            }
            if ($alert->status !== 'open') {
                throw new ConflictHttpException('Only open alerts can be acknowledged.');
            }

            $now = now();
            DB::table('inventory_alerts')->where('id', $alertId)->update([
                'status' => 'acknowledged',
                'acknowledged_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordEvent('inventory_alert', $alertId, 'alert.acknowledged', 'open', 'acknowledged');

            return DB::table('inventory_alerts')->where('id', $alertId)->first();
        });
    }

    public function reviewReorder(int $requestId, string $decision, ?string $note): object
    {
        return DB::transaction(function () use ($requestId, $decision, $note): object {
            $reorder = DB::table('reorder_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (! $reorder) {
                abort(404, 'Reorder request not found.');
            }
            if ($reorder->status !== 'pending_review') {
                throw new ConflictHttpException('Only pending recommendations can be reviewed.');
            }

            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $now = now();
            DB::table('reorder_requests')->where('id', $requestId)->update([
                'status' => $status,
                'review_note' => $note,
                'status_changed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordEvent('reorder_request', $requestId, 'reorder.reviewed', $reorder->status, $status, [
                'note' => $note,
            ]);

            return DB::table('reorder_requests')->where('id', $requestId)->first();
        });
    }

    public function updateProcurementStatus(int $requestId, string $status): object
    {
        return DB::transaction(function () use ($requestId, $status): object {
            $reorder = DB::table('reorder_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (! $reorder) {
                abort(404, 'Reorder request not found.');
            }
            if ($reorder->status !== 'approved' || $status !== 'ordered') {
                throw new ConflictHttpException('Only approved requests can be marked ordered.');
            }

            $now = now();
            DB::table('reorder_requests')->where('id', $requestId)->update([
                'status' => 'ordered',
                'status_changed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordEvent('reorder_request', $requestId, 'reorder.procurement_status_changed', $reorder->status, 'ordered');

            return DB::table('reorder_requests')->where('id', $requestId)->first();
        });
    }

    public function receiveReorder(int $requestId, float $quantity): object
    {
        return DB::transaction(function () use ($requestId, $quantity): object {
            $reorderLookup = DB::table('reorder_requests')->where('id', $requestId)->first();
            if (! $reorderLookup) {
                abort(404, 'Reorder request not found.');
            }
            $item = DB::table('inventory_items')
                ->where('id', $reorderLookup->item_id)
                ->lockForUpdate()
                ->first();
            $reorder = DB::table('reorder_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (! $item || ! $reorder) {
                abort(404, 'The reorder item is no longer available.');
            }
            if (! in_array($reorder->status, ['ordered', 'partially_received'], true)) {
                throw new ConflictHttpException('Only ordered requests can receive stock.');
            }

            $remaining = (float) $reorder->suggested_quantity - (float) $reorder->received_quantity;
            if ($quantity > $remaining) {
                throw ValidationException::withMessages(['quantity' => 'Received quantity cannot exceed the remaining reorder quantity.']);
            }

            $resultingQuantity = (float) $item->on_hand_quantity + $quantity;
            $transactionId = $this->insertStockTransaction(
                (int) $item->id,
                'receipt',
                $quantity,
                $resultingQuantity,
                'Reorder receipt #'.$requestId,
            );
            DB::table('inventory_items')->where('id', $item->id)->update([
                'on_hand_quantity' => $resultingQuantity,
                'updated_at' => now(),
            ]);

            $receivedTotal = (float) $reorder->received_quantity + $quantity;
            $status = $receivedTotal >= (float) $reorder->suggested_quantity ? 'received' : 'partially_received';
            $now = now();
            DB::table('reorder_requests')->where('id', $requestId)->update([
                'received_quantity' => $receivedTotal,
                'status' => $status,
                'status_changed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordEvent('stock_transaction', $transactionId, 'stock.receipt_recorded', null, null, [
                'reorder_request_id' => $requestId,
                'item_id' => $item->id,
                'quantity' => $quantity,
                'resulting_quantity' => $resultingQuantity,
            ]);
            $this->recordEvent('reorder_request', $requestId, 'reorder.receipt_status_changed', $reorder->status, $status);
            $this->evaluateThreshold((int) $item->id);

            return DB::table('reorder_requests')->where('id', $requestId)->first();
        });
    }

    public function closeReorder(int $requestId, string $note): object
    {
        return DB::transaction(function () use ($requestId, $note): object {
            $reorder = DB::table('reorder_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (! $reorder) {
                abort(404, 'Reorder request not found.');
            }
            if ($reorder->status !== 'received') {
                throw new ConflictHttpException('Only fully received requests can be closed.');
            }

            $now = now();
            DB::table('reorder_requests')->where('id', $requestId)->update([
                'status' => 'closed',
                'resolution_note' => $note,
                'status_changed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordEvent('reorder_request', $requestId, 'reorder.closed', 'received', 'closed', [
                'note' => $note,
            ]);

            return DB::table('reorder_requests')->where('id', $requestId)->first();
        });
    }

    public function resolveManualAction(int $actionId, string $note): object
    {
        return DB::transaction(function () use ($actionId, $note): object {
            $action = DB::table('manual_actions')->where('id', $actionId)->lockForUpdate()->first();
            if (! $action) {
                abort(404, 'Manual action not found.');
            }
            if ($action->status === 'resolved') {
                throw new ConflictHttpException('This manual action has already been resolved.');
            }

            $now = now();
            DB::table('manual_actions')->where('id', $actionId)->update([
                'status' => 'resolved',
                'resolution_note' => $note,
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);
            $this->recordEvent('manual_action', $actionId, 'manual_action.resolved', 'open', 'resolved', [
                'note' => $note,
            ]);

            return DB::table('manual_actions')->where('id', $actionId)->first();
        });
    }

    public function recordEvent(
        string $subjectType,
        int $subjectId,
        string $eventType,
        ?string $previousState,
        ?string $currentState,
        array $details = [],
        string $actorLabel = 'Demo user',
    ): void {
        $correlationId = $this->log->correlationId();

        DB::table('workflow_events')->insert([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event_type' => $eventType,
            'actor_label' => $actorLabel,
            'previous_state' => $previousState,
            'current_state' => $currentState,
            'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
            'correlation_id' => $correlationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertStockTransaction(
        int $itemId,
        string $type,
        float $delta,
        float $resultingQuantity,
        ?string $reason,
    ): int {
        return (int) DB::table('stock_transactions')->insertGetId([
            'item_id' => $itemId,
            'transaction_type' => $type,
            'quantity_delta' => $delta,
            'resulting_quantity' => $resultingQuantity,
            'reason' => $reason,
            'actor_label' => 'Demo user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function evaluateThreshold(int $itemId): array
    {
        $timer = $this->log->startTimer();
        $item = DB::table('inventory_items')->where('id', $itemId)->lockForUpdate()->first();
        if (! $item || ! $item->is_approved) {
            return ['alert' => 'not_applicable', 'reorder' => 'not_applicable'];
        }

        $isBelowThreshold = (float) $item->on_hand_quantity <= (float) $item->reorder_threshold;
        if (! $isBelowThreshold) {
            DB::table('inventory_alerts')
                ->where('item_id', $itemId)
                ->whereIn('status', ['open', 'acknowledged'])
                ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);

            return ['alert' => 'resolved_if_open', 'reorder' => 'unchanged'];
        }

        $alert = DB::table('inventory_alerts')
            ->where('item_id', $itemId)
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('episode_number')
            ->first();
        $alertDecision = 'retained';
        if (! $alert) {
            $episodeNumber = (int) DB::table('inventory_alerts')
                ->where('item_id', $itemId)
                ->max('episode_number') + 1;
            $alertId = DB::table('inventory_alerts')->insertGetId([
                'item_id' => $itemId,
                'episode_number' => $episodeNumber,
                'status' => 'open',
                'triggered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $alert = DB::table('inventory_alerts')->where('id', $alertId)->first();
            $alertDecision = 'opened';
            $this->recordEvent('inventory_alert', $alertId, 'alert.opened', null, 'open', [
                'item_id' => $itemId,
                'quantity' => $item->on_hand_quantity,
                'threshold' => $item->reorder_threshold,
            ]);
        }

        $activeRequest = DB::table('reorder_requests')
            ->where('item_id', $itemId)
            ->whereIn('status', self::ACTIVE_REORDER_STATUSES)
            ->lockForUpdate()
            ->first();
        if ($activeRequest) {
            $reorderDecision = 'suppressed_active_request';
        } else {
            $requestId = DB::table('reorder_requests')->insertGetId([
                'item_id' => $itemId,
                'source_alert_id' => $alert->id,
                'suggested_quantity' => $item->suggested_reorder_quantity,
                'received_quantity' => 0,
                'status' => 'pending_review',
                'status_changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $reorderDecision = 'created';
            $this->recordEvent('reorder_request', $requestId, 'reorder.recommended', null, 'pending_review', [
                'item_id' => $itemId,
                'alert_id' => $alert->id,
            ]);
        }

        $this->log->write('info', 'inventory.threshold_evaluated', [
            'inventory_item_id' => $itemId,
            'reorder_request_id' => $requestId ?? $activeRequest->id ?? null,
            'quantity' => $item->on_hand_quantity,
            'threshold' => $item->reorder_threshold,
            'alert_decision' => $alertDecision,
            'reorder_decision' => $reorderDecision,
            'outcome' => $reorderDecision,
            'duration_ms' => $this->log->elapsedMs($timer),
        ]);

        return ['alert' => $alertDecision, 'reorder' => $reorderDecision];
    }
}
