<?php

namespace LogLens\Http\Controllers;

use LogLens\Diagnostics\Diagnostics;

class DiagnosticsController extends Controller
{
    public function __construct(private Diagnostics $diagnostics)
    {
    }

    public function show(): \Illuminate\Http\JsonResponse
    {
        return $this->json($this->diagnostics->report());
    }
}
