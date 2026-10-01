<?php
declare(strict_types=1);

/** Derive the shared queue; approval remains with the original module endpoint. */
function tt_pending_director_rate_approvals(array $store): array {
    $requests = [];
    foreach (['bagSupplierBills' => 'bag', 'nonWovenBagSupplierBills' => 'nonwoven'] as $collection => $kind) {
        foreach ((array)($store[$collection] ?? []) as $key => $bill) {
            if (!is_array($bill) || !empty($bill['journalId']) || !empty($bill['rateExceptionApprovedAt'])
                || in_array($bill['status'] ?? '', ['Cancelled', 'Posted'], true)
                || !in_array($bill['entity'] ?? '', ['TTI', 'BRM'], true)) continue;
            $po = $store['bagPurchaseOrders'][$bill['poNo'] ?? ''] ?? [];
            $line = null;
            foreach ((array)($po['lines'] ?? []) as $candidate) {
                if (is_array($candidate) && ($candidate['lineKey'] ?? '') === ($bill['lineKey'] ?? '')) {
                    $line = $candidate;
                    break;
                }
            }
            // Use the current PO rate, exactly as the module's posting validation does.
            $poRate = (float)($line['ratePerBag'] ?? 0);
            if ($poRate <= 0 || abs((float)($bill['ratePerBag'] ?? 0) - $poRate) <= .0001) continue;
            $requests[] = [
                'kind' => $kind, 'billId' => (string)($bill['id'] ?? $key),
                'entity' => (string)$bill['entity'],
                'title' => ($kind === 'nonwoven' ? 'Non-Woven bag' : 'Bag') . ' rate approval · ' . (string)($bill['sellerInvoice'] ?? $key),
                'detail' => (string)$bill['entity'] . ' · ' . (string)($bill['supplier'] ?? '') . ' · PO ' . (string)($bill['poNo'] ?? '')
                    . ' · Invoice rate ' . (string)($bill['ratePerBag'] ?? 0) . ' / PO rate ' . (string)$poRate
                    . ' · ' . (string)($bill['status'] ?? '') . ' · ' . (string)($bill['createdBy'] ?? ''),
            ];
        }
    }
    return $requests;
}
