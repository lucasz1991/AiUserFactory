<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalHeartbeatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RotateCookieEncryptionKey extends Command
{
    protected $signature = 'security:rotate-cookie-encryption
        {--chunk=100 : Datensaetze pro Transaktion}
        {--dry-run : Nur Entschluesselbarkeit pruefen}';

    protected $description = 'Verschluesselt Person-Cookie-Payloads mit dem aktuellen APP_KEY neu.';

    public function handle(OperationalHeartbeatService $heartbeats): int
    {
        if (! Schema::hasTable('persons')) {
            $this->info('Keine persons-Tabelle vorhanden.');

            return self::SUCCESS;
        }

        $chunkSize = max(1, min(1000, (int) $this->option('chunk')));
        $dryRun = (bool) $this->option('dry-run');
        $checked = 0;
        $rotated = 0;
        $failed = 0;

        DB::table('persons')
            ->select(['id', 'cookie_payload'])
            ->whereNotNull('cookie_payload')
            ->where('cookie_payload', '!=', '')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($persons) use ($dryRun, &$checked, &$rotated, &$failed): void {
                foreach ($persons as $person) {
                    $checked++;

                    try {
                        $plainText = Crypt::decryptString((string) $person->cookie_payload);

                        if (! $dryRun) {
                            DB::table('persons')->where('id', $person->id)->update([
                                'cookie_payload' => Crypt::encryptString($plainText),
                            ]);
                            $rotated++;
                        }
                    } catch (Throwable) {
                        // Weder Ciphertext noch Exception-Message ausgeben:
                        // beide koennen sensible Sitzungsdaten enthalten.
                        $failed++;
                        $this->error('Cookie-Payload fuer Person-ID '.(int) $person->id.' konnte nicht rotiert werden.');
                    }
                }
            });

        $result = [
            'checked' => $checked,
            'rotated' => $rotated,
            'failed' => $failed,
        ];

        if (! $dryRun && $failed === 0) {
            $heartbeats->recordCookieKeyRotation($result);
        }

        $this->info(sprintf(
            '%sGeprueft: %d, neu verschluesselt: %d, fehlgeschlagen: %d.',
            $dryRun ? '[dry-run] ' : '',
            $checked,
            $rotated,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
