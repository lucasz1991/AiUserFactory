<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NetworkJobRealtimeMigrationTest extends TestCase
{
    public function test_realtime_metrics_migration_can_be_reversed_and_reapplied_without_losing_jobs(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $testConnection = 'realtime-migration-test';
        config(['database.connections.'.$testConnection => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection($testConnection);

        try {
            Schema::create('network_jobs', function (Blueprint $table): void {
                $table->id();
                $table->timestamp('queued_at')->nullable();
            });
            DB::table('network_jobs')->insert(['id' => 7, 'queued_at' => '2026-10-03 12:00:00']);
            $migration = require database_path('migrations/2026_08_22_000200_add_realtime_delivery_metrics_to_network_jobs.php');

            $migration->up();

            foreach (['signaled_at', 'pulled_at', 'started_at'] as $column) {
                $this->assertTrue(Schema::hasColumn('network_jobs', $column));
                $this->assertTrue(Schema::hasIndex('network_jobs', [$column]));
            }

            DB::table('network_jobs')->where('id', 7)->update(['signaled_at' => '2026-10-03 12:00:01']);
            $migration->down();

            foreach (['signaled_at', 'pulled_at', 'started_at'] as $column) {
                $this->assertFalse(Schema::hasColumn('network_jobs', $column));
                $this->assertFalse(Schema::hasIndex('network_jobs', [$column]));
            }

            $this->assertSame('2026-10-03 12:00:00', DB::table('network_jobs')->where('id', 7)->value('queued_at'));
            $migration->up();
            $this->assertNull(DB::table('network_jobs')->where('id', 7)->value('signaled_at'));
            $this->assertSame(1, DB::table('network_jobs')->count());
            $migration->down();
            $this->assertSame(1, DB::table('network_jobs')->count());
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge($testConnection);
        }
    }
}
