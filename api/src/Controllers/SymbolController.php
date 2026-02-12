<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\SymbolService;

class SymbolController extends Controller
{
    private SymbolService $symbolService;

    public function __construct(SymbolService $symbolService)
    {
        $this->symbolService = $symbolService;
    }

    public function index(Request $request): Response
    {
        $symbols = $this->symbolService->list();

        return $this->jsonSuccess($symbols);
    }
}
