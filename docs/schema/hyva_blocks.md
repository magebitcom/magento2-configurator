# Hyvä CMS Blocks (`hyva_blocks`)

Manages **Hyvä Commerce CMS** block-builder content (the JSON draft/published
content attached to a CMS block) from configurator sources. Can auto-create the
backing native CMS block.

> **Requires the paid `Hyva_CmsMagento` module.** Optional: no-ops when the module
> is absent, with no effect on `setup:di:compile`.

## Source format

```yaml
home-hero:
  content_source: app/etc/configurator/HyvaBlocks/content/home-hero.json
  is_liveview_enabled: true
  store_ids: [0]
  auto_create_block: true
  block_title: "Home Hero"
  block_is_active: true
  version: 1
```

The source is a map keyed by the **CMS block identifier**.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `<identifier>` | yes | map | Key is the CMS block `identifier`. |
| `.content` / `.content_source` | one of | string | Inline JSON / file path applied to both draft and published. |
| `.draft_content[_source]` / `.published_content[_source]` | no | string | Set draft / published separately. |
| `.is_liveview_enabled` | no | bool | Defaults to `true`. |
| `.store_ids` | no | list/int | Store ids the block is assigned to (`0` = all). Synced to `cms_block_store`. |
| `.auto_create_block` | no | bool | If the CMS block doesn't exist, create it (else a `ComponentException` is recorded). |
| `.block_title` | no | string | Title for an auto-created block (defaults to a humanised identifier). |
| `.block_is_active` | no | bool | Active flag for an auto-created block (default `true`). |
| `.version` | no | int | Per-entity version. |

The JSON `contentId` is kept aligned with the resolved block id automatically.

## Behaviour

- **Mode + versioning** via the shared gate (create-protects existing Hyvä content
  unless `version` bumped; `maintain` reconciles; unchanged content is skipped).
- **CMS block** is looked up by identifier; created when missing and
  `auto_create_block` is set. Store associations are reconciled against `store_ids`
  using the entity link field (`row_id`/`block_id`).
- **Persistence** is a direct insert/update on `hyva_commerce_cms_block` (keyed by
  `cms_block_id`) to preserve JSON escaping.
- **Dry-run** logs `[dry-run] Would create/update Hyvä CMS block "<id>"` and writes nothing.
