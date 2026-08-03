<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PersonProfileMarkupTest extends TestCase
{
    public function test_profile_header_no_longer_carries_the_back_timeout_session_and_image_buttons(): void
    {
        $source = $this->view('person-detail');
        $header = substr($source, 0, (int) strpos($source, "x-show=\"tab === 'overview'\""));

        $this->assertStringNotContainsString('Zurueck', $header);
        $this->assertStringNotContainsString("route('persons.index')", $header);
        $this->assertStringNotContainsString('>Timeouts', $header);
        $this->assertStringNotContainsString('Session aufbauen', $header);
        $this->assertStringNotContainsString('>Bilder', $header);

        // Die Funktionen bleiben erreichbar, nur an anderer Stelle.
        $this->assertStringContainsString('person-open-runtime-settings', $source);
        $this->assertStringContainsString('person-build-session', $source);
        $this->assertStringContainsString('Bilder erstellen', $source);
    }

    public function test_email_and_social_tabs_are_merged_into_a_single_accounts_tab(): void
    {
        $source = $this->view('person-detail');

        $this->assertStringContainsString("'accounts' => 'Accounts'", $source);
        $this->assertStringContainsString('<livewire:admin.config.person-accounts', $source);

        $this->assertStringNotContainsString("tab = 'social'", $source);
        $this->assertStringNotContainsString("tab === 'email'", $source);
        $this->assertStringNotContainsString('Social Media Accounts', $source);

        // Das Mailkonto haengt jetzt am Accounts-Tab, nicht mehr am Profil.
        $this->assertStringNotContainsString('person-email-account-settings', $source);
        $this->assertStringContainsString(
            '<livewire:admin.config.person-email-account-settings',
            $this->view('person-accounts'),
        );
    }

    public function test_profile_renders_the_new_hero_metrics_and_tab_shell(): void
    {
        $source = $this->view('person-detail');

        $this->assertStringContainsString('data-person-profile', $source);
        $this->assertStringContainsString('data-profile-hero', $source);
        $this->assertStringContainsString('data-profile-metrics', $source);
        $this->assertStringContainsString('data-profile-panel', $source);
        $this->assertStringContainsString('data-metric-value', $source);

        foreach (['Accounts verbunden', 'Zugangsdaten', 'Login-Session', 'Prozesse', 'Medien', 'Aktivitaetsrisiko'] as $label) {
            $this->assertStringContainsString($label, $source);
        }
    }

    public function test_accounts_tab_lists_every_account_type_and_shows_the_workflow_paths(): void
    {
        $source = $this->view('person-accounts');

        $this->assertStringContainsString('data-person-accounts', $source);
        $this->assertStringContainsString('ff-account-chip', $source);
        $this->assertStringContainsString('Workflow-Datenpfade', $source);
        $this->assertStringContainsString('person.accounts.&lt;typ&gt;.username', $source);
        $this->assertStringContainsString("wire:click=\"selectType('{{ \$type }}')\"", $source);
    }

    public function test_motion_layer_is_registered_and_stays_optional(): void
    {
        $root = dirname(__DIR__, 2);
        $app = file_get_contents($root.'/resources/js/app.js');
        $motion = file_get_contents($root.'/resources/js/components/person-profile-motion.js');
        $styles = file_get_contents($root.'/resources/css/person-profile.css');
        $appCss = file_get_contents($root.'/resources/css/app.css');

        $this->assertStringContainsString("import './components/person-profile-motion';", $app);
        $this->assertStringContainsString("@import './person-profile.css';", $appCss);

        // Bewegung nur mit GSAP-matchMedia und nur bei erlaubter Bewegung.
        $this->assertStringContainsString("import { gsap } from 'gsap';", $motion);
        $this->assertStringContainsString("mm.add('(prefers-reduced-motion: no-preference)'", $motion);
        $this->assertStringContainsString('[data-person-profile]', $motion);

        // Startzustaende duerfen nicht im CSS stehen, sonst bleibt ohne
        // JavaScript alles unsichtbar.
        $this->assertStringNotContainsString('.ff-metric { opacity: 0', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
    }

    /**
     * Blade-Kommentare werden entfernt: sie erklaeren hier bewusst, welche
     * Knoepfe entfallen sind, und wuerden die Negativ-Assertions sonst
     * faelschlich rot faerben.
     */
    protected function view(string $name): string
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/livewire/admin/config/'.$name.'.blade.php'
        );

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }
}
