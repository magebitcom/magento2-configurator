# Products (`products`)

Imports products from a CSV file using FireGento FastSimpleImport (which wraps
Magento's native CSV product import). One row per product; the first row is the
header that names the attribute for each column.

## Source format

The source is a **CSV file**, not YAML. The first line is a header row of attribute
codes; every following line is a product. List values within a single cell are
separated by `;` (semicolon — the importer's `MultipleValueSeparator`).

Simple products (`Samples/Components/Products/simple.csv`):

```csv
attribute_set_code,product_websites,product_type,sku,name,short_description,description,price,url_key,visibility,meta_title,meta_keywords,meta_description,color,image,small_image,thumbnail
Default,base,simple,simple-product,Simple Product,This is the short description for the simple product.,This is the longer description for the simple product.,9.99,simple-product,"catalog, search",Product 1,A few keywords,Product 1 description,,http://placehold.it/1000x1000,http://placehold.it/1000x1000,http://placehold.it/1000x1000
Default,base,simple,configurable_product_1,Configurable Product 1,...,...,9.99,,not visible individually,...,...,...,Red,http://placehold.it/1000x1000,http://placehold.it/1000x1000,http://placehold.it/1000x1000
```

Configurable products (`Samples/Components/Products/configurable.csv`):

```csv
attribute_set_code,product_websites,product_type,sku,name,short_description,description,price,url_key,visibility,meta_title,meta_keywords,meta_description,associated_products,configurable_attributes,image,small_image,thumbnail
"Default","base","configurable",configurable_product,"Configurable","Configurable short description","Configurable full description","9.99","configurable","catalog, search","Configurable Product Title","Configurable Product meta keywords","Configurable product description","configurable_product_1,configurable_product_2","color","http://placehold.it/1000x1000","http://placehold.it/1000x1000","http://placehold.it/1000x1000"
```

### Fields

Columns are the header row. There is no fixed schema — any column whose name is a
valid product attribute code is passed straight through to the importer. The columns
below have special handling or meaning in the component.

| Column | Required | Type | Notes |
|--------|----------|------|-------|
| `sku` | yes | string | Identifier used in logs and for skip/success tracking. A row whose column count does not match the header is skipped by this SKU. |
| `product_type` | yes | string | `simple`, `configurable`, etc. `configurable` triggers the variation-building logic below. |
| `attribute_set_code` | yes | string | Attribute set name (e.g. `Default`). |
| `product_websites` | recommended | list | Website codes (e.g. `base`). May be given comma-separated; commas are converted to the `;` separator. |
| `store_view_code` | no | string/list | Store view scope. Commas are converted to the `;` separator. |
| `price` | recommended | number | Product price. |
| `visibility` | no | string | e.g. `catalog, search`, `not visible individually`. |
| `description`, `short_description` | no | string | Newlines are converted to `<p>…</p>` paragraphs (only when no `<p>` tag is already present). |
| `image`, `small_image`, `thumbnail`, `media_image`, `additional_images` | no | string | Image columns. Values are run through the image handler (downloads/copies and rewrites the value); multiple images use `;`. |
| `qty` | no | number | Stock quantity. If `is_in_stock` is set without `qty`, `qty` defaults to `1`. |
| `is_in_stock` | no | int | `1`/`0`. If both `qty` and `is_in_stock` are absent, default stock is applied. |
| `msi_sources` | no | string | Multi-source-inventory source items: `source_code=qty[:status]` entries joined by `;` (e.g. `default=100;warehouse_b=50:0`); status `1`=in stock (default), `0`=out. Applied via the Inventory API after import for the imported SKUs; the column is stripped before FastSimpleImport. |
| `associated_products` | configurable only | list | Comma-separated child SKUs. Used to build `configurable_variations`; dropped from the final row. |
| `configurable_attributes` | configurable only | list | Comma-separated attribute codes that vary across the children (e.g. `color`). Used to build `configurable_variations`; dropped from the final row. |
| `color` (and other attribute columns) | no | mixed | Any other header maps directly to that product attribute. Select/multiselect option labels are auto-created via the attribute-option handler. |

For configurable rows the component reads `associated_products` and
`configurable_attributes`, loads each child SKU, reads its attribute values, and
builds a `configurable_variations` string of the form
`sku=child1;color=Red|sku=child2;color=Black`. If a child is missing a required
attribute value it is left out; if the resulting variations string is empty the whole
configurable row is skipped.

## Behaviour

- **Bulk import via FastSimpleImport.** Rows are assembled into an array and handed to
  `FireGento\FastSimpleImport` (`processImport`) with `;` as the multiple-value
  separator. Create vs. update is decided by Magento's native importer (matched by
  `sku`), not by this component, so it is effectively upsert.
- **Validation pass.** Before importing, the rows are run through a validator; rows
  that fail validation are removed and the count is logged (`Removed N products after
  validation`).
- **Row skipping.** Rows whose column count differs from the header, and configurable
  rows that produce no variations, are skipped and reported in a "products were
  skipped as they do not have the required columns" log.
- **Mode.** This component does not branch on create/maintain mode itself; it always
  submits the assembled rows to the importer.
- **Dry-run.** Validates and logs `[dry-run] Would import N product rows via
  FastSimpleImport.` but does **not** call `processImport` — no products are written.
- **Error handling.** Empty input (`data[0]` missing) records an error and returns.
  Exceptions thrown by the importer are caught and logged; the importer's own log
  trace and error messages are written to the log afterwards.

## Notes / v2 changes

- Source is CSV; in `master.yaml` the `products` node points at one or more `.csv`
  files under `sources:` (see the bundled `simple.csv` + `configurable.csv`).
- The multiple-value separator is `;`. Columns such as `product_websites` and
  `store_view_code` accept commas as a convenience; they are rewritten to `;` before
  import.
- Dry-run support (`[dry-run]` log line, import skipped) is a v2 addition; the CSV
  column contract is unchanged from v1.
