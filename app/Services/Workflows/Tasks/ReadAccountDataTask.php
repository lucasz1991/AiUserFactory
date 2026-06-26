<?php

namespace App\Services\Workflows\Tasks;

class ReadAccountDataTask
{
    public function handle(array $result): array
    {
        $account = is_array($result['account'] ?? null) ? $result['account'] : [];

        if ($account === []) {
            return [
                'ok' => false,
                'status' => 'failed',
                'statusMessage' => 'Keine Accountdaten im Ergebnis gefunden.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Accountdaten wurden gelesen.',
            'account' => collect($account)->except(['password', 'passwordEncrypted'])->all(),
        ];
    }
}
