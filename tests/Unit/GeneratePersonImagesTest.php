<?php

namespace Tests\Unit;

use App\Jobs\GeneratePersonImages;
use App\Models\Person;
use PHPUnit\Framework\TestCase;

class GeneratePersonImagesTest extends TestCase
{
    public function test_it_builds_a_prompt_from_nested_profile_values(): void
    {
        $job = new class(personId: 1, prompt: 'Erstelle ein Portrait.', preset: 'profile_portrait', aspectRatio: '1:1', preview: ['root' => ['person_first_name' => 'Mara', 'person_last_name' => 'Muster'], 'identity_profile' => ['interests' => ['Fotografie', 'Reisen'], 'personality_traits' => ['social' => ['offen', 'aufmerksam']], 'physical_appearance' => ['hair' => 'braun', 'eyes' => 'gruen']]]) extends GeneratePersonImages
        {
            public function promptFor(Person $person): string
            {
                return $this->buildImagePrompt($person, 0, 1);
            }
        };

        $prompt = $job->promptFor(new Person);

        $this->assertStringContainsString('Interessen: Fotografie, Reisen', $prompt);
        $this->assertStringContainsString('Persoenlichkeit: social: offen, aufmerksam', $prompt);
        $this->assertStringContainsString('Optische Beschreibung: hair: braun, eyes: gruen', $prompt);
    }
}
