<?php

declare(strict_types=1);

namespace LvntR\ApiDock\Http\Controllers;

use Illuminate\Http\JsonResponse;
use LvntR\ApiDock\Support\DocumentGenerator;

final class SpecController
{
    public function __invoke(DocumentGenerator $documentGenerator): JsonResponse
    {
        return new JsonResponse($documentGenerator());
    }
}
