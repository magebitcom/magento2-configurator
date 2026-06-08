# Product Links (`product_links`)

Sets related / up-sell / cross-sell links between existing products.

## Source format

Each source file is keyed by a single **link type** node. The three sample files are usually
listed together under the `product_links` alias in `master.yaml`.

`related.yaml`:

```yaml
relation:
  simple-product:
    - configurable_product_1
    - configurable_product_2
```

`up-sells.yaml`:

```yaml
up_sell:
  configurable_product_1:
    - simple-product
    - configurable_product_2
```

`cross-sells.yaml`:

```yaml
cross_sell:
  configurable_product_2:
    - simple-product
    - configurable_product_1
```

Under the link-type node, each key is the **owner product SKU** and its value is the ordered
**list of SKUs to link** to it.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| *(top level)* | yes | string key | One of `relation`, `up_sell`, `cross_sell`. Any other key throws `Link type <x> is not supported` and aborts that file. Mapped internally to Magento link types `related` / `upsell` / `crosssell`. |
| `<link_type>.<sku>` | yes | string key | SKU of the product that owns the links. Must already exist, otherwise `SKU (<sku>) for products to link to is not found` is logged and that owner is skipped. |
| `<link_type>.<sku>[]` | yes | list of string | Ordered SKUs to link. Each must exist, otherwise `SKU (<x>) to link does not exist` is logged. List order sets link position (index × 10: 0, 10, 20, …). |

## Behaviour

- **Replace, not merge.** All links of that type for the owner SKU are rebuilt from the YAML
  list and saved via `productRepository->get(sku)->setProductLinks(...)->save()`. Counted as
  *updated*. A SKU not present in the file is left untouched.
- **Both ends must exist.** The owner SKU and every linked SKU are validated through the
  product repository before saving; a missing product is logged and aborts that owner's
  links (other owners continue).
- **Dry-run.** Logs the linked SKUs, then `[dry-run] Would save product links for <sku>` and
  records the update without saving.
- **Error cases.** Unsupported link type → error added and file aborts. Missing owner /
  linked SKU → logged and skipped. Other exceptions are caught and logged so one bad entry
  does not stop the rest.

## Notes / v2 changes

- One file holds exactly one link type, but several files (related + up-sells + cross-sells)
  are typically combined under the single `product_links` alias.
- Link `position` is derived purely from list order (multiplied by 10); it cannot be set
  explicitly per entry.
- This component only ever *adds/replaces* links for the listed SKUs — it does not remove
  links from products that are absent from the file.
