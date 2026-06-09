# Widgets (`widgets`)

Creates and updates CMS widget instances, matching on instance type + title.

## Source format

```yaml
-
  instance_type: Magento\Cms\Block\Widget\Page\Link
  title: Test Link
  theme: Magento/blank
  stores:
    - default
    - usa_en_us
  parameters:
    anchor_text: Anchor Test
    title: Anchor Title
    page_id: 4
-
  instance_type: Magento\Cms\Block\Widget\Block
  title: Test Block
  theme: Magento/blank
  stores:
    - default
  parameters:
    block_id: 13
```

The source is a top-level list of widget entries. Any key on an entry is written to
the widget instance as-is via `setData()`, except `stores`, `parameters` and `theme`,
which are transformed (see below). This means other native widget columns (e.g.
`sort_order`, `page_group`) can be supplied as additional keys.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `[]` | yes | list | One entry per widget instance. |
| `[].instance_type` | yes | string | Widget block class, e.g. `Magento\Cms\Block\Widget\Block`. Part of the match key. |
| `[].title` | yes | string | Widget title. Part of the match key — combined with `instance_type` it identifies an existing widget. |
| `[].theme` | yes | string | Theme **code** (e.g. `Magento/blank`); resolved to `theme_id`. Unknown theme code throws and aborts the run. |
| `[].stores` | no | list | Store-view **codes**; resolved to a comma-separated `store_ids`. Unknown store code throws and aborts the run. |
| `[].parameters` | no | map | Widget parameters; serialized into `widget_parameters`. See block identifier note below. |
| `[].parameters.block_identifier` | no | string | Convenience: a CMS block identifier that is resolved to its `block_id` (the `block_identifier` key is removed). Unknown identifier throws. |
| `[].page_groups` | no | list | Layout placement. Each item: `page_group` (e.g. `all_pages`, `pages`, `anchor_categories`, `all_products`), `block` (block reference, e.g. `content`), plus optional `layout_handle` (default `default`), `for` (`all`/`1`), `template`, `page_id`, `entities`. Transformed into Magento's native `page_groups` structure and persisted by the resource save. |
| `[].<other>` | no | mixed | Any other key is set directly on the widget instance (`setData`). |

## Behaviour

- **Create + update (per field).** A widget is matched by `instance_type` + `title`.
  If none is found a new instance is created (`recordCreated`); if found it is
  updated. Each incoming key is compared to the current value — if all values already
  match, nothing is saved and the widget is skipped (`recordSkipped`); otherwise the
  changed fields are set and the widget is saved (`recordUpdated`).
- **Field transforms.** `stores` -> `store_ids` (codes resolved to ids, comma-joined);
  `parameters` -> `widget_parameters` (serialized); `theme` -> `theme_id` (code
  resolved to id). A `parameters.block_identifier` is resolved to a `block_id`.
- **Save area emulation.** Saving runs inside frontend area emulation
  (`emulateAreaCode(AREA_FRONTEND, ...)`).
- **No data** (empty / non-array source) produces an error and the component returns.
- **Dry-run.** No widget is saved; the component logs
  `[dry-run] Would save Widget <title>` and records the result as created (new) or
  updated (existing). Theme/store/block lookups still run during dry-run.
- **Error handling.** Unknown theme code, unknown store code, or an unresolvable
  `block_identifier` raise a `ComponentException` that is logged and recorded on the
  result.

## Notes / v2 changes

- `parameters.block_identifier` lets you reference a CMS block by its identifier and
  have it resolved to the numeric `block_id` at run time, instead of hard-coding ids.
- Matching is done by iterating the loaded widget collection
  (`findWidgetByInstanceTypeAndTitle`); an alternative collection-filter lookup exists
  (`getWidgetByInstanceTypeAndTitle`) but is not currently used.
- Multiple same-titled widgets across stores are not yet disambiguated (no store
  filter on the match) — noted as a TODO in the component.
