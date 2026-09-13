# Changelog

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
