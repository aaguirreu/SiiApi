<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
class BoletaController extends Controller
{
    public function index(Request $request) {
        $users = json_decode($request->json()->all());
        return response()->json($users);
    }
}
