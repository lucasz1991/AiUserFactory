<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('persons')
            ->select(['id', 'cookie_payload'])
            ->whereNotNull('cookie_payload')
            ->orderBy('id')
            ->chunkById(100, function ($persons): void {
                foreach ($persons as $person) {
                    $payload = (string) $person->cookie_payload;

                    if ($payload === '' || $this->isEncrypted($payload)) {
                        continue;
                    }

                    DB::table('persons')->where('id', $person->id)->update([
                        'cookie_payload' => Crypt::encryptString($payload),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('persons')
            ->select(['id', 'cookie_payload'])
            ->whereNotNull('cookie_payload')
            ->orderBy('id')
            ->chunkById(100, function ($persons): void {
                foreach ($persons as $person) {
                    $payload = (string) $person->cookie_payload;

                    if ($payload === '') {
                        continue;
                    }

                    try {
                        $plainText = Crypt::decryptString($payload);
                    } catch (Throwable) {
                        continue;
                    }

                    DB::table('persons')->where('id', $person->id)->update([
                        'cookie_payload' => $plainText,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    private function isEncrypted(string $payload): bool
    {
        try {
            Crypt::decryptString($payload);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
