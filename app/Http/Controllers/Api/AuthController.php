<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponce;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;


class AuthController extends Controller
{
    // ✅ تسجيل الدخول
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            return ApiResponce::sendResponse(401, 'Unauthorized & Invalid Credentials', null);
        }

        $user = Auth::user();

        $data = [
            'token' => $user->createToken('auth_token')->plainTextToken,
            'name'  => optional($user)->name,
            'email' => $user->email,
        ];

        return ApiResponce::sendResponse(200, 'User Logged in Successfully', $data);
    }

    // ✅ جلب بيانات المستخدم الحالي
    public function getUser(Request $request)
    {
        return ApiResponce::sendResponse(200, 'User Data Retrieved Successfully', $request->user());
    }

    // ✅ تسجيل مستخدم جديد (Admin فقط)
public function register(Request $request)
{
    $request->validate([
        'name'     => 'required|max:255',
        'email'    => 'required|email|unique:users|max:255',
        'password' => 'required|min:6',

    ]);

    // 🔹 التأكد من أن المستخدم الحالي Admin
 
    // 🔹 إنشاء المستخدم الجديد
    $user = User::create([
        'name'     => $request->name,
        'email'    => $request->email,
        'password' => Hash::make($request->password),

    ]);

    // 🔹 إنشاء التوكن للمستخدم الجديد
    $token = $user->createToken('auth_token')->plainTextToken;

    // 🔹 تحضير البيانات للإستجابة
    $data = [
        'user'  => $user,
        'token' => $token
    ];

    return ApiResponce::sendResponse(201, 'User Created Successfully', $data);
}

    // ✅ تسجيل الخروج
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return ApiResponce::sendResponse(200, 'User Logged Out Successfully', null);
    }
}