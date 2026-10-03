<?php

namespace App\Services\Zoho;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WaOrder;
use App\Models\ZohoIntegration;
use Illuminate\Support\Facades\Log;

/**
 * Handles syncing contacts, leads, and orders to Zoho CRM.
 */
class ZohoSyncService
{
    public function __construct(private readonly ZohoService $zoho) {}

    /** The active Zoho integration for a workspace, or null. */
    public function integrationFor(int $workspaceId): ?ZohoIntegration
    {
        return ZohoIntegration::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    /** Does this workspace have a live Zoho link? */
    public function isConnected(int $workspaceId): bool
    {
        return ZohoIntegration::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Push a WaDesk Contact to Zoho CRM as a Contact.
     */
    public function syncContact(ZohoIntegration $integration, Contact $contact): array|false
    {
        $phone = preg_replace('/\D+/', '', (string) ($contact->phone ?? ''));
        $email = trim((string) ($contact->email ?? ''));
        $name = trim((string) ($contact->name ?? ''));

        if ($phone === '' && $email === '') {
            return false;
        }

        [$first, $last] = $this->splitName($name ?: ($phone ? ('+' . $phone) : 'WhatsApp Contact'));

        $payload = array_filter([
            'First_Name'  => $first ?: null,
            'Last_Name'   => $last ?: 'Lead',
            'Phone'       => $phone ? ('+' . $phone) : null,
            'Email'       => $email ?: null,
            'Description' => 'Captured from Seqelo WhatsApp workspace #' . $integration->workspace_id,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->zoho->upsertContact($integration, $payload);
    }

    /**
     * Push a WaDesk order to Zoho CRM as a Contact + Deal.
     */
    public function syncOrder(WaOrder $order): array|false
    {
        $integration = $this->integrationFor((int) $order->workspace_id);
        if (! $integration) {
            return false;
        }

        $name = trim((string) ($order->customer_name ?? ''));
        $email = trim((string) ($order->customer_email ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($order->customer_phone ?? ''));

        if ($email === '' && $phone === '') {
            return false;
        }

        [$first, $last] = $this->splitName($name ?: 'Customer');

        // 1. Upsert Contact first
        $contactRes = $this->zoho->upsertContact($integration, array_filter([
            'First_Name' => $first ?: null,
            'Last_Name'  => $last ?: 'Customer',
            'Phone'      => $phone ? ('+' . $phone) : null,
            'Email'      => $email ?: null,
        ]));

        $contactId = $contactRes['contact_id'] ?? null;

        // 2. Create Deal associated with the Contact
        $amount = number_format(((int) ($order->total_minor ?? 0)) / 100, 2, '.', '');
        $closingDate = now()->addDays(7)->toDateString();

        $dealData = array_filter([
            'Deal_Name'    => 'Order #' . ($order->order_number ?: $order->id) . ' (' . ($order->currency_code ?: 'USD') . ' ' . $amount . ')',
            'Stage'        => $this->mapOrderStatusToStage((string) $order->status),
            'Amount'       => (float) $amount,
            'Closing_Date' => $closingDate,
            'Contact_Name' => $contactId ? ['id' => $contactId] : null,
            'Description'  => 'WhatsApp Storefront Order #' . ($order->order_number ?: $order->id),
        ]);

        $dealRes = $this->zoho->createDeal($integration, $dealData);

        // Store Zoho IDs in order meta
        if (! empty($dealRes['deal_id']) || ! empty($contactId)) {
            $meta = is_array($order->meta_json) ? $order->meta_json : [];
            if (! empty($dealRes['deal_id'])) {
                $meta['zoho_deal_id'] = (string) $dealRes['deal_id'];
            }
            if ($contactId) {
                $meta['zoho_contact_id'] = (string) $contactId;
            }
            $meta['zoho_synced_at'] = now()->toIso8601String();
            $order->forceFill(['meta_json' => $meta])->saveQuietly();
        }

        return $dealRes;
    }

    /**
     * Map WaOrder status to standard Zoho CRM Deal stage.
     */
    private function mapOrderStatusToStage(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'completed', 'delivered' => 'Closed Won',
            'cancelled', 'refunded'          => 'Closed Lost',
            'shipped', 'processing'          => 'Negotiation/Review',
            default                          => 'Qualification',
        };
    }

    /**
     * Split a full name into first and last.
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name));
        if (empty($parts) || $parts[0] === '') {
            return ['WhatsApp', 'Contact'];
        }
        if (count($parts) === 1) {
            return [$parts[0], '.'];
        }
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }
}
