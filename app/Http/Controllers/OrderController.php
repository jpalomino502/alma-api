<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Http\Controllers\EpaycoController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\ApiToken;

/**
 * Minimal checkout implementation that delegates session creation to EpaycoController.
 */

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user || !$user->is_admin) return response()->json(['message' => 'No autorizado'], 401);
        $q = Order::with('user')->orderByDesc('id');
        $status = $request->query('status');
        if ($status) $q->where('status', $status);
        $from = $request->query('from');
        $to = $request->query('to');
        if ($from) $q->whereDate('created_at', '>=', $from);
        if ($to) $q->whereDate('created_at', '<=', $to);
        return response()->json($q->get());
    }

    public function show(Order $order)
    {
        return response()->json($order);
    }

    public function my(Request $request)
    {
        $user = $this->authUser($request);
        if (!$user) return response()->json(['message' => 'No autorizado'], 401);
        $q = Order::with('user')->where('user_id', $user->id)->orderByDesc('id');
        return response()->json($q->get());
    }

    protected function authUser(Request $request)
    {
        $authHeader = $request->header('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        $plain = substr($authHeader, 7);
        $hash = hash('sha256', $plain);
        $token = ApiToken::where('token_hash', $hash)->where('revoked', false)->first();
        return $token ? $token->user : null;
    }

    public function checkout(Request $request)
    {
        // In a real app you'd create the Order record here, validate items, prices, etc.
        // The frontend sends `total`/`subtotal`/`items`. Ensure `amount` is present
        // for EpaycoController which validates `amount` as required.
        $amount = $request->input('amount');
        if (is_null($amount)) {
            $amount = $request->input('total') ?? $request->input('total_price') ?? $request->input('subtotal');
        }

        if (is_null($amount)) {
            return response()->json([
                'success' => false,
                'message' => 'El campo `amount` o `total` es requerido.'
            ], 422);
        }

        if (!is_numeric($amount)) {
            return response()->json([
                'success' => false,
                'message' => 'El campo `amount` debe ser numérico.'
            ], 422);
        }

        // Normalize amount and currency defaults
        $request->merge([
            'amount' => (float) $amount,
            'currency' => $request->input('currency', 'COP'),
        ]);

        // Create order record in DB (pending_payment)
        $user = $this->authUser($request);
        $items = $request->input('items', []);
        $subtotal = (int) ($request->input('subtotal') ?? $amount);
        $taxes = (int) ($request->input('taxes') ?? 0);
        $total = (int) ($request->input('total') ?? $amount);
        $order = Order::create([
            'user_id' => $user?->id,
            'items' => $items,
            'subtotal' => $subtotal,
            'taxes' => $taxes,
            'total' => $total,
            'status' => 'pending_payment',
        ]);

        // Delegate to EpaycoController to create session
        $epay = new EpaycoController();
        $sessionResponse = $epay->createSession($request);
        // If ePayco session creation failed, bubble response
        if ($sessionResponse->status() >= 300) {
            return $sessionResponse;
        }
        $payload = $sessionResponse->getData(true);
        // Attach order_id to response so frontend can correlate and send ref later
        if (is_array($payload)) {
            $payload['order_id'] = $order->id;
        }
        return response()->json($payload, $sessionResponse->status());
    }

    /**
     * Webhook endpoint for ePayco confirmation (server-to-server).
     * Validates signature and responds 200 OK.
     */
    public function epaycoCallback(Request $request)
    {
        $data = $request->all();

        // Support both param names
        $ref = $data['x_ref_payco'] ?? $data['ref_payco'] ?? null;
        $transactionId = $data['x_transaction_id'] ?? $data['transaction_id'] ?? null;
        $amount = $data['x_amount'] ?? $data['amount'] ?? null;
        $currency = $data['x_currency_code'] ?? $data['currency'] ?? null;
        $signature = $data['x_signature'] ?? null;

        $p_cust = env('EPAYCO_CUSTOMER_ID');
        $p_key = env('EPAYCO_P_KEY');

        if (empty($p_cust) || empty($p_key)) {
            Log::warning('epaycoCallback missing EPAYCO_CUSTOMER_ID or EPAYCO_P_KEY in .env');
            return response()->json(['success' => false, 'message' => 'Server not configured'], 500);
        }

        // Build signature
        $computed = hash('sha256', sprintf("%s^%s^%s^%s^%s^%s", $p_cust, $p_key, $ref ?? '', $transactionId ?? '', $amount ?? '', $currency ?? ''));

        if (! $signature || $computed !== $signature) {
            Log::warning('epaycoCallback invalid signature', ['computed' => $computed, 'received' => $signature, 'payload' => $data]);
            return response('Invalid signature', 400);
        }

        // Signature valid — process state and update order
        $state = $data['x_response'] ?? $data['x_transaction_state'] ?? $data['transaction_state'] ?? null;
        Log::info('epaycoCallback received', ['ref' => $ref, 'transaction' => $transactionId, 'state' => $state]);

        $order = null;
        if ($ref) {
            $order = Order::where('epayco_ref', $ref)->first();
        }
        if (!$order && $transactionId) {
            $order = Order::where('epayco_invoice', $transactionId)->first();
        }

        if ($order) {
            // Map ePayco states to internal order status
            $status = 'pending_payment';
            $map = [
                'Aceptada' => 'paid',
                'Aceptado' => 'paid',
                'Aceptada Test' => 'paid',
                'Pendiente' => 'pending_payment',
                'Pendiente Test' => 'pending_payment',
                'Rechazada' => 'rejected',
                'Fallida' => 'failed',
                'APPROVED' => 'paid',
                'Approved' => 'paid',
                'approved' => 'paid',
                'PENDING' => 'pending_payment',
                'Pending' => 'pending_payment',
                'pending' => 'pending_payment',
                'REJECTED' => 'rejected',
                'Rejected' => 'rejected',
                'rejected' => 'rejected',
                'FAILED' => 'failed',
                'Failed' => 'failed',
                'failed' => 'failed',
            ];
            if ($state && isset($map[$state])) {
                $status = $map[$state];
            }
            $order->status = $status;
            if ($transactionId) {
                $order->epayco_invoice = $transactionId;
            }
            if ($ref) {
                $order->epayco_ref = $ref;
            }
            $order->save();
        } else {
            Log::warning('epaycoCallback order not found for ref/invoice', ['ref' => $ref, 'transaction' => $transactionId]);
        }

        return response('OK', 200);
    }

    public function syncStatus(Request $request)
    {
        $orderId = $request->input('order_id');
        $ref = $request->input('ref') ?? $request->input('ref_payco') ?? $request->input('x_ref_payco');

        $order = null;
        if ($orderId) {
            $order = Order::find($orderId);
        }
        if (!$order && $ref) {
            $order = Order::where('epayco_ref', $ref)->first();
        }
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (!$ref) {
            $ref = $order->epayco_ref;
        }
        if (!$ref) {
            return response()->json(['success' => false, 'message' => 'Reference not available'], 422);
        }

        $res = Http::get('https://secure.epayco.co/validation/v1/reference/' . $ref);
        if (!$res->successful()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'status' => $res->status()], 502);
        }
        $json = $res->json();
        $payload = $json['data'] ?? $json ?? null;
        if (!is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 502);
        }
        $state = $payload['x_response'] ?? $payload['x_transaction_state'] ?? $payload['transaction_state'] ?? $payload['state'] ?? null;
        $map = [
            'Aceptada' => 'paid',
            'Aceptado' => 'paid',
            'Aceptada Test' => 'paid',
            'APPROVED' => 'paid',
            'Approved' => 'paid',
            'approved' => 'paid',
            'Pendiente' => 'pending_payment',
            'Pendiente Test' => 'pending_payment',
            'PENDING' => 'pending_payment',
            'Pending' => 'pending_payment',
            'pending' => 'pending_payment',
            'Rechazada' => 'rejected',
            'REJECTED' => 'rejected',
            'Rejected' => 'rejected',
            'rejected' => 'rejected',
            'Fallida' => 'failed',
            'FAILED' => 'failed',
            'Failed' => 'failed',
            'failed' => 'failed',
        ];
        if ($state && isset($map[$state])) {
            $order->status = $map[$state];
        }
        $invoice = $payload['x_id_invoice'] ?? $payload['invoice'] ?? null;
        if ($invoice) {
            $order->epayco_invoice = $invoice;
        }
        $order->epayco_ref = $ref;
        $order->save();
        return response()->json(['success' => true, 'order' => $order]);
    }

    public function updateRef(Order $order, Request $request)
    {
        $data = $request->validate([
            'ref' => 'required|string',
            'invoice' => 'nullable|string',
        ]);
        $order->epayco_ref = $data['ref'];
        if (!empty($data['invoice'])) {
            $order->epayco_invoice = $data['invoice'];
        }
        $order->save();
        return response()->json(['ok' => true, 'order_id' => $order->id]);
    }

    public function update(Request $request, Order $order)
    {
        $user = $this->authUser($request);
        if (!$user || !$user->is_admin) return response()->json(['message' => 'No autorizado'], 401);
        $data = $request->validate([
            'status' => ['required','string','max:50'],
        ]);
        $order->status = $data['status'];
        $order->save();
        return response()->json($order);
    }
}