# Inventory Sources (`inventory_sources`)

Creates and maintains the Multi-Source-Inventory (MSI) topology: **sources**,
**stocks**, their **source-stock links** and **sales-channel** (website)
assignments. Run it **before** the [`products`](products.md) component so that
component's `msi_sources` source items have sources/stocks to land in. MSI is
part of Magento Open Source 2.4 (no extra modules required).

## Source format

```yaml
sources:
  warehouse_b:
    name: "Warehouse B"
    enabled: true
    country_id: LV
    postcode: "1001"
    # any other inventory_source column may be supplied (region, city, …)
stocks:
  eu_stock:
    name: "EU Stock"
    sources: [default, warehouse_b]   # source-stock links, priority follows order
    sales_channels: [base]            # website codes assigned to the stock
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `sources.<code>` | no | map | Keyed by source code. Any key maps to an `inventory_source` column. |
| `sources.<code>.name` | yes* | string | Source name (required by Magento to create a source). |
| `sources.<code>.country_id` | yes* | string | ISO country code (required by Magento). |
| `sources.<code>.enabled` | no | bool | Defaults per Magento. |
| `stocks.<name>` | no | map | Keyed by stock name (the match key). |
| `stocks.<name>.sources` | no | list | Source codes linked to the stock; list order sets link priority. |
| `stocks.<name>.sales_channels` | no | list | Website **codes** assigned to the stock. |
| `*.version` | no | int | Per-entity version (see the README "Reconciliation" section). |

\* Magento validation requirements, not enforced by the component.

## Behaviour

- **Mode + versioning** via the shared gate, per source and per stock: create
  mode protects existing entities (unless `version` bumped); maintain reconciles.
- **Sources** are matched by `source_code` (`SourceRepositoryInterface`); **stocks**
  by `name`. Source-stock links are written via `StockSourceLinksSaveInterface`;
  sales channels via the stock's extension attributes.
- **Dry-run** logs `[dry-run] Would create/update MSI source/stock "<x>"` and writes nothing.
