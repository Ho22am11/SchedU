<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;


class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'view academic spaces',
             'create academic space',
             'show academic space',
             'update academic space',
             'delete academic space',
            
            'view departments',
             'create department',
             'show department',
             'update department',
             'delete department',
            
            'view lecturers',
             'create lecturer',
             'show lecturer', 
            'update lecturer', 
            'delete lecturer',
            
            'view courses', 
            'create course', 
            'show course',
             'update course', 
            'delete course',
            
            'view academics',
             'create academic', 
            'show academic', 
            'update academic', 
            'delete academic',
            'add course to academic', 
            'remove course from academic',
            
            'view term plans', 
            'create term plan', 'show term plan', 
            'update term plan',
             'delete term plan',
            'add item to term plan',
             'remove item from term plan', 'show term plan item',

            'assign role',
            'remove role',
            'assign permission',
            'remove permission',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
        ]);

        $adminRole->givePermissionTo($permissions);


        $user = User::create([
            'name' => 'hossam' ,
            'email' => 'hossam@gmail.com' ,
            'password' => bcrypt('123456'),
        ]);

        $user->assignRole('admin');

    }
}
