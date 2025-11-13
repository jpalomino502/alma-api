<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'phone' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $request->input('phone'),
        ]);

        $plainToken = Str::random(40);
        $token = ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'revoked' => false,
        ]);

        return response()->json([
            'token' => $plainToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_admin' => (bool) $user->is_admin,
            ],
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        $plainToken = Str::random(40);
        ApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'revoked' => false,
        ]);

        return response()->json([
            'token' => $plainToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_admin' => (bool) $user->is_admin,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $authHeader = $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        $plain = substr($authHeader, 7);
        $hash = hash('sha256', $plain);
        $token = ApiToken::where('token_hash', $hash)->where('revoked', false)->first();
        if (!$token) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        $user = $token->user;
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
        ]);
    }

    public function logout(Request $request)
    {
        $authHeader = $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        $plain = substr($authHeader, 7);
        $hash = hash('sha256', $plain);
        $token = ApiToken::where('token_hash', $hash)->where('revoked', false)->first();
        if (!$token) {
            return response()->json(['message' => 'No autorizado'], 401);
        }
        $token->revoked = true;
        $token->save();
        return response()->json(['ok' => true]);
    }
}