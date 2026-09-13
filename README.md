# Dolibarr WarrantyPeriod

WarrantyPeriod is a Dolibarr external module that calculates warranty expiration **per shipment line** from warranty duration stored on the product.

## Compatibility

- Dolibarr 23.x
- PHP 8.1+
- MariaDB / MySQL and PostgreSQL-compatible Dolibarr database access

Current version: **0.2.0**

## How it works

A Product extra field stores the warranty duration in **months**. For every Shipment line the module calculates:

```text
shipment date + product warranty months = line warranty expiration
```

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

The warranty start is currently fixed to the Shipment date.

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

If a change is made in the consumer subtree first, export it before continuing normal development:

```bash
git subtree split --prefix=htdocs/custom/warrantyperiod -b warrantyperiod-export
git push warrantyperiod warrantyperiod-export:main
```

## Scope of 0.2.0

Warranty expiration is tracked per Shipment line. Lot/serial-number-specific warranty dates are not yet stored separately; all serials or batches belonging to the same Shipment line share the line's calculated expiration.

## Development checks

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/date_math_smoke.php
```

## License

GPL-3.0-or-later.
