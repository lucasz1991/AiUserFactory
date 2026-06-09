<?php

namespace Tests\Unit;

use App\Models\Person;
use App\Services\Simulation\PersonaActivityPlanner;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class PersonaActivityPlannerTest extends TestCase
{
    public function test_it_builds_a_deterministic_internal_activity_plan(): void
    {
        $person = $this->person();
        $planner = new PersonaActivityPlanner();
        $startsAt = Carbon::parse('2026-06-09 00:00:00', 'Europe/Berlin');

        $first = $planner->build($person, 5, 'balanced', 'fixed-seed', $startsAt);
        $second = $planner->build($person, 5, 'balanced', 'fixed-seed', $startsAt);

        $this->assertSame('internal_sandbox_only', $first['scope']);
        $this->assertFalse($first['governance']['real_platform_access']);
        $this->assertFalse($first['governance']['browser_automation']);
        $this->assertSame($first['days_plan'], $second['days_plan']);
        $this->assertGreaterThan(0, $first['metrics']['planned_sessions']);
        $this->assertGreaterThan(0, $first['metrics']['planned_steps']);
    }

    public function test_it_caps_days_and_creates_bursty_session_steps(): void
    {
        $plan = (new PersonaActivityPlanner())->build(
            person: $this->person(),
            days: 30,
            intensity: 'active',
            seed: 'cap-test',
            startsAt: Carbon::parse('2026-06-09 00:00:00', 'Europe/Berlin'),
        );

        $this->assertSame(14, $plan['days']);
        $this->assertCount(14, $plan['days_plan']);

        $firstSession = $plan['days_plan'][0]['sessions'][0];

        $this->assertGreaterThanOrEqual(4, count($firstSession['steps']));
        $this->assertSame('open_home_feed', $firstSession['steps'][0]['action']);
        $this->assertSame('close_session', $firstSession['steps'][count($firstSession['steps']) - 1]['action']);
    }

    protected function person(): Person
    {
        return new Person([
            'profile_key' => 'test-persona',
            'profile_label' => 'test-persona',
            'person_first_name' => 'Mara',
            'person_last_name' => 'Sommer',
            'person_city' => 'Berlin',
            'person_country' => 'Deutschland',
            'person_timezone' => 'Europe/Berlin',
            'bot_status' => 'ready',
            'identity_profile' => [
                'occupation' => 'Grafikdesignerin',
                'interests' => ['Fotografie', 'Urban Gardening', 'Kaffee', 'Ausstellungen'],
                'personality_traits' => ['aufmerksam', 'humorvoll'],
                'values' => ['Zuverlaessigkeit', 'Kreativitaet'],
                'daily_routine' => 'Arbeitet tagsueber, kurze Checks morgens und laengere Session am Abend.',
            ],
            'bot_profile' => [
                'communication_style' => 'warm, kurz und konkret',
                'writing_style' => 'natuerlich und nicht werblich',
            ],
        ]);
    }
}
