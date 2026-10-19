# Development Guide — WarrantyPeriod

> Last reviewed: 2026-10-19

## Repository role

```text
canonical source: vanyolai/dolibarr-warrantyperiod
source branch:    main
consumer prefix:  htdocs/custom/warrantyperiod
subtree mode:     squashed
```

## Current implementation state

Current integrated module version: **0.3.1**.

The module calculates warranty expiration **per shipment line** from:
- a Product integer extra field containing warranty duration in months;
- shipment actual date, falling back to planned date;
- a Shipment-line date extra field as target.

The line-level model intentionally avoids ambiguous mixed-warranty header dates.

Implemented:
- Product warranty-month field selection;
- Shipment-line warranty-expiration field selection/creation;
- calendar-month addition with month-end clamping;
- recalculation on shipment and shipment-line events;
- creation-form prefill through `expeditioncard`;
- Nautilus shipment PDF model;
- defensive runtime external-model discovery fallback added in 0.3.1;
- HU/EN translations.

## Architectural rules

- Warranty duration belongs to Product configuration.
- Calculated expiration belongs to `expeditiondet` / Shipment line.
- Do not reintroduce a shipment-header mixed-warranty policy.
- Trigger writes must respect the transaction owned by Shipment/Shipment-line.
- Document-model discovery must use Dolibarr's supported model registration path.
- Nautilus should stay as close as practical to the core Espadon model so future upgrades remain reviewable.

## Roadmap

### Milestone 1 — 0.3.1 regression baseline
- verify model discovery on Shipment pages after module activation/update;
- verify line recalculation on create/modify/validate and line insert/modify;
- test planned-date -> actual-date replacement;
- verify month-end/leap-year calculations.

### Milestone 2 — Nautilus regression suite
- compare against current Dolibarr 23 Espadon behavior;
- verify page breaks, product images, notes, batch/serial rendering and target warranty column;
- ensure generic sell-by/eat-by output does not duplicate/confuse warranty expiration.

### Milestone 3 — upgrade resilience
- document any delta from the upstream Espadon base;
- re-check hooks/triggers/model discovery on every Dolibarr minor update;
- keep setup/migration idempotent.

## Subtree update

```bash
git subtree pull \
  --prefix=htdocs/custom/warrantyperiod \
  https://github.com/vanyolai/dolibarr-warrantyperiod.git \
  main \
  --squash \
  -m "Update WarrantyPeriod subtree"
```
