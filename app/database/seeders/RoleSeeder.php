<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all permissions
        $permissions = [
            // Airport management
            'airports.view',
            'airports.create',
            'airports.update',
            'airports.delete',

            // Airport sources
            'airport-sources.view',
            'airport-sources.create',
            'airport-sources.update',
            'airport-sources.delete',

            // Scraping
            'scrape-jobs.view',
            'scrape-jobs.trigger',

            // Flights
            'flights.view',

            // Parser versions
            'parser-versions.view',
            'parser-versions.create',
            'parser-versions.activate',
            'parser-versions.replay',

            // Failures & repairs
            'failures.view',
            'failures.repair',
            'repair-candidates.review',
            'repair-candidates.approve',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // admin: everything
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions(Permission::all());

        // operator: run scrape, review failures, approve candidate parser
        $operator = Role::firstOrCreate(['name' => 'operator']);
        $operator->syncPermissions([
            'airports.view',
            'airport-sources.view',
            'scrape-jobs.view',
            'scrape-jobs.trigger',
            'flights.view',
            'parser-versions.view',
            'parser-versions.replay',
            'failures.view',
            'failures.repair',
            'repair-candidates.review',
            'repair-candidates.approve',
        ]);

        // viewer: read-only
        $viewer = Role::firstOrCreate(['name' => 'viewer']);
        $viewer->syncPermissions([
            'airports.view',
            'airport-sources.view',
            'scrape-jobs.view',
            'flights.view',
            'parser-versions.view',
            'failures.view',
        ]);
    }
}
