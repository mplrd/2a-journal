<?php

namespace App\Core;

abstract class Controller
{
    protected function jsonSuccess(array $data = [], ?array $meta = null, int $status = 200): Response
    {
        return Response::success($data, $meta, $status);
    }

    protected function jsonError(string $code, string $messageKey, ?string $field = null, int $status = 400): Response
    {
        return Response::error($code, $messageKey, $field, $status);
    }
}
