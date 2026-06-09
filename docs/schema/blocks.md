# Blocks (`blocks`)

Creates and maintains CMS blocks, optionally per store view, with content sourced
either inline or from an included PHP/HTML template file. Supports versioning so a
block is only re-applied when its version number increases.

## Source format

The top-level keys are block **identifiers**. Each identifier holds a `block` list of
one or more definitions (one per store-view target).

```yaml
all_stores_identifier:
  block:
    -
      source: ../configurator/Blocks/allstores.html   # template file, rendered into content
      title: All stores
      is_active: 1
certain_stores_identifier:
  block:
    -
      source: ../configurator/Blocks/uk.html
      title: UK Block
      is_active: 1
      stores:
        - default                                       # store codes this definition applies to
    -
      source: ../configurator/Blocks/us.html
      title: US Block
      is_active: 1
      stores:
        - usa_en_us
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `<identifier>` | yes | map | Top-level key = CMS block `identifier`. |
| `<identifier>.block` | yes | list | One or more block definitions; iterate to target different stores. |
| `<identifier>.block[].title` | yes | string | Block title. |
| `<identifier>.block[].is_active` | no | int | `1`/`0`. |
| `<identifier>.block[].source` | no | string | Path (relative to Magento base path `BP`) to a `.html`/`.phtml` template. The file is `include`d and its rendered output becomes the block `content`. If the file does not exist, processing of this block returns early. Mutually used in place of `content`. |
| `<identifier>.block[].content` | no | string | Inline block content. Use this instead of `source` when you don't need a template file. |
| `<identifier>.block[].stores` | no | list | Store **codes** the block applies to. Each is resolved to a store id; an unknown code raises an error. When omitted the block is saved at default scope (store id 0). |
| `<identifier>.block[].version` | no | int | Version number. The block is only re-applied when this value is greater than the last recorded version for this identifier (+ stores). Stripped before saving. |
| `<identifier>.block[].remove` | no | bool | When truthy, **delete** the block (by `identifier`, narrowed by the first store code when `stores` is set) instead of creating/updating it. Applies in both `create` and `maintain` mode. Idempotent: a block already absent is recorded as skipped. All other fields on the entry are ignored when `remove` is set. |
| `<identifier>.block[].<other>` | no | mixed | Any other key is set directly on the block via `setData()` (only when its value differs from the current value). |

The `source` template is executed as PHP with `$escaper`
(`Magento\Framework\Escaper`) and `$viewModels`
(`Hyva\Theme\Model\ViewModelRegistry`, when Hyvä is installed) in scope, so templates
can call escaping helpers and Hyvä view models. A plain HTML file such as
`Samples/Components/Blocks/us.html` (`<h1>US Block</h1>`) is captured verbatim.

## Behaviour

- **Create vs. update by identifier (and store).** Existing blocks are looked up by
  `identifier`; when `stores` is given the lookup is narrowed by the first store code.
  A match is updated, otherwise a new block is created.
- **Dirty check.** A block is only saved if at least one field value actually changed
  (or it is new); unchanged blocks are not re-saved.
- **Mode.** In `create` mode an existing block is **skipped** (recorded as skipped)
  unless its `version` is newer. In `maintain` mode existing blocks are updated. New
  blocks are created in both modes.
- **Versioning.** When `version` is set it is compared against the stored version
  (keyed by `blocks_<identifier>` plus the store codes). Only a higher version is
  treated as a new version; after a successful apply the new version is recorded.
- **Sync from DB.** `configurator:sync-from-db --component=blocks --all` exports every
  block: each block's content is written to an external file under
  `app/etc/configurator/Blocks/content/<identifier>.html` and the YAML entry references
  it via `source:` (never inlined as `content:`). When the component isn't wired in
  `master.yaml`, the export still runs and defaults to `app/etc/configurator/Blocks/blocks.yaml`.
- **Removal.** `remove: true` on a definition deletes the matching block via the
  block repository, in either mode, and records it as *removed* in the run summary.
  A block that does not exist is recorded as *skipped* (no error), so the entry is
  safe to keep in the file or remove once applied. Dry-run logs
  `[dry-run] Would remove block <identifier>` and deletes nothing.
- **Dry-run.** Logs `[dry-run] Would create/save block <identifier>` and
  `[dry-run] Would set version …`, records the create/update, but performs no
  `save()` and does not persist the version.
- **Error handling.** Non-array input records an error. An unknown store code raises a
  `ComponentException` that is logged; per-block exceptions are caught so the run
  continues.

## Notes / v2 changes

- `source` templates are rendered with Hyvä view-model support: `$viewModels` is the
  `Hyva\Theme\Model\ViewModelRegistry` when the Hyvä theme module is present
  (otherwise `null`), alongside `$escaper`.
- Version-gated re-application (`version`) and dry-run support are v2 additions.
- The created/updated/skipped result counters are recorded per definition in v2.
