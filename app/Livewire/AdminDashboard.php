<?php

namespace App\Livewire;

use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class AdminDashboard extends Component
{
    public int $totalUsers = 0;
    public int $totalPersons = 0;
    public int $activePersons = 0;
    public int $blockedPersons = 0;
    public int $automationReadyPersons = 0;

    public function mount(): void
    {
        $this->refreshStats();
    }

    public function refreshStats(): void
    {
        $this->totalUsers = Schema::hasTable('users') ? User::count() : 0;
        $personSchema = $this->personSchema();

        if (! $personSchema->hasTable('persons')) {
            return;
        }

        $this->totalPersons = Person::count();
        $this->activePersons = Person::where('is_active', true)->count();
        $this->blockedPersons = $personSchema->hasColumn('persons', 'scrape_blocked_until')
            ? Person::whereNotNull('scrape_blocked_until')->where('scrape_blocked_until', '>', now())->count()
            : 0;
        $this->automationReadyPersons = $personSchema->hasColumn('persons', 'bot_status')
            ? Person::whereIn('bot_status', ['ready', 'training'])->count()
            : 0;
    }

    public function render()
    {
        return view('livewire.admin-dashboard')->layout('layouts.master');
    }

    private function personSchema(): \Illuminate\Database\Schema\Builder
    {
        return Schema::getFacadeRoot();
    }
}
