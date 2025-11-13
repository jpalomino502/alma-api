<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EpaycoController extends Controller
{
    public function createSession(Request $request)
    {
        // Validate minimal required fields (frontend can send more)
        $data = $request->validate([
            'name' => 'sometimes|string',
            'description' => 'sometimes|string',
            'currency' => 'sometimes|string',
            'amount' => 'required|numeric',
            'lang' => 'sometimes|string',
            'country' => 'sometimes|string',
        ]);

        $public = env('EPAYCO_PUBLIC_KEY');
        $private = env('EPAYCO_PRIVATE_KEY');

        if (empty($public) || empty($private)) {
            return response()->json([
                'success' => false,
                'message' => 'EPAYCO_PUBLIC_KEY and EPAYCO_PRIVATE_KEY must be set in .env'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            // 1. Authenticate with Apify (Basic auth using PUBLIC:PRIVATE)
            $basic = base64_encode($public . ':' . $private);

            $loginRes = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . $basic,
            ])->post('https://apify.epayco.co/login');

            // Debug log: show raw login response for troubleshooting
            Log::debug('Epayco Apify login response', ['status' => $loginRes->status(), 'body' => $loginRes->body()]);

            if (! $loginRes->successful()) {
                Log::error('Epayco login failed', ['status' => $loginRes->status(), 'body' => $loginRes->body()]);
                return response()->json(['success' => false, 'message' => 'Failed to authenticate with ePayco Apify'], Response::HTTP_BAD_GATEWAY);
            }

            $apifyToken = $loginRes->json('token');
            if (empty($apifyToken)) {
                Log::error('Epayco login response missing token', ['body' => $loginRes->body()]);
                return response()->json(['success' => false, 'message' => 'Apify token not returned'], Response::HTTP_BAD_GATEWAY);
            }

            // 2. Prepare payload for session creation
            $payload = $request->all();
            // Ensure required / sensible defaults
            $payload['checkout_version'] = $payload['checkout_version'] ?? '2';
            $payload['name'] = $payload['name'] ?? env('EPAYCO_STORE_NAME', 'Alma Store');
            $payload['description'] = $payload['description'] ?? env('EPAYCO_STORE_DESCRIPTION', 'Compra desde el sitio');
            $payload['country'] = $payload['country'] ?? env('EPAYCO_COUNTRY', 'CO');

            // IP: prefer X-Forwarded-For, otherwise request IP. For local dev replace loopback/private IP
            $ip = $request->header('X-Forwarded-For') ?? $request->ip();
            if (in_array($ip, ['127.0.0.1', '::1']) || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1]))/', $ip)) {
                // use a public test IP for Apify validation in local dev
                $ip = env('EPAYCO_TEST_IP', '201.245.254.45');
            }
            $payload['ip'] = $payload['ip'] ?? $ip;

            $payload['test'] = isset($payload['test']) ? (bool) $payload['test'] : (bool) env('EPAYCO_TEST', true);

            // Normalize amount: if amount looks like cents (very large) convert to units
            if (isset($payload['amount']) && is_numeric($payload['amount'])) {
                $amount = (float) $payload['amount'];
                if ($amount > 1000000) {
                    // assume cents were sent, convert to currency units
                    $payload['amount'] = $amount / 100;
                    Log::debug('EpaycoController normalized amount by dividing by 100', ['original' => $amount, 'normalized' => $payload['amount']]);
                }
            }

            // Optional range guard: some ePayco comercios tienen rango de montos configurado.
            // Permite detener con un error claro si el total está fuera del rango esperado.
            $minAmount = env('EPAYCO_MIN_AMOUNT');
            $maxAmount = env('EPAYCO_MAX_AMOUNT');
            if (isset($payload['amount']) && (isset($minAmount) || isset($maxAmount))) {
                $amt = (float) $payload['amount'];
                if (isset($minAmount) && is_numeric($minAmount) && $amt < (float) $minAmount) {
                    Log::warning('EpaycoController amount below configured min', ['amount' => $amt, 'min' => (float) $minAmount]);
                    return response()->json([
                        'success' => false,
                        'message' => sprintf('El monto (%s) está por debajo del mínimo permitido (%s).', $amt, (float) $minAmount),
                        'code' => 'amount_below_min'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                if (isset($maxAmount) && is_numeric($maxAmount) && $amt > (float) $maxAmount) {
                    Log::warning('EpaycoController amount above configured max', ['amount' => $amt, 'max' => (float) $maxAmount]);
                    return response()->json([
                        'success' => false,
                        'message' => sprintf('El monto (%s) supera el máximo permitido (%s).', $amt, (float) $maxAmount),
                        'code' => 'amount_above_max'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            // Ensure redirect/response URLs so ePayco returns to our frontend instead of its landing page
            // Do not default to localhost: it is invalid for ePayco validation. Require a valid public HTTPS URL.
            $responseUrl = $payload['response'] ?? env('EPAYCO_RESPONSE_URL');
            $confirmationUrl = $payload['confirmation'] ?? env('EPAYCO_CONFIRMATION_URL');
            if (is_string($responseUrl)) {
                $responseUrl = trim($responseUrl, " \t\n\r\0\x0B`\"'");
            }
            if (is_string($confirmationUrl)) {
                $confirmationUrl = trim($confirmationUrl, " \t\n\r\0\x0B`\"'");
            }

            // Helper to validate a URL is absolute, HTTPS and not localhost/private
            $isValidPublicHttps = function ($url) {
                if (empty($url)) return false;
                if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
                $parts = parse_url($url);
                if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') return false;
                if (empty($parts['host'])) return false;
                $host = $parts['host'];
                // Reject localhost and private IP ranges
                if (in_array($host, ['localhost', '127.0.0.1', '::1'])) return false;
                if (filter_var($host, FILTER_VALIDATE_IP)) {
                    // private ranges
                    if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1]))/', $host)) return false;
                }
                return true;
            };

            if ($responseUrl) {
                if (! $isValidPublicHttps($responseUrl)) {
                    Log::error('EpaycoController invalid response URL', ['response' => $responseUrl]);
                    return response()->json([
                        'success' => false,
                        'message' => 'La URL `response` debe ser una URL pública y segura (https). Usa ngrok/localtunnel y configura EPAYCO_RESPONSE_URL en .env'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $payload['response'] = $responseUrl;
            }

            if ($confirmationUrl) {
                if (! $isValidPublicHttps($confirmationUrl)) {
                    Log::error('EpaycoController invalid confirmation URL', ['confirmation' => $confirmationUrl]);
                    return response()->json([
                        'success' => false,
                        'message' => 'La URL `confirmation` debe ser una URL pública y segura (https). Usa ngrok/localtunnel y configura EPAYCO_CONFIRMATION_URL en .env'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $payload['confirmation'] = $confirmationUrl;
            }

            // 3. Create session
            $sessionRes = Http::withToken($apifyToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://apify.epayco.co/payment/session/create', $payload);

            // Debug log: show raw session create response for troubleshooting
            Log::debug('Epayco Apify session create response', ['status' => $sessionRes->status(), 'body' => $sessionRes->body(), 'payload' => $payload]);

            if (! $sessionRes->successful()) {
                Log::error('Epayco session create failed', ['status' => $sessionRes->status(), 'body' => $sessionRes->body()]);
                return response()->json(['success' => false, 'message' => 'Failed to create ePayco session', 'details' => $sessionRes->json()], Response::HTTP_BAD_GATEWAY);
            }

            // Return the full apify response to the frontend (including sessionId)
            return response()->json($sessionRes->json());

        } catch (\Exception $e) {
            Log::error('EpaycoController exception', ['exception' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
