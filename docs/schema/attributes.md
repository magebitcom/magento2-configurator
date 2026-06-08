# Attributes (`attributes`)

Creates and maintains product (catalog) EAV attributes, including select options and
visual / text swatches.

## Source format

```yaml
attributes:
  test_attr:                         # attribute code (map key)
    global: 1
    label: Test Attribute
    type: text
    input: select
    visible_on_front: 1
    filterable: 1
    searchable: 1
    visible_in_advanced_search: 0
    product_types:
      - simple
    option:
      values:
        - Red
        - Green
        - Blue

  book_format:
    global: 1
    label: Book Format
    type: int
    input: select
    required: 0
    position: 10
    product_types:
      - simple
      - configurable
    option:
      values:
        - "Hardback"
        - "Paperback"

  colour_with_swatches:
    global: 1
    label: Colour
    type: int
    input: swatch_visual               # swatch_visual | swatch_text
    filterable: 1
    product_types:
      - simple
      - configurable
    option:
      values:                          # for swatches: label -> swatch value (hex)
        'Black': '#000000'
        'Blue': '#0000FF'
        'Red': '#FF0000'
```

### Fields

The attribute config is largely passed straight to `EavSetup::addAttribute`. The keys
below are the ones the component understands / remaps; any other key (e.g.
`used_in_product_listing`, `position`, `frontend_label`, `frontend_input`) is passed
through to Magento as-is.

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `attributes` | yes | map | Keyed by **attribute code**. Must be present and an array. |
| `attributes.<code>.label` | recommended | string | Maps to `frontend_label`. (You may instead set `frontend_label` directly.) |
| `attributes.<code>.type` | recommended | string | Maps to `backend_type` (`text`, `int`, `varchar`, …). |
| `attributes.<code>.input` | recommended | string | Maps to `frontend_input` (`select`, `text`, `price`, …). The special values `swatch_visual` / `swatch_text` are handled as swatches (see below). |
| `attributes.<code>.product_types` | no | list | List of product types; joined into `apply_to`. |
| `attributes.<code>.required` | no | `1`/`0` | Maps to `is_required`. |
| `attributes.<code>.source` | no | string | Maps to `source_model`. |
| `attributes.<code>.backend` | no | string | Maps to `backend_model`. |
| `attributes.<code>.frontend` | no | string | Maps to `frontend_model`. |
| `attributes.<code>.searchable` | no | `1`/`0` | Maps to `is_searchable`. |
| `attributes.<code>.global` | no | `1`/`0` | Maps to `is_global` (scope). |
| `attributes.<code>.filterable` | no | `1`/`0` | Maps to `is_filterable`. |
| `attributes.<code>.filterable_in_search` | no | `1`/`0` | Maps to `is_filterable_in_search`. |
| `attributes.<code>.unique` | no | `1`/`0` | Maps to `is_unique`. |
| `attributes.<code>.visible_in_advanced_search` | no | `1`/`0` | Maps to `is_visible_in_advanced_search`. |
| `attributes.<code>.comparable` | no | `1`/`0` | Maps to `is_comparable`. |
| `attributes.<code>.visible_on_front` | no | `1`/`0` | Maps to `is_visible_on_front`. |
| `attributes.<code>.user_defined` | no | `1`/`0` | Maps to `is_user_defined`. **Defaults to `1`** if omitted. |
| `attributes.<code>.default` | no | scalar | Maps to `default_value`. |
| `attributes.<code>.used_for_promo_rules` | no | `1`/`0` | Maps to `is_used_for_promo_rules`. |
| `attributes.<code>.wysiwyg_enabled` | no | `1`/`0` | Maps to `is_wysiwyg_enabled`. |
| `attributes.<code>.option.values` | no | list / map | **List** of option labels for plain selects. For swatches, a **map** of label → swatch value (hex colour for visual, or text). |
| `attributes.<code>.swatch` | no | map | Optional explicit swatch map (label → hex) used when an option value is not itself a valid `#RRGGBB` colour. |

### Fields excluded from update-diffing

`option` and `used_in_forms` are never compared when deciding whether an existing
attribute needs updating (they are handled separately / ignored).

## Behaviour

- **Create + maintain.**
  - New attribute → created via `EavSetup::addAttribute` and counted as created.
  - Existing attribute → each configured key is compared (after remapping) to the
    stored attribute. If anything differs, or new option values are detected, the
    attribute is re-added (update) and counted as updated. If nothing differs the
    attribute is skipped.
- **Options are additive.** For existing attributes, only option labels **not**
  already present are added; existing options are not removed (option removal is
  commented out in the code).
- **Swatches.** When `input` is `swatch_visual` or `swatch_text`, the attribute is
  created as a `select` and then converted to a swatch. Visual swatches use the hex
  values from `option.values` (or the `swatch` map / a literal `#RRGGBB` label);
  text swatches use the option labels as the swatch text.
- **Dry-run.** With `--dry-run` nothing is written; the component logs
  `[dry-run] Would add/update attribute …` and `[dry-run] Would convert attribute …
  to a … swatch.` and still records created/updated.
- **Per-entry resilience.** Option lookups catch `NoSuchEntityException` /
  `TypeError` / `BadMethodCallException` and log an error rather than aborting; a
  `ComponentException` from the run loop is logged and recorded as an error.

## Notes / v2 changes

- The friendly-key → Magento-key mapping (`label`→`frontend_label`, `type`→
  `backend_type`, etc.) and the `option.values` shape are unchanged from v1; you can
  still supply raw Magento keys directly.
- v2 adds dry-run support and `ComponentResult` counts.
- Note `user_defined` defaults to `1` when omitted, so attributes are created as
  user-defined unless you explicitly set `user_defined: 0`.
