<?php

// Labels printed on HR documents. Keys are stored in document content; the text is chosen at print time.
return [
    'titles' => [
        'payslip' => 'Payslip', 'wage_slip' => 'Wage slip', 'advance_voucher' => 'Advance / loan voucher', 'bonus_slip' => 'Bonus slip',
        'cash_receipt' => 'Salary payment receipt', 'attendance_summary' => 'Attendance summary', 'payroll_register' => 'Payroll register',
        'salary_certificate' => 'Salary certificate', 'employment_letter' => 'Employment certificate',
        'reimbursement_voucher' => 'Reimbursement voucher', 'contractor_bill' => 'Labour contractor bill',
    ],
    'labels' => [
        'number' => 'No.', 'date' => 'Date', 'month' => 'Month', 'period' => 'Period', 'employee' => 'Employee', 'code' => 'Code', 'name' => 'Name',
        'designation' => 'Designation', 'department' => 'Department', 'cnic' => 'CNIC', 'iban' => 'Bank account', 'joining_date' => 'Date of joining',
        'pay_basis' => 'Pay basis', 'paid_days' => 'Paid days', 'calendar_days' => 'Days in month', 'present' => 'Present', 'absent' => 'Absent',
        'paid_leave' => 'Paid leave', 'unpaid_leave' => 'Leave without pay', 'holidays' => 'Holidays', 'weekly_offs' => 'Weekly offs',
        'overtime_hours' => 'Overtime hours', 'late_minutes' => 'Late (minutes)', 'item' => 'Item', 'basis' => 'Basis', 'amount' => 'Amount (Rs)',
        'earnings' => 'Earnings', 'deductions' => 'Deductions', 'employer' => 'Employer contributions (not deducted)', 'employer_cost' => 'Employer cost', 'gross' => 'Gross pay',
        'total_deductions' => 'Total deductions', 'net_pay' => 'Net pay', 'amount_in_words' => 'Amount in words', 'ytd' => 'Tax year to date',
        'ytd_gross' => 'Gross to date', 'ytd_tax' => 'Tax deducted to date', 'ytd_net' => 'Net paid to date', 'leave_balance' => 'Leave balance',
        'leave_type' => 'Leave', 'balance' => 'Balance', 'loan_balance' => 'Advances and loans', 'attendance_calendar' => 'Attendance',
        'recovery_schedule' => 'Recovery schedule', 'planned' => 'Planned', 'recovered' => 'Recovered', 'balance_after' => 'Balance after',
        'kind' => 'Kind', 'reason' => 'Reason', 'instalment' => 'Monthly recovery', 'first_recovery' => 'First recovery', 'paid_from' => 'Paid from',
        'mode' => 'Mode', 'reference' => 'Reference', 'status' => 'Status', 'voucher' => 'Voucher', 'payslip' => 'Payslip', 'sr' => 'Sr',
        'employees' => 'Employees', 'total' => 'Total', 'units' => 'Units', 'rate' => 'Rate', 'quantity' => 'Quantity', 'note' => 'Note',
        'acknowledged' => 'Acknowledged by', 'revision' => 'Revision', 'supersedes' => 'Replaces', 'issued' => 'Issued',
        'prepared_by' => 'Prepared by', 'approved_by' => 'Approved by', 'received_by' => 'Received by (employee)', 'employee_signature' => 'Employee signature',
        'hr_manager' => 'HR manager', 'accountant' => 'Accountant', 'authorised' => 'Authorised signatory', 'monthly_gross' => 'Monthly gross salary',
        'contractor' => 'Contractor', 'workers' => 'Workers', 'man_days' => 'Man-days', 'sales_tax' => 'Sales tax on services', 'wht' => 'Income tax withheld',
        'payable' => 'Net payable', 'unit' => 'Unit', 'ntn' => 'NTN', 'category' => 'Category', 'receipts' => 'Receipts', 'description' => 'Description', 'claimed' => 'Claimed', 'approved' => 'Approved',
    ],
    'values' => [
        'monthly' => 'Monthly salary', 'daily' => 'Daily wage', 'piece_rate' => 'Piece rate', 'cash' => 'Cash', 'cheque' => 'Cheque', 'bank' => 'Bank transfer',
        'advance' => 'Salary advance', 'loan' => 'Staff loan', 'posted' => 'Posted', 'paid' => 'Paid', 'part_paid' => 'Partly paid', 'unpaid' => 'Unpaid',
        'signature' => 'Signature', 'thumb' => 'Thumb impression',
    ],
    'heads' => [],
    'text' => [
        'salary_certificate' => 'This is to certify that :name, holder of CNIC :cnic, has been employed with :mill as :designation in the :department department since :joining. Their current monthly gross salary is :gross (:words).',
        'employment_letter' => 'This is to certify that :name, holder of CNIC :cnic, has been employed with :mill as :designation in the :department department since :joining, and is in service on the date of this letter.',
        'certificate_purpose' => 'Issued on request for :purpose, without any liability on the part of the mill.',
        'receipt' => 'Received :amount (:words) as salary for :month.',
        'computer_generated' => 'Computer-generated document; check it at the verification page with the code below.',
    ],
    'watermark' => ['draft' => 'DRAFT', 'duplicate' => 'DUPLICATE'],
];
