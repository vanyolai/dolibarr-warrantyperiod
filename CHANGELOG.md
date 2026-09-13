# Changelog

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
