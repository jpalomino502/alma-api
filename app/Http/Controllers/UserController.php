<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ApiToken;
use App\Models\User;

class UserController extends Controller
{
    protected function authUser(Request $request)
    {
        $authHeader = $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        $plain = substr($authHeader, 7);
        $hash = hash('sha256', $plain);
        $token = ApiToken::where('token_hash', $hash)->where('revoked', false)->first();
        return $token ? $token->user : null;
    }

    public function index(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user || !$user->is_admin) return response()->json(['message' => 'No autorizado'], 401);
        $q = User::query()->orderByDesc('id');
        return response()->json($q->get(['id','name','email','phone','address','bio','is_admin']));
    }

    public function update(Request $request, User $user)
    {
        $admin = $this->authUser($request);
        if (!$admin || !$admin->is_admin) return response()->json(['message' => 'No autorizado'], 401);
        $data = $request->validate([
            'is_admin' => ['required','boolean'],
        ]);
        $user->is_admin = $data['is_admin'];
        $user->save();
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'bio' => $user->bio,
            'is_admin' => (bool) $user->is_admin,
        ]);
    }
}