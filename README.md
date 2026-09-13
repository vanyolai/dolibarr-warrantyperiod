# Dolibarr WarrantyPeriod

WarrantyPeriod is a Dolibarr external module that calculates warranty expiration **per shipment line** from warranty duration stored on the product.

## Compatibility

- Dolibarr 23.x
- PHP 8.1+
- MariaDB / MySQL and PostgreSQL-compatible Dolibarr database access

Current version: **0.3.0**

## How it works

A Product extra field stores the warranty duration in **months**. For every Shipment line the module calculates:

```text
warranty start + product warranty months = line warranty expiration
```

The warranty start date is resolved in this order:

1. actual Shipment sending date (`date_expedition` / `date_shipping`);
2. planned Shipment date (`date_delivery`) when the actual sending date is still empty.

This is important when a Shipment is opened from an Order: Dolibarr normally propagates the planned date but leaves the actual sending date empty. Once an actual sending date is entered later, it supersedes the planned date and WarrantyPeriod recalculates the line expiration dates.

Both participating extra fields are selected in the module setup:

- **source:** integer Product extra field, for example `warrantymonths`;
- **target:** date **Shipment-line** extra field (`expeditiondet`), for example `warrantyexpire`.

This allows one shipment to contain products with different warranty periods without ambiguity. A 12-month and a 36-month product simply receive different expiration dates on their respective shipment lines.

If no suitable target field exists, the setup page can create a module-managed `warranty_expiration` date field on Shipment lines. Blank and zero product warranty values leave that line's expiration blank.

### Calendar-month calculation

Month addition clamps to the last valid day of the target month:

```text
2024-01-31 + 1 month   = 2024-02-29
2025-01-31 + 1 month   = 2025-02-28
2024-02-29 + 12 months = 2025-02-28
```

## Nautilus shipment PDF

Version 0.3.0 adds **Nautilus**, a Digital Nautics shipment document model based on Dolibarr's Espadon model.

Nautilus intentionally keeps Espadon's mature pagination, addresses, linked-order information, totals, footer, product images, notes and printable line extra fields, while cleaning up the line table for our shipment workflow:

- the line-number column is hidden;
- weight/volume is shown only if at least one physical product actually has weight or volume data;
- ordered quantity, unit and shipped quantity use narrower columns so the product description has more room;
- the configured WarrantyPeriod target field is kept as a dedicated right-hand column;
- lot/serial information is reduced to the lot/serial number by default;
- the generic Espadon sell-by/eat-by values are not repeated in the product description, avoiding confusion with the real warranty-expiration field;
- `Quantity: 1` is omitted for ordinary serial-number rows and for a single lot, but per-lot quantities remain visible when a shipment line is genuinely split across several lots with meaningful quantities.

The model file lives at:

```text
core/modules/expedition/doc/pdf_nautilus.modules.php
```

After updating the module, enable/select **Nautilus** under the Shipping module's document-template settings and regenerate the shipment PDF.

## Triggers

All shipment lines are recalculated on:

```text
SHIPPING_CREATE
SHIPPING_MODIFY
SHIPPING_VALIDATE
```

An individual line is recalculated immediately on:

```text
LINESHIPPING_INSERT
LINESHIPPING_MODIFY
```

The implementation writes the line extrafield without opening its own database transaction because Dolibarr invokes these triggers from transactions owned by the Shipment / Shipment-line objects.

The `expeditioncard` hook also pre-fills the calculated warranty date on the Shipment creation form before the Shipment-line records exist.

## Installation

The repository root is the Dolibarr module root. For a standalone Dolibarr installation:

```bash
cd /path/to/dolibarr/htdocs/custom
git clone https://github.com/vanyolai/dolibarr-warrantyperiod.git warrantyperiod
```

Enable **Warranty period** under **Home → Setup → Modules/Applications**, then open the module configuration page.

## Configuration

1. Create or choose an integer Product extra field containing warranty months.
2. Under the Shipping module's **Extra fields (line)** / **Kiegészítő tulajdonságok (tétel)** page, create or choose a date field for the expiration.
3. In WarrantyPeriod setup select the Product field and the Shipment-line target field.
4. Save.

## Repository and subtree development model

This repository is the **canonical development source**.

It is consumed by `vanyolai/dolibarr` as a squash-merged Git subtree:

```text
repository: vanyolai/dolibarr-warrantyperiod
branch:     main
consumer:   vanyolai/dolibarr
branch:     23.0
prefix:     htdocs/custom/warrantyperiod
```

Initial integration:

```bash
cd /path/to/dolibarr
git remote add warrantyperiod https://github.com/vanyolai/dolibarr-warrantyperiod.git
git fetch warrantyperiod

git subtree add \
  --prefix=htdocs/custom/warrantyperiod \
  warrantyperiod main \
  --squash
```

Later updates:

```bash
git fetch warrantyperiod
git subtree pull \
  --prefix=htdocs/custom/warrantyperiod \
  warrantyperiod main \
  --squash
```

Normal development direction is:

```text
dolibarr-warrantyperiod -> Dolibarr subtree
```
