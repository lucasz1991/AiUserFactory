<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureReservationColumn('network_nodes', 'network_nodes_workflow_reservation_fk');
        $this->ensureReservationColumn('devices', 'devices_workflow_reservation_fk');

        $this->addNetworkJobColumn('control_command', function (Blueprint $table): void {
            $table->string('control_command', 40)->nullable()->after('attempt_count')->index();
        });
        $this->addNetworkJobColumn('control_sequence', function (Blueprint $table): void {
            $table->unsignedBigInteger('control_sequence')->default(0)->after('control_command');
        });
        $this->addNetworkJobColumn('control_payload_json', function (Blueprint $table): void {
            $table->json('control_payload_json')->nullable()->after('control_sequence');
        });
        $this->addNetworkJobColumn('control_requested_at', function (Blueprint $table): void {
            $table->timestamp('control_requested_at')->nullable()->after('control_payload_json');
        });
        $this->addNetworkJobColumn('control_acknowledged_at', function (Blueprint $table): void {
            $table->timestamp('control_acknowledged_at')->nullable()->after('control_requested_at');
        });
        $this->addNetworkJobColumn('control_deadline_at', function (Blueprint $table): void {
            $table->timestamp('control_deadline_at')->nullable()->after('control_acknowledged_at')->index();
        });
        $this->addNetworkJobColumn('unreachable_at', function (Blueprint $table): void {
            $table->timestamp('unreachable_at')->nullable()->after('control_deadline_at')->index();
        });
    }

    public function down(): void
    {
        foreach ([
            'unreachable_at',
            'control_deadline_at',
            'control_acknowledged_at',
            'control_requested_at',
            'control_payload_json',
            'control_sequence',
            'control_command',
        ] as $column) {
            if (Schema::hasColumn('network_jobs', $column)) {
                Schema::table('network_jobs', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        $this->dropReservationColumn('devices');
        $this->dropReservationColumn('network_nodes');
    }

    protected function ensureReservationColumn(string $tableName, string $foreignKeyName): void
    {
        if (! Schema::hasColumn($tableName, 'workflow_reservation_run_id')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('workflow_reservation_run_id')->nullable()->after('status')->index();
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->foreignKeys($tableName, 'workflow_reservation_run_id') as $foreignKey) {
            if ($foreignKey !== '1') {
                return;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                str_replace('`', '``', $tableName),
                str_replace('`', '``', $foreignKey),
            ));
        }

        // Existing valid reservations remain untouched. Only orphaned references are cleared.
        DB::statement(sprintf(
            'UPDATE `%1$s` target LEFT JOIN `workflow_runs` runs ON runs.id = target.workflow_reservation_run_id '
            .'SET target.workflow_reservation_run_id = NULL '
            .'WHERE target.workflow_reservation_run_id IS NOT NULL AND runs.id IS NULL',
            str_replace('`', '``', $tableName),
        ));

        if ($this->foreignKeys($tableName, 'workflow_reservation_run_id') !== []) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`workflow_reservation_run_id`) REFERENCES `workflow_runs` (`id`) ON DELETE SET NULL',
            str_replace('`', '``', $tableName),
            str_replace('`', '``', $foreignKeyName),
        ));
    }

    protected function addNetworkJobColumn(string $column, callable $definition): void
    {
        if (! Schema::hasColumn('network_jobs', $column)) {
            Schema::table('network_jobs', $definition);
        }
    }

    /** @return list<string> */
    protected function foreignKeys(string $tableName, string $column): array
    {
        return collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
            .'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
            .'AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$tableName, $column],
        ))
            ->map(fn (object $row): string => (string) $row->CONSTRAINT_NAME)
            ->values()
            ->all();
    }

    protected function dropReservationColumn(string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'workflow_reservation_run_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            foreach ($this->foreignKeys($tableName, 'workflow_reservation_run_id') as $foreignKey) {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                    str_replace('`', '``', $tableName),
                    str_replace('`', '``', $foreignKey),
                ));
            }
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('workflow_reservation_run_id'));
    }
};
