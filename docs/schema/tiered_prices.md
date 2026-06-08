# Tiered Prices (`tiered_prices`)

Imports advanced (tiered) prices from a CSV file via FireGento FastSimpleImport (the
`advanced_pricing` import entity).

## Source format

```csv
sku,tier_price_website,tier_price_customer_group,tier_price_qty,tier_price,tier_price_value_type
SKU1,All Websites [GBP],ALL GROUPS,10,0.85,Fixed
SKU1,All Websites [GBP],ALL GROUPS,50,0.79,Fixed
SKU2,All Websites [GBP],ALL GROUPS,24,2.99,Fixed
```

The first row is the header row; the column names map directly to the fields expected
by Magento's `advanced_pricing` importer. The `sku` column is required to identify the
product.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `sku` | yes | string | Product SKU the tier price applies to. The component locates this column by header name; rows whose column count does not match the header are skipped. |
| `tier_price_website` | yes | string | Website scope label, e.g. `All Websites [GBP]`. |
| `tier_price_customer_group` | yes | string | Customer group label, e.g. `ALL GROUPS`. |
| `tier_price_qty` | yes | number | Quantity threshold for the tier. |
| `tier_price` | yes | number | The tier price value. |
| `tier_price_value_type` | yes | string | `Fixed` or `Discount` (as accepted by the advanced-pricing importer). |

Column names are passed straight to the `advanced_pricing` importer, so any column that
entity accepts is valid; the table lists those in the sample. Multi-value fields use
`;` as the separator.

## Behaviour

- **Header-driven.** The first CSV row defines the attribute columns and the position
  of the `sku` column. If the file has no data the component records `The row data is
  not valid.` and stops.
- **Row validation / skipping.** Any row whose column count does not match the header
  row is skipped; the skipped SKUs are logged and recorded as "skipped".
- **Import entity.** Valid rows are imported through the `advanced_pricing` entity with
  the multiple-value separator set to `;`.
- **Dry-run.** Logs `[dry-run] Would import N rows`, records the valid rows as
  "created", and performs no import (skipped rows are still counted/logged).
- **Error handling.** Any exception from the importer is caught, logged and recorded as
  an error.

## Notes / v2 changes

- v2 wires the importer through `FireGento\FastSimpleImport\Model\ImporterFactory` and
  adds **dry-run** support (rows are counted but not imported). The CSV contract above
  is unchanged from v1.
