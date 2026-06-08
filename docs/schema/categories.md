# Categories (`categories`)

Creates and maintains a (recursive) category tree underneath the root category of a
store group.

## Source format

```yaml
categories:
  -
    store_group: Main Website Store   # optional — defaults to "Main Website Store"
    categories:
      -
        name: Category 1              # required — used to look the category up under its parent
        description: "A description"
        url_key: "category-1"
        categories:                   # optional — nested children (recursive, unlimited depth)
          -
            name: "Sub Category 1"
            description: "Sub Category 1 description"
            url_key: "sub-category-1"
      -
        name: Category 2
        description: "A description"
        url_key: "category-2"
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `categories` | yes | list | One entry per store group. |
| `categories[].store_group` | no | string | Name of an existing store group. Defaults to `Main Website Store`. Must resolve to **exactly one** store group, otherwise the whole entry errors out (see Behaviour). The group's root category is the parent of the top-level `categories` list. |
| `categories[].categories` | yes | list | Top-level categories to create/update under the store group's root category. |
| `categories[].categories[].name` | yes | string | Category name. Used together with the parent id to find an existing category, so it is effectively the lookup key within a parent. |
| `categories[].categories[].is_active` | no | bool/int | Mapped directly. If omitted, the category is forced active (`is_active = true`). |
| `categories[].categories[].position` | no | int | Mapped directly. |
| `categories[].categories[].include_in_menu` | no | bool/int | Mapped directly. |
| `categories[].categories[].description` | no | string | Mapped directly. |
| `categories[].categories[].page_layout` | no | string | Mapped directly. |
| `categories[].categories[].custom_use_parent_settings` | no | bool/int | Mapped directly. |
| `categories[].categories[].image` | no | string | Local path or URL to an image. The file is copied into `media/catalog/category/` and the basename stored on the category. Local paths are resolved relative to the Magento base path (`BP`). A failed copy is logged and the image is skipped (the category is still saved). |
| `categories[].categories[].landing_page` | no | string | CMS block **identifier**. Resolved to the block id and stored as `landing_page`. If the identifier does not resolve, the field is silently skipped. |
| `categories[].categories[].categories` | no | list | Nested child categories. Processed recursively with the current category as parent. |
| `categories[].categories[].<other>` | no | mixed | Any other key is set as a custom attribute via `setCustomAttribute()`. |

The recognised "main" attributes mapped directly with `setData()` are: `name`,
`is_active`, `position`, `include_in_menu`, `description`, `page_layout`,
`custom_use_parent_settings`. `image`, `landing_page` and `categories` get the
special handling above; everything else falls through to custom attributes.

## Behaviour

- **Lookup by name + parent.** A category is matched by its `name` filtered to the
  current parent category id. If a match is found it is **updated**; otherwise a new
  category is **created**.
- **Mode.** In `create` mode an already-existing category is **skipped** (comment
  logged, recorded as skipped). In `maintain` mode existing categories are updated.
  New categories are created in both modes.
- **Recursive.** Children under `categories[]` are processed after the parent is
  saved, with the saved parent as their parent category.
- **Default scope.** Categories are saved with `store_id = 0` (default scope) and the
  entity type's default attribute set.
- **Dry-run.** Logs `[dry-run] Would create/update category <name>` and records the
  create/update in the result, but performs no `save()`. Note: image copying and
  block-identifier resolution still run during a dry-run (they happen before the
  save check).
- **Error handling.** If the `categories` node is missing/not an array the component
  errors out. Per store-group entry, a missing default category, or a store group
  that resolves to zero or more than one match, raises a `ComponentException` that is
  logged and recorded as an error; remaining entries still run.

## Notes / v2 changes

- The `store_group` is resolved against `Magento\Store\Model\GroupFactory` by `name`;
  the root category of that group is the top-level parent.
- Image and `landing_page` resolution behaviour is unchanged from v1.
- Dry-run support (`[dry-run]` log lines, no `save()`) and the per-row
  created/updated/skipped result counters are v2 additions.
