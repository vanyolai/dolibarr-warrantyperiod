# Changelog

## 0.2.2

Use the planned Shipment date when Dolibarr has not yet assigned an actual sending date.

- Shipment creation from an Order now pre-fills warranty expiration from the propagated planned date when the sending date is empty.
- Line and Shipment synchronization use the same fallback, so the calculated value is also persisted instead of being cleared by triggers.
- An actual sending date always takes precedence and causes the warranty expiration to be recalculated from that date.

## 0.2.1

Prefill calculated warranty expiration on the Shipment creation form.

- Added an `expeditioncard` hook so Shipment-line warranty dates are visible before the Shipment is created.
- The prefill uses the selected shipping date and each line product's configured warranty months.
- User-entered values are preserved when the creation form is redisplayed after validation errors.
- Persistence triggers remain authoritative after creation/line modification.

## 0.2.0

Correct the data model to store warranty expiration per Shipment line.

- Target extra field is now a date field on `expeditiondet` (Shipment line), not on the Shipment header.
- Different products on the same Shipment can have different warranty expiration dates naturally.
- Removed the mixed-warranty policy because it is no longer needed.
- Added recalculation on `LINESHIPPING_INSERT` and `LINESHIPPING_MODIFY`.
- Existing Shipment-line date fields such as `warrantyexpire` can be selected directly.
- Default `warranty_expiration` creation now creates a Shipment-line extra field.

## 0.1.0

Initial implementation.

- Configurable integer Product extra field containing warranty duration in months.
- Configurable Shipment date extra field receiving warranty expiration.
- Optional one-click creation of a default `warranty_expiration` Shipment extra field.
- Warranty starts on the shipment date.
- Calendar-month arithmetic with end-of-month clamping.
- Configurable handling of mixed warranty periods: blank with warning, shortest, or longest.
- Automatic recalculation on shipment create, modify, and validate triggers.
- Hungarian and English translations.
