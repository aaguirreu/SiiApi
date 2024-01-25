<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UserAuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'password' => 'required',
        ],[
            'name.required' => 'El campo name es obligatorio',
            'password.required' => 'El campo password es obligatorio',
        ]);

        // Si falla la validación, retorna una respuesta Json con el error
        if ($validator->fails()) {
            return response()->json([
                'message' => "Error al iniciar sesión",
                'error' => $validator->errors()->first(),
            ], 400);
        }

        $credentials = $request->only('name', 'password');
        if (Auth::attempt($credentials)) {
            $user = User::where('name', $request->name)->first();
            $token = $user->createToken('token-name')->plainTextToken;
            return response()->json(['token' => $token], 200);
        } else {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }
    }
}
