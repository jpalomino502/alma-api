<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ApiToken;

class ProfileController extends Controller
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

    public function show(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
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

    public function update(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
        $data = $request->validate([
            'name' => ['sometimes','string','max:255'],
            'email' => ['sometimes','string','email','max:255','unique:users,email,'.$user->id],
            'phone' => ['nullable','string','max:255'],
            'address' => ['nullable','string','max:255'],
            'bio' => ['nullable','string'],
        ]);
        $user->update($data);
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