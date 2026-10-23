<?php

use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Filament\Events\ServingFilament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function policyChecksums(): array
{
    $hashes = [];
    foreach (File::files(app_path('Policies')) as $file) {
        $hashes[$file->getFilename()] = hash_file('sha256', $file->getPathname());
    }
    return $hashes;
}

test('fresh bootstrap creates supported policy permissions without editing policies', function () {
    $before = policyChecksums();
    $this->seed(ShieldSeeder::class);
    foreach (File::files(app_path('Policies')) as $file) {
        preg_match_all('/->can\(\x27([a-z_:]+)\x27\)/', $file->getContents(), $matches);
        foreach ($matches[1] as $permission) {
            expect(Permission::where('name', $permission)->where('guard_name', 'web')->exists())->toBeTrue($permission);
        }
    }
    expect(policyChecksums())->toBe($before);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    expect($admin->can('view_any_user'))->toBeTrue()
        ->and($admin->can('update_user'))->toBeTrue()
        ->and($admin->can('update_role'))->toBeTrue();
});

test('repeated seeding preserves existing custom role permissions', function () {
    $role = Role::findOrCreate('admin', 'web');
    $role->givePermissionTo(Permission::findOrCreate('custom_existing_permission', 'web'));
    $this->seed(ShieldSeeder::class);
    $this->seed(ShieldSeeder::class);
    expect($role->refresh()->hasPermissionTo('custom_existing_permission'))->toBeTrue();
});

test('serving Filament resolves its listener and generates only database permissions', function () {
    $before = policyChecksums();
    Event::dispatch(new ServingFilament());
    expect(Permission::where('name', 'view_any_client')->exists())->toBeTrue()
        ->and(policyChecksums())->toBe($before);
});

test('documented CLI bootstrap creates an administrator who can access the panel', function () {
    $this->seed(ShieldSeeder::class);
    $this->artisan('make:filament-user', [
        '--name' => 'Example Operator',
        '--email' => 'operator@example.test',
        '--password' => 'fixture-only-password',
    ])->assertSuccessful();
    $user = User::where('email', 'operator@example.test')->firstOrFail();
    $this->artisan('shield:super-admin', ['--panel' => 'admin', '--user' => $user->id])->assertSuccessful();
    $this->actingAs($user->fresh())->get('/admin')->assertOk();
    expect($user->fresh()->can('update_client'))->toBeTrue();
});
