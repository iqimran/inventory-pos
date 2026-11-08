<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/backups-'.getmypid());
        config([
            'backup.manual.directory' => $this->directory.'/manual',
            'backup.auto.path' => $this->directory.'/auto/database-auto.sql.gz',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function contents(string $path): string
    {
        return (string) gzdecode((string) file_get_contents($path));
    }

    public function test_only_admin_can_manage_backups()
    {
        // Even every permission does not include backups.
        $user = $this->generalUser();
        $user->givePermissionTo(array_column(Permission::cases(), 'value'));

        $this->actingAs($user)->get('/admin/backups')->assertForbidden();
        $this->actingAs($user)->post('/admin/backups')->assertForbidden();
        $this->actingAs($user)->delete('/admin/backups')->assertForbidden();
        $this->actingAs($user)->get('/admin/backups/auto/download')->assertForbidden();
        $this->assertDirectoryDoesNotExist($this->directory.'/manual');

        $this->get('/admin/backups')->assertForbidden();
        auth()->logout();
        $this->get('/admin/backups')->assertRedirect('/login');
    }

    public function test_admin_creates_lists_and_downloads_a_manual_backup()
    {
        $admin = $this->admin();
        Product::factory()->create(['name' => "Charger 25W 'fast'"]);

        $this->actingAs($admin)->post('/admin/backups')->assertRedirect()->assertSessionHas('success');

        $files = glob($this->directory.'/manual/manual-*.sql.gz');
        $this->assertCount(1, $files);
        $name = basename($files[0]);
        $sql = $this->contents($files[0]);
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertMatchesRegularExpression("/Charger 25W (''|\\\\')fast/", $sql);   // quote escaped ('' SQLite, \' MySQL)
        $this->assertStringEndsWith("-- Dump completed\n", $sql);
        $this->assertSame([], glob($this->directory.'/manual/*.partial'));
        $this->assertSame($name, AuditLog::where('event', 'backup.created')->sole()->description);

        $this->actingAs($admin)->get('/admin/backups')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/backups')
                ->where('manual.0.name', $name)
                ->where('auto', null)
                ->where('schedule.enabled', true));

        $response = $this->actingAs($admin)->get("/admin/backups/{$name}/download")->assertOk();
        $this->assertStringContainsString($name, (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(1, AuditLog::where('event', 'backup.downloaded')->count());
    }

    public function test_the_backup_restores_into_an_empty_database()
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Restore is verified on SQLite; MySQL dumps use the same writer.');
        }

        Product::factory()->count(3)->create();
        $this->artisan('backup:database')->assertSuccessful();

        config(['database.connections.restore' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::connection('restore')->unprepared($this->contents(config('backup.auto.path')));

        $this->assertSame(3, DB::connection('restore')->table('products')->count());
        $this->assertSame(User::count(), DB::connection('restore')->table('users')->count());
    }

    public function test_delete_and_clear_manual_backups_keep_the_automatic_one()
    {
        $admin = $this->admin();
        $this->artisan('backup:database')->assertSuccessful();
        foreach (['20260101-010101', '20260102-010101', '20260103-010101'] as $stamp) {
            File::ensureDirectoryExists($this->directory.'/manual');
            file_put_contents($this->directory."/manual/manual-{$stamp}.sql.gz", gzencode('-- test'));
        }

        $this->actingAs($admin)->delete('/admin/backups/manual-20260101-010101.sql.gz')->assertRedirect()->assertSessionHas('success');
        $this->assertFileDoesNotExist($this->directory.'/manual/manual-20260101-010101.sql.gz');
        $this->actingAs($admin)->delete('/admin/backups/manual-20260101-010101.sql.gz')->assertNotFound();

        $this->actingAs($admin)->delete('/admin/backups')->assertSessionHas('success', '2 manual backup(s) deleted.');
        $this->assertSame([], glob($this->directory.'/manual/*'));
        $this->assertFileExists(config('backup.auto.path'));
        $this->assertSame(1, AuditLog::where('event', 'backup.cleared')->count());
    }

    public function test_names_outside_the_backup_pattern_are_refused()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/backups/..%2F..%2F.env/download')->assertNotFound();
        $this->actingAs($admin)->get('/admin/backups/database-auto.sql.gz/download')->assertNotFound();
        $this->actingAs($admin)->delete('/admin/backups/manual-1.sql.gz')->assertNotFound();
    }

    public function test_the_daily_backup_overwrites_one_file()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/backups/auto/download')->assertNotFound();

        $this->artisan('backup:database')->assertSuccessful();
        $first = $this->contents(config('backup.auto.path'));

        Product::factory()->create(['name' => 'Added later']);
        $this->travel(1)->days();
        $this->artisan('backup:database')->assertSuccessful();

        $this->assertSame(['database-auto.sql.gz'], array_map('basename', glob($this->directory.'/auto/*')));
        $this->assertStringNotContainsString('Added later', $first);
        $this->assertStringContainsString('Added later', $this->contents(config('backup.auto.path')));
        $this->assertDirectoryDoesNotExist($this->directory.'/manual');

        $this->actingAs($admin)->get('/admin/backups')
            ->assertInertia(fn (Assert $page) => $page->where('auto.name', 'database-auto.sql.gz')->has('auto.size'));
        $response = $this->actingAs($admin)->get('/admin/backups/auto/download')->assertOk();
        $this->assertMatchesRegularExpression('/auto-\d{8}-\d{6}\.sql\.gz/', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_command_can_also_keep_a_manual_backup()
    {
        $this->artisan('backup:database --manual')->assertSuccessful();

        $this->assertCount(1, glob($this->directory.'/manual/manual-*.sql.gz'));
        $this->assertFileDoesNotExist(config('backup.auto.path'));
    }

    public function test_the_backup_is_scheduled_daily()
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'backup:database'));

        $this->assertNotNull($event);
        $this->assertSame('30 1 * * *', $event->expression);
    }
}
