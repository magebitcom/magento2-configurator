# Pages (`pages`)

Creates and maintains CMS pages, optionally per store view, with content sourced
either inline or from an included PHP/HTML template file. Supports versioning so a
page is only re-applied when its version number increases.

## Source format

The top-level keys are page **identifiers** (URL keys). Each identifier holds a `page`
list of one or more definitions (one per store-view target).

```yaml
all_stores_identifier:
  page:
    -
      source: ../configurator/Pages/allstores.html   # template file, rendered into content
      title: All stores Update 2
      meta_title:
      meta_keywords:
      meta_description:
      content_heading:
      content:
      sort_order:
      layout_update_xml:
      custom_theme:
      custom_root_template:
      custom_layout_update_xml:
      custom_theme_from:
      custom_theme_to:
      page_layout: 1column
      is_active: 1
      stores:
        - usa_en_us
inline_content_page:
  page:
    -
      content: Page Content                            # inline content instead of a source file
      title: Inline Content Page
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `<identifier>` | yes | map | Top-level key = CMS page `identifier` / URL key. |
| `<identifier>.page` | yes | list | One or more page definitions; iterate to target different stores. |
| `<identifier>.page[].title` | yes | string | **Required.** A missing `title` raises a `Required Data Missing` error. |
| `<identifier>.page[].is_active` | no | int | Defaults to `1` when omitted. |
| `<identifier>.page[].page_layout` | no | string | Defaults to `empty` when omitted (e.g. `1column`, `2columns-left`). |
| `<identifier>.page[].source` | no | string | Path (relative to Magento base path `BP`) to a `.html`/`.phtml` template. The file is `include`d and its rendered output becomes the page `content`. If the file does not exist, processing of this page returns early. Use instead of `content`. |
| `<identifier>.page[].content` | no | string | Inline page content. Use instead of `source`. |
| `<identifier>.page[].stores` | no | list | Store **codes** the page applies to. Each is resolved via the store repository (unknown codes raise `NoSuchEntityException`, logged). When omitted the page is saved at default scope (store id 0). |
| `<identifier>.page[].version` | no | int | Version number; the page is only re-applied when greater than the last recorded version (keyed by identifier + stores). Stripped before saving. |
| `<identifier>.page[].meta_title`, `meta_keywords`, `meta_description`, `content_heading`, `sort_order`, `layout_update_xml`, `custom_theme`, `custom_root_template`, `custom_layout_update_xml`, `custom_theme_from`, `custom_theme_to` | no | mixed | Standard CMS page fields, set directly when their value differs from the current value. Empty YAML values are passed through as empty. |
| `<identifier>.page[].<other>` | no | mixed | Any other key is set directly on the page via `setData()`. |

As with blocks, a `source` template is executed as PHP with `$escaper` and
`$viewModels` (Hyvä `ViewModelRegistry`, when installed) in scope; its captured output
becomes the page content.

## Behaviour

- **Create vs. update by identifier (and store).** The existing page id is resolved by
  `identifier`, scoped to the default store (0) plus the target store id (preferring
  the store-specific row). A match is updated; otherwise a new page is created.
- **Required/default fields.** `title` is required; `page_layout` defaults to `empty`
  and `is_active` to `1` when not supplied.
- **Dirty check.** The page is only saved when the model reports data changes
  (`hasDataChanges()`); unchanged pages are not re-saved.
- **Mode.** In `create` mode an existing page is **skipped** (recorded as skipped)
  unless its `version` is newer. In `maintain` mode existing pages are updated. New
  pages are created in both modes.
- **Versioning.** Identical to blocks: only a higher `version` re-applies the page;
  the new version is recorded after a successful apply.
- **Dry-run.** Logs `[dry-run] Would create/save page <identifier>` and
  `[dry-run] Would set version …`, records the create/update, but performs no save and
  does not persist the version.
- **Error handling.** Non-array input records an error. A missing required field raises
  a `ComponentException` (logged, recorded as error); `NoSuchEntityException` from an
  unknown store code is caught and logged.

## Notes / v2 changes

- Page lookup is done via a direct `cms_page` / `cms_page_store` query using entity
  metadata (identifier + link fields), scoped to default + target store, replacing the
  v1 collection lookup.
- `source` templates are rendered with Hyvä view-model support (`$viewModels`) when the
  Hyvä theme module is present, alongside `$escaper`.
- Version-gated re-application (`version`) and dry-run support are v2 additions.
