# Configurator schema reference

This directory documents the **public configuration contract** for each component —
the shape of the YAML/CSV that the configurator consumes. These schemas are the
stability guarantee of the v2 line: implementations may be rewritten, but a
component's documented input must keep working unless a major version says otherwise.

Each component is wired into `master.yaml`, which is the table of contents:

```yaml
<alias>:
  enabled: 1                 # 0 disables the component for this run
  method: code               # how the source is provided (e.g. code = file in the repo)
  sources:
    - app/etc/configurator/<Component>/<file>.yaml
  env:                       # optional, per-environment overrides
    <environment>:
      mode: create | maintain
      sources:
        - ...
```

> **Source paths** are resolved relative to the Magento base directory (`BP`),
> so a source is written as e.g. `app/etc/configurator/Attributes/attributes.yaml`.
> (`master.yaml` itself is read from `app/etc/master.yaml`.)

The per-component pages below document the structure of the referenced source files,
in `master.yaml` order.

| Component | Alias | Source | Page |
|-----------|-------|--------|------|
| Websites / Stores / Store Views | `websites` | YAML | [websites.md](websites.md) |
| Configuration | `config` | YAML | [config.md](config.md) |
| Sequence | `sequence` | YAML | [sequence.md](sequence.md) |
| Attributes | `attributes` | YAML | [attributes.md](attributes.md) |
| Attribute Sets | `attribute_sets` | YAML | [attribute_sets.md](attribute_sets.md) |
| Categories | `categories` | YAML | [categories.md](categories.md) |
| Products | `products` | CSV | [products.md](products.md) |
| Blocks | `blocks` | YAML | [blocks.md](blocks.md) |
| Pages | `pages` | YAML | [pages.md](pages.md) |
| API Integrations | `apiintegrations` | YAML | [apiintegrations.md](apiintegrations.md) |
| Tax Rates | `taxrates` | CSV | [taxrates.md](taxrates.md) |
| Tax Rules | `taxrules` | CSV | [taxrules.md](taxrules.md) |
| Widgets | `widgets` | YAML | [widgets.md](widgets.md) |
| Customer Groups | `customergroups` | YAML | [customergroups.md](customergroups.md) |
| Admin Roles | `adminroles` | YAML | [adminroles.md](adminroles.md) |
| Admin Users | `adminusers` | YAML | [adminusers.md](adminusers.md) |
| Media | `media` | YAML | [media.md](media.md) |
| Rewrites | `rewrites` | CSV | [rewrites.md](rewrites.md) |
| Review Ratings | `review_rating` | YAML | [review_rating.md](review_rating.md) |
| Product Links | `product_links` | YAML | [product_links.md](product_links.md) |
| Customer Attributes | `customer_attributes` | YAML | [customer_attributes.md](customer_attributes.md) |
| Customers | `customers` | CSV | [customers.md](customers.md) |
| SQL | `sql` | YAML (+ `.sql`) | [sql.md](sql.md) |
| Catalog Price Rules | `catalog_price_rules` | YAML | [catalog_price_rules.md](catalog_price_rules.md) |
| Shipping Table Rates | `shippingtablerates` | YAML | [shippingtablerates.md](shippingtablerates.md) |
| Order Statuses | `order_statuses` | YAML | [order_statuses.md](order_statuses.md) |
| Tiered Prices | `tiered_prices` | CSV | [tiered_prices.md](tiered_prices.md) |
| Inventory Sources | `inventory_sources` | YAML | [inventory_sources.md](inventory_sources.md) |
| Hyvä CMS Pages | `hyva_pages` | YAML | [hyva_pages.md](hyva_pages.md) |
| Hyvä CMS Blocks | `hyva_blocks` | YAML | [hyva_blocks.md](hyva_blocks.md) |
