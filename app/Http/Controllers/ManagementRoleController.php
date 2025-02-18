<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ManagementRoleController extends Controller
{
    use ApiResponseTrait ;
    public function assignRole(Request $request){
        
        $user = User::find($request->user_id);
        $role = Role::find($request->role_id);
        $user->assignRole($role->name); 
        return $this->ApiResponse(null , 'assign Role successfully' , 200);

    }


    public function removeRole(Request $request){

        $user = User::find($request->user_id);
        $role = Role::find($request->role_id);
        $user->removeRole($role->name);
        return $this->ApiResponse(null , 'remove Role successfully' , 200);
    }

    public function assignPermission(Request $request){
        
        $user = User::find($request->user_id);
        $permissions = Permission::whereIn('id', $request->permission_ids)->pluck('name');
        $user->givePermissionTo($permissions);
        return $this->ApiResponse(null , 'assign Permission successfully' , 200);

    }

    public function updatePermission(Request $request)
{
    $user = User::find($request->user_id);

    $permissions = Permission::whereIn('id', $request->permission_ids)->pluck('name');
    $user->syncPermissions($permissions);

    return $this->ApiResponse(null, 'update permissions successfully', 200);
}
}
