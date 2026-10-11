# Pakistan localisation and Al Mustafa Rice Mills branding — plan

Branch `pakistan-localisation` (from `hr-phase-4`). The system is still on demo data only, so the original migrations are changed in place and the demo data is rebuilt; no conversion of live records is needed.

## Decisions (user, 2026-10-09)
* Replace the Indian rules with Pakistani ones (no country switch).
* Sales-tax registration is a **setting**: when the mill's STRN is filled in, sales invoices print as **Sales Tax Invoice** with tax lines; otherwise they print as **Invoice** with the NTN. FBR Digital Invoicing (PRAL) integration is left for later, once credentials exist.
* Printed documents carry **English with Urdu headings**.
* Demo data is rebuilt for a single mill.

## Business identity
**Al Mustafa Rice Mills**, Adda Permat, 1 km Shujabad Road, Lodhran, Punjab. Phone 0300-6834588. Entity code `AMR`, so numbers look like `AMR-EMP-0001`. Two logos: the round mark for the panel, favicon and app icon, and the wide one for letterheads.

## What changes
| Area | India (before) | Pakistan (after) |
|---|---|---|
| Time | Asia/Kolkata, "IST" (UTC+5:30) | Asia/Karachi, "PKT" (UTC+5) |
| Financial year | April – March | July – June (`2026-27` starts 1 Jul 2026) |
| Currency | ₹, Indian grouping 5,00,000 | Rs, grouping 500,000; amounts in words "Rupees … Only" (million / thousand) |
| Trade unit | Quintal (100 kg), rates per quintal | **Maund (40 kg)**, rates per maund; quintal / ton remain as display units |
| Mill identity | GSTIN, state code | NTN, STRN (optional), province |
| Party | GSTIN, PAN, TDS-194Q exemption | NTN, CNIC, STRN, filer status (Active Taxpayer List), withholding exemption |
| Product | HSN, GST rate | PCT code, sales-tax rate (0 % / exempt allowed) |
| Sales invoice | CGST / SGST / IGST, place of supply, IRN QR, e-way bill | Sales tax and further tax (unregistered buyer); IRN / e-way removed |
| Returns | GSTR-1 / GSTR-3B style | Monthly sales-tax registers (sales and purchases) for the IRIS return, as CSV |
| Purchases | TDS s. 194Q | Withholding income tax on supplies (s. 153), with filer and non-filer rates; growers can be exempt |
| Cash limit | s. 40A(3) ₹10,000 per day | s. 21(l) ITO 2001: cash payments above Rs 250,000 are refused |
| People | Aadhaar, PAN, IFSC | CNIC (13 digits), NTN, IBAN (PK, mod-97 check) |
| Phones, vehicles | +91, HR05AB1234 | +92 3XX XXXXXXX; Pakistani registration numbers (e.g. LES-21-1234, MNA-1234) |
| Leave defaults | CL 7, SL 7, EL 12 | Factories Act 1934 / Punjab Shops & Establishments: casual 10, sick 16, annual 14 (carry up to 28) — to be confirmed by the owner |
| Holidays | — | Pakistan national days pre-filled; Eid / Ashura dates entered each year (lunar) |

All rates and thresholds are settings with conservative defaults. **They must be checked by the mill's tax adviser**: withholding rates and further-tax rates change with each Finance Act.

## Printed documents
* A shared letterhead: logo, name, address, phone, NTN / STRN, and a document title in English and Urdu.
* A shared footer: verification code with QR, printed by / at, and signature boxes.
* PDF engine: **mPDF** instead of dompdf, because dompdf cannot join Urdu letters. Every document uses it.
* Documents:
  * weighment slip (کانٹا پرچی);
  * gate pass (گیٹ پاس);
  * sales tax invoice / invoice (سیلز ٹیکس انوائس / انوائس);
  * ledger statement (کھاتہ);
  * new: paddy purchase bill (خریداری بل);
  * new: payment voucher (ادائیگی واؤچر).

## Tests
Both databases. New tests cover:
* CNIC, NTN, IBAN, mobile and vehicle-number checks;
* the July financial year, PKT times, maund arithmetic;
* sales tax registered vs unregistered, further tax, and withholding with filer / non-filer status;
* every PDF renders with the branding and its required fields.
