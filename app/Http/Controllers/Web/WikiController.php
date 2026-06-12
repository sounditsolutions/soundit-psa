<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;

class WikiController extends Controller
{
    public function show(string $slug)
    {
        abort(404); // implemented in Task 15
    }

    public function clientShow(Client $client, string $slug)
    {
        abort(404); // implemented in Task 15
    }
}
