<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * On MariaDB (explicit_defaults_for_timestamp = OFF) the first NOT NULL TIMESTAMP column of a
     * table silently becomes DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, so triggered_at
     * was rewritten by every acknowledgement and resolution. The explicit default below has no
     * ON UPDATE clause. MODIFY keeps existing values, the foreign key and the unique index.
     * Other drivers (SQLite) never had the behaviour and are left alone.
     */
    public function up(): void
    {
        if (! $this->isMySqlFamily()) {
            return;
        }

        DB::statement('ALTER TABLE `inventory_alerts` MODIFY `triggered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    /**
     * Restores the definition the original create migration produced on this server: with
     * explicit_defaults_for_timestamp OFF, a bare NOT NULL TIMESTAMP regains the implicit
     * auto-update behaviour; with it ON, the column has no default, exactly as originally created.
     */
    public function down(): void
    {
        if (! $this->isMySqlFamily()) {
            return;
        }

        DB::statement('ALTER TABLE `inventory_alerts` MODIFY `triggered_at` TIMESTAMP NOT NULL');
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
