<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Services\MetricsService;

class ApiController extends Controller
{
    public function metrics()
{
    return response(MetricsService::render(), 200)
        ->header('Content-Type', 'text/plain; version=0.0.4');
}
    public function normal()
    {
        return response()->json([
            'status'  => 'ok',
            'message' => 'Normal response',
            'data'    => ['value' => rand(1, 100)]
        ]);
    }

    public function slow(Request $request)
    {
        if ($request->query('hard') == '1') {
            sleep(rand(5, 7));
        } else {
            usleep(rand(500000, 1500000)); // 0.5 - 1.5 seconds
        }

        return response()->json([
            'status'  => 'ok',
            'message' => 'Slow response',
        ]);
    }

    public function error()
    {
        abort(500, 'Simulated server error');
    }

    public function random()
    {
        $rand = rand(1, 3);
        if ($rand === 1) {
            usleep(rand(500000, 1500000));
            return response()->json(['status' => 'ok', 'message' => 'Random slow']);
        } elseif ($rand === 2) {
            abort(500, 'Random error');
        }
        return response()->json(['status' => 'ok', 'message' => 'Random normal']);
    }

    public function db(Request $request)
    {
        if ($request->query('fail') == '1') {
            // Force a QueryException with wrong table
            DB::table('nonexistent_table_xyz')->get();
        }

        $results = DB::select('SELECT 1 as connected, NOW() as server_time');

        return response()->json([
            'status' => 'ok',
            'message' => 'DB query successful',
            'data'   => $results
        ]);
    }

    public function validateInput(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'age'   => 'required|integer|between:18,60',
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return response()->json([
            'status'  => 'ok',
            'message' => 'Validation passed',
            'data'    => $request->only(['email', 'age'])
        ]);
    }
}