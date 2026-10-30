<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE = 'reorder_requests_source_alert_id_unique';

    private const PLAIN = 'reorder_requests_source_alert_id_index';

    /**
     * One alert episode may have several reorder requests over time.
     * MySQL/MariaDB InnoDB needs an index to back the source_alert_id foreign key,
     * so a plain index is ensured before the unique index is dropped. The foreign key is untouched.
     */
    public function up(): void
    {
        if (! $this->hasPlainSourceAlertIndex()) {
            Schema::table('reorder_requests', function (Blueprint $table): void {
                $table->index('source_alert_id', self::PLAIN);
            });
        }

        if (Schema::hasIndex('reorder_requests', self::UNIQUE)) {
            Schema::table('reorder_requests', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE);
            });
        }
    }

    /**
     * Restores the unique index (fails if an alert already has several reorder requests,
     * which is the intended data that rollback cannot preserve). The unique index is added
     * before the plain index created by up() is removed, so the foreign key stays supported.
     * A plain index that existed under another name is left in place.
     */
    public function down(): void
    {
        if (! Schema::hasIndex('reorder_requests', self::UNIQUE)) {
            Schema::table('reorder_requests', function (Blueprint $table): void {
                $table->unique('source_alert_id', self::UNIQUE);
            });
        }

        if (Schema::hasIndex('reorder_requests', self::PLAIN)) {
            Schema::table('reorder_requests', function (Blueprint $table): void {
                $table->dropIndex(self::PLAIN);
            });
        }
    }

    private function hasPlainSourceAlertIndex(): bool
    {
        foreach (Schema::getIndexes('reorder_requests') as $index) {
            if (($index['columns'] ?? []) === ['source_alert_id'] && ! $index['unique'] && ! $index['primary']) {
                return true;
            }
        }

        return false;
    }
};
