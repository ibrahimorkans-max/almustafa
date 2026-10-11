# Pakistan localisation and Al Mustafa Rice Mills branding — summary

Plan: [pakistan-localisation-plan.md](pakistan-localisation-plan.md). Branch `pakistan-localisation`.

## Built
| Area | What changed |
|---|---|
| Branding | The two supplied logos are prepared as web and print assets in `app/public/images/brand/`: transparent background, a dark-mode card, flattened print copies, favicon and app icons (192 / 512) from the wheat emblem. The admin panel, sign-in, verify page, maintenance and no-access pages, and the PWA (name, colours, icons) all carry **Al Mustafa Rice Mills**, deep green `#0B4F2C` and gold `#D99A0E`. Address and phone (Adda Permat, 1 km Shujabad Road, Lodhran · 0300-6834588) come from the mill entity, with `config/ricemill.php → brand` as fall-back |
| Printed documents | **mPDF** replaces dompdf, so Urdu letters join correctly (Lateef font). Every document shares one letterhead: logo, name in English and Urdu, address, phone, NTN / STRN, and a green title bar in English + Urdu. The footer carries printed-by / time / page numbers; QR verification and signature boxes follow the content. Documents:<br>• weighment slip (کانٹا پرچی, A5): weighments, gross / tare / net, kg **and maund**, lab result, deduction, payable weight, rate cut, flags<br>• sales tax invoice / invoice (A4): seller and buyer NTN / STRN, HS code, bags, kg, maund, rate per maund, value, sales tax, further tax, total, amount in words, terms<br>• gate pass (گیٹ پاس, A5)<br>• account statement (کھاتہ, A4)<br>• **new** paddy purchase bill (A4): CNIC / NTN, slip, deduction, maund, rate cut, withholding, net payable<br>• **new** payment voucher (ادائیگی واؤچر, A5): mode, IBAN ending, transaction ID, bills paid<br>All six can be checked at `/verify` (QR on each) |
| Time and year | Asia/Karachi everywhere; screens show PKT by default (previously some columns showed UTC). Document numbers follow the **July–June** tax year (`AMR/INV/2026-27/0001`) |
| Money | Rs with thousands grouping (`Rs 1,234,567.89`); amounts in words in millions ("Rupees One Million … Only") |
| Trade unit | Rates are **per maund (40 kg)** everywhere: saudas, quotes, requirements, price lists, rate cuts, brokerage, bills, margins. Stock and dashboards show maund. Weights are still stored in kg |
| Identity | `PakistanIds` validates and formats:<br>• CNIC (13 digits, region 1–7) and NTN (1234567-8, or the CNIC)<br>• STRN (13 digits) and IBAN (PK, mod-97 check digits)<br>• mobiles (0300-1234567 → +923001234567) and vehicle plates (MNA-21-1234)<br>Mill: NTN, STRN, province, phone, postal code. Parties: NTN, CNIC, STRN, **FBR Active Taxpayer List status** (filer / non-filer / not checked), withholding exemption. Bank accounts are IBANs (bank code from the IBAN), with an optional Raast ID. Payment modes: IBFT, RTGS (PRISM), Raast, cheque, pay order, cash |
| Sales tax | Invoices are **Sales Tax Invoices** only when the mill's STRN is filled in; otherwise they are plain invoices with no tax lines. The product's rate applies (a rate can be marked exempt). **Further tax** (default 4 %, setting) is charged on taxable supplies to a buyer without an STRN. Postings go to Output sales tax and Further sales tax. Credit notes reverse the tax in the invoice's proportion. The e-invoice, e-way bill and IRN steps are removed; the gate pass needs only a posted invoice |
| Tax registers | **Accounts → Tax registers** shows the month's Annex-C (sales and credit notes) with totals, and income tax withheld u/s 153. Both download as CSV in the IRIS column order and the downloads are audited |
| Withholding u/s 153 | Replaces TDS 194Q. When enabled, tax is withheld from paddy bills above the yearly threshold. The rate is the filer or non-filer rate according to the supplier's ATL status on the day of posting, which is recorded on the bill. Exempt suppliers (e.g. growers) are skipped |
| Cash limit | s. 21(l): cash to one person in a day is refused above Rs 250,000 (setting) |
| HR | Aadhaar → CNIC (masked `XXXXX-XXXX567-1`), PAN → NTN, salary account → IBAN (needs payroll approval as before). Minimum age 15 (Punjab Restriction on Employment of Children Act 2016). Pay heads include EOBI, medical allowance and income tax on salary. Leave defaults: casual 10, sick 16, annual 14 (carry 28). Pakistan's fixed public holidays are pre-filled for this year and next; HR adds Eid and Ashura each year |
| Demo data | The dev database was rebuilt: one mill **AMR — Al Mustafa Rice Mills, Lodhran**, with logins `amr.<role>` in `storage/app/private/demo-credentials.txt`. It holds Pakistani paddy and rice products, parties (grower, arhti, Karachi exporter, Multan shop, broker, transporter), MNA plates, a Meezan IBAN, the mill attendance site and three employees. NTN and STRN are left empty for the owner to fill in |

## Tested
* **New tests:**
  * Pakistani IDs: CNIC, NTN, STRN, IBAN check digits, plates, mobiles.
  * Maund conversion and the maund-and-kg display; the July tax year; Rs formatting and amounts in words.
  * Sales tax for registered buyers, further tax for unregistered buyers, commercial invoice without an STRN, exempt goods, gate pass without an e-invoice.
  * Annex-C register net of credit notes; withholding at non-filer and filer rates, exempt and disabled.
  * Every printed document checked for its required fields, plus the `/verify` check for invoices, purchase bills and payment vouchers.
* **Updated tests:** existing tests now use maund rates (scaled so amounts are unchanged), IBANs, CNIC / NTN, Pakistani plates, Karachi / Multan buyers, and the tax-register screen.
* **Full suites:** see the final report. Pint, Larastan, PWA tests (17) and agent tests (33) are clean.

## Needs the owner or the tax adviser
* Enter the mill's **NTN and STRN** under Entities. Until the STRN is entered, invoices print as plain invoices without sales tax.
* Confirm these against the current Finance Act and FBR circulars:
  * the sales-tax rate for each product (the demo marks rice and paddy exempt and packed / branded goods at 18 %) and the PCT codes;
  * the further-tax rate;
  * the withholding rates and threshold, and whether the mill is a withholding agent (`RICEMILL_WHT_ENABLED`).
* Confirm the leave quotas, and the sick-leave pay rule (the Factories Act allows half pay).
* FBR **Digital Invoicing** (POS / PRAL) integration is not built yet; it needs the mill's credentials.
