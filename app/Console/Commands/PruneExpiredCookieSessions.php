<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Services\Operations\OperationalHeartbeatService;
use App\Services\Security\CookieFilePathPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class PruneExpiredCookieSessions extends Command
{
    protected $signature = 'security:prune-cookie-sessions
        {--days= : Aufbewahrung in Tagen; Standard aus config/security.php}
        {--dry-run : Nur anzeigen, was geloescht wuerde}';

    protected $description = 'Entfernt abgelaufene Cookie-Sitzungen aus privatem Storage und der Person-Tabelle.';

    public function handle(
        CookieFilePathPolicy $paths,
        OperationalHeartbeatService $heartbeats,
    ): int {
        if (! Schema::hasTable('persons')) {
            $this->info('Keine persons-Tabelle vorhanden.');

            return self::SUCCESS;
        }

        $daysOption = trim((string) $this->option('days'));
        $days = $daysOption === ''
            ? max(1, (int) config('security.cookie_files.retention_days', 30))
            : max(1, (int) $daysOption);
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now('UTC')->subDays($days);
        $result = [
            'payloads_pruned' => 0,
            'files_deleted' => 0,
            'recent_files_skipped' => 0,
            'unsafe_files_skipped' => 0,
        ];

        Person::withTrashed()
            ->select(['id', 'cookie_file_path', 'cookies_synced_at', 'updated_at'])
            ->whereNotNull('cookie_payload')
            ->where(function ($query) use ($cutoff): void {
                $query->where('cookies_synced_at', '<', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('cookies_synced_at')->where('updated_at', '<', $cutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($persons) use ($paths, $cutoff, $dryRun, &$result): void {
                foreach ($persons as $person) {
                    $path = $paths->resolve($person->cookie_file_path, storage_path('app'));

                    if ($path !== null && File::exists($path)) {
                        if ($paths->containsSymbolicLink($path) || ! File::isFile($path)) {
                            $result['unsafe_files_skipped']++;
                        } else {
                            $modifiedAt = @filemtime($path);

                            if ($modifiedAt !== false && $modifiedAt >= $cutoff->getTimestamp()) {
                                $result['recent_files_skipped']++;

                                continue;
                            }

                            if ($dryRun) {
                                $result['files_deleted']++;
                            } elseif (File::delete($path)) {
                                $result['files_deleted']++;
                            } else {
                                $result['unsafe_files_skipped']++;
                            }
                        }
                    } elseif (filled($person->cookie_file_path)) {
                        // Ein alter externer/oeffentlicher Pfad wird niemals
                        // automatisch angefasst; die DB-Kopie wird dennoch
                        // fristgerecht entfernt und der Operator gewarnt.
                        $result['unsafe_files_skipped']++;
                    }

                    if (! $dryRun) {
                        DB::table('persons')->where('id', $person->id)->update([
                            'cookie_payload' => null,
                            'cookie_payload_hash' => null,
                            'cookie_count' => 0,
                            'session_cookie_present' => false,
                            'cookies_synced_at' => null,
                        ]);
                    }

                    $result['payloads_pruned']++;
                }
            });

        if (! $dryRun) {
            $heartbeats->recordCookiePrune($result);
        }

        $this->info(sprintf(
            '%sCookie-Payloads: %d, Dateien: %d, frische Dateien uebersprungen: %d, unsichere Pfade uebersprungen: %d.',
            $dryRun ? '[dry-run] ' : '',
            $result['payloads_pruned'],
            $result['files_deleted'],
            $result['recent_files_skipped'],
            $result['unsafe_files_skipped'],
        ));

        return $result['unsafe_files_skipped'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
