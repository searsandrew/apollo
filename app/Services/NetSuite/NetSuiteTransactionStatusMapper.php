<?php

namespace App\Services\NetSuite;

class NetSuiteTransactionStatusMapper
{
    /**
     * @var array<string, array<string, string>>
     */
    private const array STATUS_LABELS = [
        'CashSale' => [
            'A' => 'Unapproved Payment',
            'B' => 'Not Deposited',
            'C' => 'Deposited',
        ],
        'Check' => [
            'V' => 'Voided',
            'Z' => 'Online Bill Pay Pending Accounting Approval',
        ],
        'Commissn' => [
            'A' => 'Pending Payment',
            'O' => 'Overpaid',
            'P' => 'Pending Accounting Approval',
            'R' => 'Rejected by Accounting',
            'X' => 'Paid in Full',
        ],
        'CustChrg' => [
            'A' => 'Open',
            'B' => 'Paid In Full',
        ],
        'CustCred' => [
            'A' => 'Open',
            'B' => 'Fully Applied',
        ],
        'CustDep' => [
            'A' => 'Not Deposited',
            'B' => 'Deposited',
            'C' => 'Fully Applied',
        ],
        'CustInvc' => [
            'A' => 'Open',
            'B' => 'Paid In Full',
        ],
        'CustPymt' => [
            'A' => 'Unapproved Payment',
            'B' => 'Not Deposited',
            'C' => 'Deposited',
        ],
        'CustRfnd' => [
            'V' => 'Voided',
        ],
        'Estimate' => [
            'A' => 'Open',
            'B' => 'Processed',
            'C' => 'Closed',
            'V' => 'Voided',
            'X' => 'Expired',
        ],
        'ExpRept' => [
            'A' => 'In Progress',
            'B' => 'Pending Supervisor Approval',
            'C' => 'Pending Accounting Approval',
            'D' => 'Rejected by Supervisor',
            'E' => 'Rejected by Accounting',
            'F' => 'Approved by Accounting',
            'G' => 'Approved (Overridden) by Accounting',
            'H' => 'Rejected (Overridden) by Accounting',
            'I' => 'Paid In Full',
        ],
        'ItemShip' => [
            'A' => 'Picked',
            'B' => 'Packed',
            'C' => 'Shipped',
        ],
        'Journal' => [
            'A' => 'Pending Approval',
            'B' => 'Approved for Posting',
        ],
        'LiabPymt' => [
            'V' => 'Voided',
        ],
        'Opprtnty' => [
            'A' => 'In Progress',
            'B' => 'Issued Estimate',
            'C' => 'Closed - Won',
            'D' => 'Closed - Lost',
        ],
        'Paycheck' => [
            'A' => 'Undefined',
            'C' => 'Pending Tax Calculation',
            'D' => 'Pending Commitment',
            'F' => 'Committed',
            'P' => 'Preview',
            'R' => 'Reversed',
        ],
        'PurchOrd' => [
            'A' => 'Pending Supervisor Approval',
            'B' => 'Pending Receipt',
            'C' => 'Rejected by Supervisor',
            'D' => 'Partially Received',
            'E' => 'Pending Billing/Partially Received',
            'F' => 'Pending Bill',
            'G' => 'Fully Billed',
            'H' => 'Closed',
        ],
        'RtnAuth' => [
            'A' => 'Pending Approval',
            'B' => 'Pending Receipt',
            'C' => 'Cancelled',
            'D' => 'Partially Received',
            'E' => 'Pending Refund/Partially Received',
            'F' => 'Pending Refund',
            'G' => 'Refunded',
            'H' => 'Closed',
        ],
        'SalesOrd' => [
            'A' => 'Pending Approval',
            'B' => 'Pending Fulfillment',
            'C' => 'Cancelled',
            'D' => 'Partially Fulfilled',
            'E' => 'Pending Billing/Partially Fulfilled',
            'F' => 'Pending Billing',
            'G' => 'Billed',
            'H' => 'Closed',
        ],
        'TaxLiab' => [
            'V' => 'Voided',
        ],
        'TaxPymt' => [
            'V' => 'Voided',
            'Z' => 'Online Bill Pay Pending Accounting Approval',
        ],
        'TegPybl' => [
            'E' => 'Endorsed',
            'I' => 'Issued',
            'P' => 'Paid',
        ],
        'TegRcvbl' => [
            'C' => 'Collected',
            'D' => 'Discounted',
            'E' => 'Endorsed',
            'H' => 'Holding',
        ],
        'TrnfrOrd' => [
            'A' => 'Pending Approval',
            'B' => 'Pending Fulfillment',
            'C' => 'Rejected',
            'D' => 'Partially Fulfilled',
            'E' => 'Pending Receipt/Partially Fulfilled',
            'F' => 'Pending Receipt',
            'G' => 'Received',
            'H' => 'Closed',
        ],
        'VendAuth' => [
            'A' => 'Pending Approval',
            'B' => 'Pending Return',
            'C' => 'Cancelled',
            'D' => 'Partially Returned',
            'E' => 'Pending Credit/Partially Returned',
            'F' => 'Pending Credit',
            'G' => 'Credited',
            'H' => 'Closed',
        ],
        'VendBill' => [
            'A' => 'Open',
            'B' => 'Paid In Full',
        ],
        'VendPymt' => [
            'V' => 'Voided',
            'Z' => 'Online Bill Pay Pending Accounting Approval',
        ],
        'WorkOrd' => [
            'B' => 'Pending Build',
            'C' => 'Cancelled',
            'D' => 'Partially Built',
            'G' => 'Built',
            'H' => 'Closed',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const array TYPE_LABELS = [
        'CashSale' => 'Cash Sale',
        'Check' => 'Check',
        'Commissn' => 'Commission',
        'CustChrg' => 'Statement Charge',
        'CustCred' => 'Credit Memo',
        'CustDep' => 'Customer Deposit',
        'CustInvc' => 'Invoice',
        'CustPymt' => 'Payment',
        'CustRfnd' => 'Customer Refund',
        'Estimate' => 'Quote',
        'ExpRept' => 'Expense Report',
        'ItemShip' => 'Item Fulfillment',
        'Journal' => 'Journal',
        'LiabPymt' => 'Payroll Liability Check',
        'Opprtnty' => 'Opportunity',
        'Paycheck' => 'Paycheck',
        'PurchOrd' => 'Purchase Order',
        'RtnAuth' => 'Return Authorization',
        'SalesOrd' => 'Sales Order',
        'TaxLiab' => 'Tax Liability Cheque',
        'TaxPymt' => 'Sales Tax Payment',
        'TegPybl' => 'Tegata Payable',
        'TegRcvbl' => 'Tegata Receivables',
        'TrnfrOrd' => 'Transfer Order',
        'VendAuth' => 'Vendor Return Authorization',
        'VendBill' => 'Bill',
        'VendPymt' => 'Bill Payment',
        'WorkOrd' => 'Work Order',
    ];

    public function label(?string $type, ?string $status): string
    {
        $status = trim((string) $status);

        if ($status === '') {
            return '-';
        }

        [$transactionType, $statusCode] = $this->normalizeStatus($type, $status);

        return self::STATUS_LABELS[$transactionType][$statusCode] ?? $status;
    }

    public function color(?string $type, ?string $status): string
    {
        $label = $this->label($type, $status);

        if ($label === '-') {
            return 'zinc';
        }

        $status = str($label)->lower();

        if ($status->contains(['cancel', 'reject', 'void', 'expired', 'lost', 'reversed'])) {
            return 'red';
        }

        if ($status->contains('partial')) {
            return 'blue';
        }

        if ($status->contains(['pending', 'unapproved', 'in progress', 'not deposited', 'picked', 'packed', 'holding', 'preview', 'undefined', 'issued'])) {
            return 'amber';
        }

        if ($status->contains('closed') && ! $status->contains('won')) {
            return 'zinc';
        }

        if ($status->contains(['paid', 'applied', 'deposit', 'approved', 'billed', 'shipped', 'posting', 'won', 'processed', 'refunded', 'received', 'built', 'committed', 'credited', 'collected', 'endorsed'])) {
            return 'green';
        }

        if ($status->contains('open')) {
            return 'amber';
        }

        return 'zinc';
    }

    public function typeLabel(?string $type): string
    {
        $type = $this->canonicalType(trim((string) $type));

        if ($type === '') {
            return '-';
        }

        return self::TYPE_LABELS[$type] ?? $type;
    }

    public function typeColor(?string $type): string
    {
        return match ($this->canonicalType(trim((string) $type))) {
            'CustInvc' => 'blue',
            'CustCred' => 'amber',
            default => 'zinc',
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeStatus(?string $type, string $status): array
    {
        $transactionType = trim((string) $type);
        $statusCode = $status;

        if (str_contains($statusCode, ':')) {
            [$prefixedType, $prefixedStatus] = explode(':', $statusCode, 2);

            if ($transactionType === '') {
                $transactionType = $prefixedType;
            }

            $statusCode = $prefixedStatus;
        }

        return [
            $this->canonicalType($transactionType),
            strtoupper(trim($statusCode)),
        ];
    }

    private function canonicalType(string $type): string
    {
        foreach (array_keys(self::STATUS_LABELS) as $knownType) {
            if (strcasecmp($knownType, $type) === 0) {
                return $knownType;
            }
        }

        return $type;
    }
}
