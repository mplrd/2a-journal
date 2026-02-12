<?php

namespace App\Services;

use App\Repositories\SymbolRepository;

class SymbolService
{
    private SymbolRepository $symbolRepo;

    public function __construct(SymbolRepository $symbolRepo)
    {
        $this->symbolRepo = $symbolRepo;
    }

    public function list(): array
    {
        return $this->symbolRepo->findAllActive();
    }
}
