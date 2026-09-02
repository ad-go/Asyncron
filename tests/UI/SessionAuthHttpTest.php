<?php

declare(strict_types=1);

namespace Tests\UI;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * HTTP coverage of the 'session' filter (Shield's SessionAuth, registered
 * via CodeIgniter\Shield\Config\Registrar::Filters() - see app/Config/
 * Filters.php's own $aliases, which does NOT list 'session' explicitly;
 * it arrives through Shield's Registrar merge instead) as it actually
 * gates this app's admin UI routes (AdGo\Cluster\UI\RouteRegistrar - the
 * dashboard, settings, users, profile).
 *
 * Deliberately scoped to the UNAUTHENTICATED case only - a real logged-in
 * session would need Shield's own auth tables migrated against the
 * 'tests' SQLite group (see app/Config/Database.php's own $tests
 * property) plus a seeded user, which is what UI\Commands\InstallCommand
 * itself does for a real node and is a much heavier fixture than this
 * suite takes on; that path is exercised live on each node instead, per
 * this package's own README. No database access happens for either test
 * below - SessionAuth::before()'s own checkUserState() returns immediately
 * once it finds no user id in a fresh, empty test session, without ever
 * querying the users table.
 */
final class SessionAuthHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testUnauthenticatedDashboardRequestRedirectsToLogin(): void
    {
        $result = $this->withSession([])->get('/');

        $result->assertRedirectTo(site_url('login'));
    }

    public function testUnauthenticatedSettingsRequestRedirectsToLogin(): void
    {
        $result = $this->withSession([])->get('settings');

        $result->assertRedirectTo(site_url('login'));
    }

    public function testLoginPageIsReachableWithoutAuthenticationAndReturns200(): void
    {
        $result = $this->withSession([])->get('login');

        $result->assertStatus(200);
    }
}
