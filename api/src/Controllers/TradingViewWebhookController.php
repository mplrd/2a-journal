<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\TradingViewWebhookService;

class TradingViewWebhookController extends Controller
{
    public function __construct(private TradingViewWebhookService $service) {}

    public function ingest(Request $request): Response
    {
        $token = (string) $request->getRouteParam('token', '');
        $payload = $request->getBody();
        if (!is_array($payload)) {
            $payload = [];
        }

        // Always 200 — the outcome is recorded in tradingview_alert_events and
        // surfaced to the user via the UI. Leaking validation results back to
        // the public webhook caller would help attackers fingerprint tokens.
        $this->service->process($token, $payload);

        return $this->jsonSuccess(['received' => true]);
    }
}
