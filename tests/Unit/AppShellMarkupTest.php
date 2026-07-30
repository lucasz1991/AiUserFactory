<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AppShellMarkupTest extends TestCase
{
    public function test_master_keeps_blade_and_livewire_content_inside_one_accessible_shell(): void
    {
        $root = dirname(__DIR__, 2);
        $master = file_get_contents($root.'/resources/views/layouts/master.blade.php');

        $this->assertStringContainsString('href="#main-content"', $master);
        $this->assertStringContainsString('id="main-content"', $master);
        $this->assertStringContainsString('tabindex="-1"', $master);

        $mainStart = strpos($master, '<main');
        $yieldPosition = strpos($master, "@yield('content')");
        $slotPosition = strpos($master, '$slot');
        $mainEnd = strpos($master, '</main>', max($yieldPosition ?: 0, $slotPosition ?: 0));

        $this->assertNotFalse($mainStart, 'Der gemeinsame Main-Wrapper fehlt.');
        $this->assertNotFalse($yieldPosition, 'Der klassische Blade-Inhalt fehlt.');
        $this->assertNotFalse($slotPosition, 'Der Livewire-Slot fehlt.');
        $this->assertNotFalse($mainEnd, 'Der gemeinsame Main-Wrapper wird nicht geschlossen.');
        $this->assertGreaterThan($mainStart, $yieldPosition);
        $this->assertGreaterThan($mainStart, $slotPosition);
        $this->assertLessThan($mainEnd, $yieldPosition);
        $this->assertLessThan($mainEnd, $slotPosition);
        $this->assertSame(1, substr_count($master, "@yield('content')"));
    }

    public function test_topbar_and_sidebar_expose_accessible_shell_contracts_below_workflow_overlays(): void
    {
        $root = dirname(__DIR__, 2);
        $topbar = file_get_contents($root.'/resources/views/layouts/topbar.blade.php');
        $sidebar = file_get_contents($root.'/resources/views/layouts/sidebar.blade.php');

        $this->assertMatchesRegularExpression('/data-(?:app|ff|rt)-shell-topbar(?:\\s|=|>)/', $topbar);
        $this->assertStringContainsString('aria-label=', $topbar);
        $this->assertStringContainsString('aria-controls="app-sidebar"', $topbar);
        $this->assertStringContainsString('aria-expanded="false"', $topbar);
        $this->assertStringContainsString('z-40', $topbar);

        $this->assertMatchesRegularExpression('/data-(?:app|ff|rt)-shell-sidebar(?:\\s|=|>)/', $sidebar);
        $this->assertStringContainsString('id="app-sidebar"', $sidebar);
        $this->assertStringContainsString('aria-label=', $sidebar);
        $this->assertStringContainsString('z-30', $sidebar);
        $this->assertStringContainsString('z-20', $sidebar);

        $shellMarkup = $topbar.$sidebar;
        $this->assertStringNotContainsString('z-[70]', $shellMarkup);
        $this->assertStringNotContainsString('z-[80]', $shellMarkup);
        $this->assertStringNotContainsString('z-[90]', $shellMarkup);
    }

    public function test_shell_assets_share_one_1024_pixel_breakpoint_and_are_loaded_by_vite(): void
    {
        $root = dirname(__DIR__, 2);
        $styles = file_get_contents($root.'/resources/css/app-shell.css');
        $script = file_get_contents($root.'/resources/js/app-shell.js');
        $appScript = file_get_contents($root.'/resources/js/app.js');
        $vite = file_get_contents($root.'/vite.config.js');
        $master = file_get_contents($root.'/resources/views/layouts/master.blade.php');

        $this->assertStringContainsString('@media (max-width: 1023.98px)', $styles);
        $this->assertStringContainsString('@media (min-width: 1024px)', $styles);
        $this->assertStringContainsString('window.innerWidth >= 1024', $script);
        $this->assertStringNotContainsString('window.innerWidth >= 992', $script);
        $this->assertStringNotContainsString('window.innerWidth >= 1140', $script);

        $this->assertStringContainsString("import './app-shell'", $appScript);
        $this->assertStringContainsString("'resources/css/app-shell.css'", $vite);
        $this->assertStringContainsString("'resources/css/app-shell.css'", $master);
    }

    public function test_sidebar_groups_and_mobile_drawer_expose_keyboard_and_focus_contracts(): void
    {
        $root = dirname(__DIR__, 2);
        $group = file_get_contents($root.'/resources/views/components/menu/sidebar-nav-group.blade.php');
        $script = file_get_contents($root.'/resources/js/app-shell.js');
        $styles = file_get_contents($root.'/resources/css/app-shell.css');

        $this->assertStringContainsString('<button', $group);
        $this->assertStringContainsString('type="button"', $group);
        $this->assertStringContainsString('aria-controls="{{ $groupId }}"', $group);
        $this->assertStringContainsString('id="{{ $groupId }}"', $group);
        $this->assertStringNotContainsString('href="#"', $group);

        $this->assertStringContainsString("sidebar.toggleAttribute('inert', hidden)", $script);
        $this->assertStringContainsString("sidebar.setAttribute('aria-hidden', hidden ? 'true' : 'false')", $script);
        $this->assertStringContainsString('focusFirstSidebarControl()', $script);
        $this->assertStringContainsString('restoreFocus: true', $script);
        $this->assertStringContainsString('desktopSidebarExpandedState', $script);
        $this->assertStringContainsString('captureDesktopSidebarState()', $script);
        $this->assertStringContainsString("document.addEventListener('livewire:navigate'", $script);
        $this->assertStringContainsString('event.defaultPrevented', $script);
        $this->assertStringNotContainsString("link.dataset.menuActive === 'true'", $script);

        $this->assertMatchesRegularExpression(
            '/\.ff-sidebar-section\s*\{[^}]*height:\s*0\s*!important/s',
            $styles,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 1023\.98px\).*?\.ff-sidebar-section\s*\{[^}]*height:\s*2\.5rem\s*!important/s',
            $styles,
        );
    }

    public function test_shared_dropdown_escapes_overflow_and_supports_menu_keyboard_navigation(): void
    {
        $root = dirname(__DIR__, 2);
        $dropdown = file_get_contents($root.'/resources/views/components/ui/dropdown.blade.php');
        $dropdownLink = file_get_contents($root.'/resources/views/components/ui/dropdown-link.blade.php');
        $workflowActions = file_get_contents($root.'/resources/views/components/workflows/actions-dropdown.blade.php');

        $this->assertStringContainsString('x-teleport="body"', $dropdown);
        $this->assertStringContainsString('x-anchor.{{ $anchorPlacement }}.offset.8.flip.shift', $dropdown);
        $this->assertStringContainsString('data-ff-dropdown-root', $dropdown);
        $this->assertStringContainsString('data-ff-dropdown-panel', $dropdown);
        $this->assertStringContainsString('role="menu"', $dropdown);
        $this->assertStringContainsString('@keydown.arrow-down.prevent.stop', $dropdown);
        $this->assertStringContainsString('@keydown.arrow-up.prevent.stop', $dropdown);
        $this->assertStringContainsString('@keydown.escape.prevent.stop="hide(true)"', $dropdown);
        $this->assertStringContainsString('focus({ preventScroll: true })', $dropdown);

        $this->assertStringContainsString("'role' => 'menuitem'", $dropdownLink);
        $this->assertStringContainsString('aria-haspopup="menu"', $workflowActions);
        $this->assertStringContainsString('x-bind:aria-expanded="open.toString()"', $workflowActions);
        $this->assertStringContainsString('role="menuitem"', $workflowActions);
    }

    public function test_manager_workbench_and_standalone_studio_stay_above_the_shell(): void
    {
        $root = dirname(__DIR__, 2);
        $manager = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $studio = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio.blade.php');

        $this->assertStringContainsString('data-workflow-test-workbench', $manager);
        $this->assertStringContainsString('fixed inset-0 top-0 z-[70]', $manager);
        $this->assertStringContainsString(
            "'fixed inset-0 top-0 z-[70] h-[100dvh]'",
            $studio,
        );
    }
}
