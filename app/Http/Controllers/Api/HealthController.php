<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index()
    {
        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable) {}

        $storageOk = is_writable(storage_path('framework/cache'))
            && is_writable(storage_path('framework/sessions'))
            && is_writable(storage_path('logs'));

        $status = ($dbOk && $storageOk) ? 'ok' : 'degraded';

        return response()->json([
            'status'           => $status,
            'app'              => config('app.name'),
            'php'              => PHP_VERSION,
            'laravel'          => app()->version(),
            'database'         => $dbOk,
            'storage_writable' => $storageOk,
        ]);
    }
}
