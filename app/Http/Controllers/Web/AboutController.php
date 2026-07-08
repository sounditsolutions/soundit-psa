<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\VersionService;

class AboutController extends Controller
{
    public function __construct(
        private readonly VersionService $version,
    ) {}

    public function index()
    {
        return view('about.index', [
            'current' => $this->version->current(),
            'updates' => $this->version->updates(),
        ]);
    }

    public function checkForUpdates()
    {
        $updates = $this->version->checkForUpdates();

        return response()->json($updates);
    }
}
