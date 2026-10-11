<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Your payslips, payment receipts and certificates. Each one carries a verification code: anyone can check it at {{ url('/verify') }}.
        <span class="block" dir="rtl" lang="ur">آپ کی تنخواہ کی پرچیاں، رسیدیں اور سرٹیفکیٹ۔</span>
    </p>
    {{ $this->table }}
</x-filament-panels::page>
