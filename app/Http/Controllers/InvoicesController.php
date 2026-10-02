<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\WaOrder;
use App\Models\Workspace;
use App\Services\Invoice\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Merchant-facing invoice list / detail / manual-create / download, plus the
 * PUBLIC token view+PDF the WhatsApp button links to. Interactive routes are
 * gated by plan:auto_invoice; the public /i/{token} routes are token-only.
 */
class InvoicesController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    public function index(Request $request)
    {
        $wsId = $this->wsId();
        // Optional source scope from a store tab: own | woocommerce | shopify.
        $source = in_array($request->query('source'), ['own', 'woocommerce', 'shopify'], true) ? $request->query('source') : null;
        $storeSources = $source === 'own' ? ['own', 'storefront', 'waba', 'twilio', 'whatsapp_ai', 'manual'] : ($source ? [$source] : null);

        $invQ = Invoice::forWorkspace($wsId)->orderByDesc('id');
        if ($source) {
            $invQ->when($source === 'own', fn ($q) => $q->whereIn('source', ['own', 'manual']), fn ($q) => $q->where('source', $source));
        }
        $invoices = $invQ->limit(100)->get();

        $ordQ = WaOrder::where('workspace_id', $wsId)->whereIn('status', ['paid', 'completed'])->whereDoesntHave('invoice');
        if ($storeSources) {
            $ordQ->whereIn('source', $storeSources);
        }
        $orders = $ordQ->orderByDesc('id')->limit(25)->get();

        $settings = \App\Models\InvoiceSetting::forWorkspace($wsId);
        // Setup checklist (surface every warning up-front).
        $isWaba = str_starts_with((string) $settings->send_sender, 'waba:');
        $autoKey = $source ? ('auto_send_'.($source === 'own' ? 'own' : $source)) : 'auto_send_own';
        $checklist = [
            ['label' => __('Invoices enabled'), 'ok' => (bool) $settings->enabled, 'hint' => __('Turn it on in Settings.')],
            ['label' => __('Company name & tax number set'), 'ok' => filled($settings->seller_name), 'hint' => __('Add your legal name, address and GST/VAT in Settings — it prints on every invoice.')],
            ['label' => __('WhatsApp sender connected'), 'ok' => filled($settings->send_sender), 'hint' => __('Pick a WABA number or Unofficial device in Settings.')],
            ['label' => $isWaba ? __('Meta template approved') : __('Delivery ready'), 'ok' => ! $isWaba || $settings->template_status === 'approved', 'hint' => __('WABA needs the invoice template approved by Meta — click “Create & submit” in Settings.')],
            ['label' => __('Auto-send is on for this store'), 'ok' => (bool) ($settings->$autoKey ?? false), 'hint' => __('Toggle auto-send for this store in Settings, or generate invoices manually below.')],
        ];

        return view('user.invoices.index', compact('invoices', 'orders', 'settings', 'source', 'checklist'));
    }

    public function show(int $id)
    {
        $invoice = Invoice::forWorkspace($this->wsId())->with('items', 'taxSummary', 'order')->findOrFail($id);

        return view('user.invoices.show', compact('invoice'));
    }

    /** Generate (or regenerate a not-yet-sent) invoice from a WaOrder. */
    public function store(Request $request)
    {
        $data = $request->validate(['wa_order_id' => 'required|integer']);
        $ws = Workspace::find($this->wsId());
        $order = WaOrder::where('workspace_id', $ws->id)->findOrFail($data['wa_order_id']);

        $invoice = $this->invoices->issue($this->invoices->manualDraftFromOrder($order), $ws, Auth::id());
        if (! $invoice) {
            return back()->with('error', 'Auto-invoice is not enabled on your plan.');
        }
        $this->invoices->renderAndSend($invoice); // render now; delivery is P3 / auto-send

        // Stay wherever the operator generated from (store / Woo / Shopify tab,
        // or the standalone list) — never yank them onto a bare detail page.
        return back()->with('success', 'Invoice '.$invoice->invoice_number.' generated.');
    }

    /* ── AI-CRM Phase 2.1 — free-form (order-less) invoice builder ─────────── */

    /** Line-item builder form for an ad-hoc invoice (no WaOrder needed). */
    public function createManual()
    {
        $wsId = $this->wsId();
        return view('user.invoices.create', [
            'settings' => \App\Models\InvoiceSetting::forWorkspace($wsId),
            // Optional CRM links so the money rolls up (Phase 2.3). Lean columns only.
            'contacts' => \App\Models\Contact::forCurrentWorkspace()->orderByDesc('id')->limit(500)->get(['id', 'name']),
            'companies'=> class_exists(\App\Models\Company::class)
                ? \App\Models\Company::forCurrentWorkspace()->orderBy('id')->limit(500)->get(['id', 'name'])
                : collect(),
            'deals'    => \App\Models\Deal::forCurrentWorkspace()->orderByDesc('id')->limit(500)->get(['id', 'title']),
        ]);
    }

    /**
     * Build an InvoiceDraft directly from the form (source='manual', no WaOrder)
     * and issue it through the SAME immutable engine every other source uses.
     */
    public function storeManual(Request $request)
    {
        $data = $request->validate([
            'buyer_name'   => 'required|string|max:191',
            'buyer_email'  => 'nullable|email|max:191',
            'buyer_phone'  => 'nullable|string|max:40',
            'doc_type'     => ['nullable', \Illuminate\Validation\Rule::in(['tax_invoice', 'receipt', 'proforma'])],
            'currency'     => 'nullable|string|size:3',
            'discount'     => 'nullable|numeric|min:0',
            'shipping'     => 'nullable|numeric|min:0',
            'note'         => 'nullable|string|max:2000',
            'items'                => 'required|array|min:1|max:100',
            'items.*.description'  => 'required|string|max:500',
            'items.*.qty'          => 'required|numeric|min:0.001|max:100000',
            'items.*.unit_price'   => 'required|numeric|min:0|max:100000000',
            'items.*.tax_rate'     => 'nullable|numeric|min:0|max:100',
            // Optional CRM links.
            'contact_id'   => 'nullable|integer',
            'company_id'   => 'nullable|integer',
            'deal_id'      => 'nullable|integer',
        ]);

        $ws = Workspace::find($this->wsId());
        if (! $ws) { return back()->with('error', 'No workspace.'); }

        $currency = strtoupper((string) (($data['currency'] ?? '') ?: (\App\Models\InvoiceSetting::forWorkspace($ws->id)->currency ?: ($ws->currency ?: 'USD'))));
        $exp      = $this->currencyExponent($currency);
        $unit     = 10 ** $exp; // minor units per 1 major

        $items = [];
        $subtotalMinor = 0; $taxMinor = 0; $byRate = [];
        foreach ($data['items'] as $row) {
            $qty       = (float) $row['qty'];
            $priceMin  = (int) round(((float) $row['unit_price']) * $unit);
            $lineSub   = (int) round($priceMin * $qty);
            $rate      = (float) ($row['tax_rate'] ?? 0);
            $lineTax   = (int) round($lineSub * $rate / 100);
            $items[] = [
                'description'        => (string) $row['description'],
                'sku'                => null,
                'hsn_sac'            => null,
                'qty'                => $qty,
                'unit_price_minor'   => $priceMin,
                'line_subtotal_minor'=> $lineSub,
                'line_discount_minor'=> 0,
                'tax_rate'           => $rate,
                'tax_amount_minor'   => $lineTax,
                'tax_code'           => null,
            ];
            $subtotalMinor += $lineSub;
            $taxMinor      += $lineTax;
            if ($rate > 0) {
                $k = (string) $rate;
                $byRate[$k] = ($byRate[$k] ?? ['label' => 'Tax ' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%', 'rate' => $rate, 'base_minor' => 0, 'amount_minor' => 0]);
                $byRate[$k]['base_minor']   += $lineSub;
                $byRate[$k]['amount_minor'] += $lineTax;
            }
        }
        $discountMinor = (int) round(((float) ($data['discount'] ?? 0)) * $unit);
        $shippingMinor = (int) round(((float) ($data['shipping'] ?? 0)) * $unit);
        $totalMinor    = max(0, $subtotalMinor - $discountMinor + $shippingMinor + $taxMinor);

        $draft = new \App\Services\Invoice\InvoiceDraft(
            source: 'manual',
            docType: $data['doc_type'] ?? 'tax_invoice',
            currency: $currency,
            currencyExponent: $exp,
            buyer: array_filter([
                'name'  => $data['buyer_name'],
                'email' => $data['buyer_email'] ?? null,
                'phone' => $data['buyer_phone'] ?? null,
            ]),
            items: $items,
            taxSummary: array_values($byRate),
            subtotalMinor: $subtotalMinor,
            discountMinor: $discountMinor,
            shippingMinor: $shippingMinor,
            taxMinor: $taxMinor,
            totalMinor: $totalMinor,
            waOrderId: null,
            trigger: 'manual',
            // CRM links + note travel in meta so revenue can roll up + audit.
            meta: array_filter([
                'note'       => $data['note'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'company_id' => $data['company_id'] ?? null,
                'deal_id'    => $data['deal_id'] ?? null,
            ]),
        );

        $invoice = $this->invoices->issue($draft, $ws, Auth::id());
        if (! $invoice) {
            return back()->withInput()->with('error', 'Invoicing is not enabled on your plan.');
        }
        $this->invoices->renderAndSend($invoice);

        return redirect()->route('user.invoices.show', $invoice->id)
            ->with('success', 'Invoice ' . $invoice->invoice_number . ' created.');
    }

    /** Minor-unit exponent for a currency — 0 for zero-decimal (JPY/IDR/…), else 2. */
    private function currencyExponent(string $currency): int
    {
        static $zero = ['JPY', 'KRW', 'VND', 'IDR', 'CLP', 'ISK', 'HUF', 'XAF', 'XOF', 'PYG', 'RWF', 'UGX', 'KMF'];
        return in_array(strtoupper($currency), $zero, true) ? 0 : 2;
    }

    /** Resend the same frozen PDF (P3 delivery). */
    public function resend(int $id)
    {
        $invoice = Invoice::forWorkspace($this->wsId())->findOrFail($id);
        $res = $this->invoices->resend($invoice);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Invoice re-sent.' : ('Could not send: '.($res['error'] ?? 'unknown')));
    }

    /** Stream the merchant's own copy of the PDF. */
    public function pdf(int $id)
    {
        $invoice = Invoice::forWorkspace($this->wsId())->findOrFail($id);

        return $this->streamPdf($invoice);
    }

    // ── Public (token-only) ────────────────────────────────────────────────

    public function publicShow(string $token)
    {
        $invoice = Invoice::where('public_token', $token)->with('items', 'taxSummary')->firstOrFail();

        // Offer "Pay now" through the MERCHANT's own configured gateways (same
        // WaMerchantGateway the storefront/booking checkout uses — money lands in
        // the client's account, not the platform's). Only when still unpaid and
        // the total is positive.
        $gateways = collect();
        if ($invoice->status !== Invoice::STATUS_PAID && (int) $invoice->total_minor > 0) {
            $gateways = \App\Models\WaMerchantGateway::query()->active()
                ->where('workspace_id', (int) $invoice->workspace_id)
                ->where('storefront_id', 0)
                ->get()
                ->filter(fn ($g) => $g->isConfigured())
                ->map(fn ($g) => ['slug' => $g->slug, 'label' => $g->display_label ?? ucfirst($g->slug)])
                ->values();
        }

        return view('public.invoice', compact('invoice', 'gateways'));
    }

    /**
     * Public: start a payment for an invoice through the merchant's own gateway.
     * Creates an Order that carries the invoice id, initiates the gateway, and
     * hands the customer the redirect / SDK checkout. Token-only (no auth).
     */
    public function publicPay(string $token, string $gateway)
    {
        $invoice = Invoice::where('public_token', $token)->firstOrFail();
        if ($invoice->status === Invoice::STATUS_PAID) {
            return redirect()->route('invoice.public.show', $token)->with('info', __('This invoice is already paid.'));
        }
        if ((int) $invoice->total_minor <= 0) {
            return redirect()->route('invoice.public.show', $token)->with('error', __('This invoice has no amount to pay.'));
        }

        $merchant = \App\Models\WaMerchantGateway::query()->active()
            ->where('workspace_id', (int) $invoice->workspace_id)
            ->where('storefront_id', 0)
            ->where('slug', $gateway)
            ->first();
        if (! $merchant || ! $merchant->isConfigured()) {
            return redirect()->route('invoice.public.show', $token)->with('error', __('That payment method is not available.'));
        }

        try {
            $driver = app(\App\Services\Payment\PaymentGatewayManager::class)
                ->driverFromModel($merchant->toTransientPaymentGateway());
        } catch (\Throwable $e) {
            return redirect()->route('invoice.public.show', $token)->with('error', __('Payment method unavailable, please try another.'));
        }

        $exp    = (int) ($invoice->currency_exponent ?? 2);
        $amount = (float) $invoice->total_minor / (10 ** max(0, $exp));

        $order = \App\Models\Order::create([
            'order_number'    => 'INV-' . strtoupper(\Illuminate\Support\Str::random(10)),
            'workspace_id'    => (int) $invoice->workspace_id,
            'user_id'         => (int) $invoice->user_id,
            'gateway_id'      => null,
            'gateway_slug'    => $gateway,
            'currency'        => (string) $invoice->currency,
            'amount'          => $amount,
            'total_amount'    => $amount,
            'status'          => 'pending',
            'gateway_payload' => ['invoice' => ['invoice_id' => (int) $invoice->id, 'public_token' => $token]],
        ]);

        $callbackUrl = route('invoice.pay.callback', ['gateway' => $gateway]) . '?io=' . $order->id;

        try {
            $result = $driver->initiate($order, $callbackUrl);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INVOICE-PAY] initiate failed inv=' . $invoice->id . ': ' . $e->getMessage());
            return redirect()->route('invoice.public.show', $token)->with('error', __('Could not start the payment. Please try again.'));
        }

        if ($result->gatewayOrderId) {
            $order->update([
                'gateway_order_id' => $result->gatewayOrderId,
                'gateway_payload'  => array_merge((array) $order->gateway_payload, (array) $result->payload),
            ]);
        }
        if ($result->status === 'paid') {
            $this->markInvoicePaid($invoice, $order, $result->gatewayPaymentId);
            return redirect()->route('invoice.public.show', $token)->with('success', __('Payment received. Thank you!'));
        }
        if ($result->redirectUrl) return redirect()->away($result->redirectUrl);
        if ($result->html)        return response($result->html);

        return redirect()->route('invoice.public.show', $token)->with('error', __('Could not open the payment page.'));
    }

    /**
     * Public: the gateway redirects the customer here after paying an invoice.
     * Verifies via the merchant driver, marks the Order + Invoice paid.
     */
    public function payCallback(string $gateway, Request $request)
    {
        $payload = array_merge($request->query() ?: [], $request->post() ?: []);

        $order = null;
        if (ctype_digit((string) $request->query('io'))) {
            $order = \App\Models\Order::find((int) $request->query('io'));
        }
        if (! $order) {
            $hint = $payload['order_id'] ?? $payload['razorpay_order_id'] ?? $payload['token']
                ?? $payload['txnid'] ?? $payload['merchantTransactionId'] ?? $payload['session_id'] ?? null;
            if ($hint) $order = \App\Models\Order::where('gateway_order_id', $hint)->first();
        }
        $invoiceId = (int) data_get($order?->gateway_payload, 'invoice.invoice_id', 0);
        $invoice   = $invoiceId ? Invoice::find($invoiceId) : null;
        $backToken = (string) data_get($order?->gateway_payload, 'invoice.public_token', '');

        if (! $order || ! $invoice) {
            return response()->view('public.invoice-pay-result', ['state' => 'error', 'message' => __('We could not find that invoice payment.'), 'token' => $backToken]);
        }

        $merchant = \App\Models\WaMerchantGateway::query()->active()
            ->where('workspace_id', (int) $order->workspace_id)
            ->where('storefront_id', 0)
            ->where('slug', $gateway)
            ->first();
        if (! $merchant || ! $merchant->isConfigured()) {
            return response()->view('public.invoice-pay-result', ['state' => 'error', 'message' => __('Unknown payment provider.'), 'token' => $backToken]);
        }

        try {
            $driver = app(\App\Services\Payment\PaymentGatewayManager::class)->driverFromModel($merchant->toTransientPaymentGateway());
            $res    = $driver->handleCallback($payload);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INVOICE-PAY] callback failed order=' . $order->id . ': ' . $e->getMessage());
            return response()->view('public.invoice-pay-result', ['state' => 'error', 'message' => __('We could not verify the payment. If you were charged, contact the business.'), 'token' => $backToken]);
        }

        if ($res->gatewayOrderId) {
            $order->update([
                'gateway_order_id' => $res->gatewayOrderId,
                'gateway_payload'  => array_merge((array) $order->gateway_payload, (array) $res->payload),
            ]);
        }

        if ($res->status === 'paid') {
            if ($order->status !== 'paid') {
                $order->forceFill(['status' => 'paid', 'paid_at' => now(), 'gateway_payment_id' => $res->gatewayPaymentId])->save();
            }
            $this->markInvoicePaid($invoice, $order, $res->gatewayPaymentId);
            return redirect()->route('invoice.public.show', $backToken)->with('success', __('Payment received. Thank you!'));
        }

        if ($res->status === 'failed') {
            $order->forceFill(['status' => 'failed', 'failure_reason' => $res->error])->save();
            return response()->view('public.invoice-pay-result', ['state' => 'failed', 'message' => __('Payment failed. Please try again.'), 'token' => $backToken]);
        }
        if ($res->redirectUrl) return redirect()->away($res->redirectUrl);

        return response()->view('public.invoice-pay-result', ['state' => 'pending', 'message' => __('Your payment is being processed. You will get a confirmation shortly.'), 'token' => $backToken]);
    }

    /** Mark an invoice PAID (idempotent) once its payment Order is confirmed. */
    private function markInvoicePaid(Invoice $invoice, \App\Models\Order $order, ?string $paymentId): void
    {
        if ($invoice->status === Invoice::STATUS_PAID) return;
        try {
            $invoice->forceFill([
                'status'   => Invoice::STATUS_PAID,
                'paid_at'  => now(),
                'meta_json' => array_merge((array) $invoice->meta_json, [
                    'paid_via'          => $order->gateway_slug,
                    'payment_order_id'  => $order->id,
                    'gateway_payment_id'=> $paymentId,
                ]),
            ])->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INVOICE-PAY] markPaid failed inv=' . $invoice->id . ': ' . $e->getMessage());
        }
    }

    public function publicPdf(string $token)
    {
        $invoice = Invoice::where('public_token', $token)->firstOrFail();

        return $this->streamPdf($invoice);
    }

    private function streamPdf(Invoice $invoice)
    {
        // Render on demand if it was never rendered (e.g. plan flipped after issue).
        if (! $invoice->pdf_path || ! media_storage()->exists($invoice->pdf_path)) {
            $this->invoices->renderAndSend($invoice);
            $invoice->refresh();
        }
        abort_unless($invoice->pdf_path && media_storage()->exists($invoice->pdf_path), 404);
        $bytes = media_storage()->get($invoice->pdf_path);

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->invoice_number.'.pdf"',
        ]);
    }
}
