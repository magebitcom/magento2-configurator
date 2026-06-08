# Magento 2 Configurator — Magebit v2

Keep Magento persistently configured from version-controlled files. Define your
store configuration (websites, attributes, categories, CMS, tax, customers, …)
as YAML/CSV, commit it, and apply it to any environment with a single command.

Originally created by [CTI Digital]; this is Magebit's **v2** line — a
modernised, self-owned fork under the `Magebit\Configurator` namespace
(`Magebit_Configurator`).

## Why

- Stand up a store from scratch with its important DB-backed configuration ready.
- Share and version configuration alongside your code.
- Apply environment-specific configuration (local / stage / live).
- Re-run safely: components are idempotent and a `--dry-run` previews changes.

## Requirements

- **Magento Open Source / Adobe Commerce 2.4.7 – 2.4.9** (all currently non-EOL)
- **PHP 8.1 – 8.5** (the union supported across those Magento versions)
- `firegento/fastsimpleimport ^2.0`

## What's new in v2

- **`Magebit\Configurator` namespace** / `Magebit_Configurator` module.
- **Typed component contract** — `execute(ComponentContext): ComponentResult`
  (replacing the untyped `execute($data)`); `strict_types`, promoted `readonly`
  constructors throughout.
- **`--dry-run`** — preview what each component would create/update/skip without
  persisting anything (raw SQL, imports and saves are all guarded).
- **Meaningful exit codes** — `configurator:run` returns non-zero when any
  component reports an error, and prints a run summary
  (`created N, updated N, skipped N, errors N`). A single failing component no
  longer aborts the whole run.
- **Documented config contract** — every component's source format is in
  [`docs/schema/`](docs/schema/README.md).

## Getting started

1. Create `app/etc/master.yaml` (see [`Samples/master.yaml`](Samples/master.yaml)).
   Source paths are resolved relative to the Magento base dir, e.g.
   `app/etc/configurator/Attributes/attributes.yaml`.
2. Enable the modules: `bin/magento module:enable Magebit_Configurator FireGento_FastSimpleImport`
   then `bin/magento setup:upgrade`.
3. Apply: `bin/magento configurator:run --env="<environment>"`

### Usage

```bash
bin/magento configurator:list                                   # list components
bin/magento configurator:run --env="local"                      # run all
bin/magento configurator:run --env="local" --component="config" # run one (repeatable)
bin/magento configurator:run --env="local" --dry-run            # preview, no writes
bin/magento configurator:run --env="local" -i                   # ignore missing source files
bin/magento configurator:run --env="local" -v                   # verbose logging
```

## Configuration reference

See [`docs/schema/`](docs/schema/README.md) for the source format of every
component (fields, behaviour, create/maintain semantics). This is the stable
public contract for the v2 line.

## Testing

- **Unit tests** (`phpunit.xml.dist`) — run from within a Magento install:
  ```bash
  ../../../vendor/bin/phpunit -c phpunit.xml.dist
  ```
- **Conformance harness** ([`Test/Conformance/`](Test/Conformance/README.md)) —
  runs every component through the shipped `Samples/` in `--dry-run` against a
  real Magento and fails on any component crash. This is the primary integration
  check.
- **Static analysis** — `phpcs` (Magento2 standard, see `phpcs.xml.dist`) + `phpmd`.

## Components

All components are implemented and execute-verified on Magento 2.4.7. Each links
to its schema page.

| Component | Alias | Notes |
|-----------|-------|-------|
| Websites / Stores / Store Views | `websites` | |
| Configuration | `config` | create/maintain |
| Sequence | `sequence` | |
| Attributes | `attributes` | create/maintain, swatches |
| Attribute Sets | `attribute_sets` | |
| Categories | `categories` | create/maintain |
| Products | `products` | FastSimpleImport (configurable products need their simple products to exist) |
| Blocks | `blocks` | create/maintain, phtml templates, versioning |
| Pages | `pages` | create/maintain, versioning |
| API Integrations | `apiintegrations` | |
| Tax Rates | `taxrates` | |
| Tax Rules | `taxrules` | |
| Widgets | `widgets` | |
| Customer Groups | `customergroups` | |
| Admin Roles / Users | `adminroles` / `adminusers` | |
| Media | `media` | |
| Rewrites | `rewrites` | |
| Review Ratings | `review_rating` | |
| Product Links | `product_links` | related / up-sell / cross-sell |
| Customer Attributes | `customer_attributes` | |
| Customers | `customers` | FastSimpleImport |
| SQL | `sql` | raw SQL files |
| Catalog Price Rules | `catalog_price_rules` | |
| Shipping Table Rates | `shippingtablerates` | |
| Order Statuses | `order_statuses` | |
| Tiered Prices | `tiered_prices` | FastSimpleImport |

## Background

Lightning talk by [Raj Chevli] at Mage Titans Manchester on [YouTube].

## License

MIT — see [`LICENSE`](LICENSE). Copyright © 2016 CTI Digital; portions
copyright © 2026 Magebit, Ltd.

[CTI Digital]:http://www.ctidigital.com/
[YouTube]:https://www.youtube.com/watch?v=iFkhAzJl2k0
[Raj Chevli]:https://twitter.com/chevli
