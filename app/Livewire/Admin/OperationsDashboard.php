<?php

namespace App\Livewire\Admin;

use App\Services\Operations\OperationalMetricsService;
use Livewire\Component;

class OperationsDashboard extends Component
{
    public function render(OperationalMetricsService $metrics)
    {
        return view('livewire.admin.operations-dashboard', [
            'metrics' => $metrics->snapshot(),
        ])->layout('layouts.master');
    }

    public function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1_048_576) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        if ($bytes < 1_073_741_824) {
            return number_format($bytes / 1_048_576, 1, ',', '.').' MB';
        }

        return number_format($bytes / 1_073_741_824, 2, ',', '.').' GB';
    }
}
