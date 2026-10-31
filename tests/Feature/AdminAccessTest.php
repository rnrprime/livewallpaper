<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Panel;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    public function test_nonlocal_admin_access_is_denied_by_default(): void
    {
        config(['admin.emails' => []]);
        $user = new User(['email' => 'owner@example.com']);
        $this->assertFalse($user->canAccessPanel(Panel::make()->id('admin')));
    }

    public function test_explicit_allowlist_grants_only_selected_users(): void
    {
        config(['admin.emails' => ['Owner@Example.com']]);
        $panel = Panel::make()->id('admin');
        $this->assertTrue((new User(['email' => 'owner@example.com']))->canAccessPanel($panel));
        $this->assertFalse((new User(['email' => 'someone@example.com']))->canAccessPanel($panel));
    }
}
