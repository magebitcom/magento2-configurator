# Tax Rates (`taxrates`)

Imports tax rates from a CSV file via Magento's native tax-rate CSV import handler.

## Source format

```csv
code,tax_country_id,tax_region_id,rate,tax_postcode,zip_is_range,zip_from,zip_to
"US-CA-*-Rate 1","US","CA","8.2500","*","","",""
"US-NY-*-Rate 1","US","NY","8.3750","*","","",""
```

The first row is a header of machine-name column keys. The component reads each
data row relative to those headers, so the columns may appear in **any order** in
your CSV — `getSortedData()` re-keys each row by header and re-emits it in the fixed
order Magento's `CsvImportHandler` requires (`code`, `tax_country_id`,
`tax_region_id`, `tax_postcode`, `rate`, `zip_is_range`, `zip_from`, `zip_to`).

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `code` | yes | string | Unique tax rate code/identifier. |
| `tax_country_id` | yes | string | Two-letter country code, e.g. `US`. |
| `tax_region_id` | yes | string | Region/state code or id, e.g. `CA`. |
| `rate` | yes | string | Percentage rate, e.g. `8.2500`. |
| `tax_postcode` | yes | string | Postcode pattern. `*` matches all postcodes. |
| `zip_is_range` | yes | string | `1` when the rate applies to a zip range, empty/`0` otherwise. |
| `zip_from` | yes | string | Range start; leave empty when `zip_is_range` is not set. |
| `zip_to` | yes | string | Range end; leave empty when `zip_is_range` is not set. |

All eight columns must be present in the header. The required-field validation and
duplicate handling is delegated to Magento's `CsvImportHandler`, not the component.

## Behaviour

- **Bulk CSV import.** The component reorders every row into Magento's required
  column order, writes a temporary CSV to the system temp directory, and hands it to
  `Magento\TaxImportExport\Model\Rate\CsvImportHandler::importFromCsvFile()`. The
  temporary file is deleted afterwards.
- **Create/update semantics are Magento's**, not the component's. The import handler
  matches on the rate code and inserts/updates accordingly; the configurator does not
  pre-check existence and cannot report per-row created/updated/skipped counts.
- **No row data** (empty file / missing header row at index `0`) produces an error
  and the component returns without importing.
- **Dry-run.** Both the temp-file write and the import are skipped; the component
  logs `[dry-run] Would import tax rates from a generated CSV file.` and makes no
  changes.
- **Error handling.** A `ComponentException` during sorting/import is logged and
  recorded on the result.

## Notes / v2 changes

- The component delegates entirely to Magento's tax-rate `CsvImportHandler`; it does
  not implement its own create/maintain logic, so there is no per-rate idempotency
  reporting and `mode` (create vs maintain) has no effect here.
- Because column order is normalised by `getSortedData()`, CSVs authored for v1 keep
  working regardless of how the columns are ordered, as long as all eight machine-name
  headers are present.
