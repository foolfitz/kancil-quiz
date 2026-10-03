<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// 三種角色（docs/SPEC.md 第 2 節）。老師是預設角色；審核者與管理員可以使用 Filament 後台。
return new class extends Migration
{
    public function up(): void
    {
        foreach (['teacher', 'curator', 'admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::whereIn('name', ['teacher', 'curator', 'admin'])->delete();
    }
};
