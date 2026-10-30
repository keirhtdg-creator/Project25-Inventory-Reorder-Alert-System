<?php

namespace Database\Seeders;

use App\Services\InventoryWorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $workflow = app(InventoryWorkflowService::class);
        $items = [
            [
                'sku' => 'SUP-001',
                'name' => 'Printer paper',
                'unit' => 'ream',
                'on_hand_quantity' => 28,
                'reorder_threshold' => 12,
                'suggested_reorder_quantity' => 40,
            ],
            [
                'sku' => 'SUP-002',
                'name' => 'Black toner cartridge',
                'unit' => 'piece',
                'on_hand_quantity' => 3,
                'reorder_threshold' => 5,
                'suggested_reorder_quantity' => 8,
            ],
            [
                'sku' => 'LAB-001',
                'name' => 'Disposable gloves',
                'unit' => 'box',
                'on_hand_quantity' => 7,
                'reorder_threshold' => 10,
                'suggested_reorder_quantity' => 20,
            ],
        ];

        foreach ($items as $item) {
            if (! DB::table('inventory_items')->where('sku', $item['sku'])->exists()) {
                $workflow->createItem($item);
            }
        }
    }
}
