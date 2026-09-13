# Dolibarr WarrantyPeriod

WarrantyPeriod is a Dolibarr external module that calculates a shipment's warranty-expiration date from warranty duration stored on the shipped products.

## Compatibility

- Dolibarr 23.x
- PHP 8.1+
- MariaDB / MySQL and PostgreSQL-compatible Dolibarr database access

Current version: **0.1.0**

## How it works

A Product extra field stores the warranty duration in **months**. The module reads the actual product lines of a Shipment and calculates:

```text
shipment date + warranty months = warranty expiration
```

Both participating extra fields are selected in the module setup:

- **source:** integer Product extra field, for example `warrantymonths`;
- **target:** date Shipment extra field, for example an existing warranty-expiration field.

If no suitable target field exists, the setup page can create a module-managed `warranty_expiration` date field. It is shown on shipment forms and is marked printable so standard document models can include it as an extra field.

Blank and zero product warranty values are ignored.

### Mixed warranty periods

If one shipment contains, for example, 12-, 24-, and 36-month products, a single shipment-level expiration date is inherently ambiguous. The setup therefore provides three policies:

- **blank + warning** (default): do not claim a false common warranty date;
- **shortest:** calculate from the shortest positive warranty period;
- **longest:** calculate from the longest positive warranty period.

The default is deliberately conservative.

### Calendar-month calculation

Month addition clamps to the last valid day of the target month:

```text
2024-01-31 + 1 month  = 2024-02-29
2025-01-31 + 1 month  = 2025-02-28
2024-02-29 + 12 months = 2025-02-28
```

## Triggers

The expiration field is synchronized on:

```text
SHIPPING_CREATE
SHIPPING_MODIFY
SHIPPING_VALIDATE
```

The implementation writes the shipment extrafield without opening its own database transaction. This is important because `SHIPPING_CREATE` is executed inside Dolibarr's shipment-creation transaction.

## Installation

The repository root is the Dolibarr module root. For a standalone Dolibarr installation:

```bash
cd /path/to/dolibarr/htdocs/custom
git clone https://github.com/vanyolai/dolibarr-warrantyperiod.git warrantyperiod
```

Enable **Warranty period** under **Home → Setup → Modules/Applications**, then open the module configuration page.

## Configuration

1. Create or choose an integer Product extra field containing warranty months.
2. Choose an existing Shipment date extra field for the result, or use **Create default field**.
3. Choose the policy for shipments containing different warranty periods.
4. Save.

The warranty start is currently fixed to the Shipment date.

## Repository and subtree development model

This repository is the **canonical development source**.

It is intended to be consumed by `vanyolai/dolibarr` as a squash-merged Git subtree:

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

## Scope of 0.1.0

The module intentionally stores one expiration date at Shipment level. Per-line and per-serial-number warranty tracking is not part of 0.1.0; it is a natural later extension for mixed-warranty shipments and lot/serial-managed products.

## Development checks

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/date_math_smoke.php
```

## License

GPL-3.0-or-later.
