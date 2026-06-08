# Media (`media`)

Recreates a media folder tree under `pub/media` and downloads remote files into it.

## Source format

```yaml
wysiwyg:
  -
    name: placeholder.gif
    location: http://placehold.it/350x150
  -
    name: placeholder2.gif
    location: http://placehold.it/350x550
other_folder:
  another_sub_folder:
    -
      name: placeholder1.gif
      location: http://placehold.it/350x150
    -
      name: placeholder2.gif
      location: http://placehold.it/350x550
```

The structure is recursive. Any **non-numeric** key is treated as a directory name
(relative to `pub/media`) and is recursed into. Any **numeric** key (i.e. a list item)
is treated as a file to download, and must carry `name` + `location`. Directories can be
nested to any depth, as shown by `other_folder/another_sub_folder`.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| *(top level)* | yes | map | Each key is a directory name created directly under `pub/media`. |
| `<dir>` | — | map or list | A map nests further directories; a list holds file entries. |
| `<dir>[]` | — | map | A list item describes a single file to download. |
| `<dir>[].name` | yes | string | Destination file name within the current directory. Missing `name` raises an error for that item (logged, item skipped). |
| `<dir>[].location` | yes | string | Source the file is fetched from via `file_get_contents` (any URL or path PHP can read). Missing `location` raises an error for that item (logged, item skipped). |

## Behaviour

- **Directories: create-if-missing.** If the directory does not exist it is created
  (`mkdir`, recursive, mode `0777`) and counted as *created*; if it already exists it is
  counted as *skipped* (comment logged).
- **Files: create-only.** If the destination file already exists it is *skipped* (comment
  logged) and not re-downloaded. Otherwise the contents are fetched from `location` and
  written to `<dir>/<name>`, counted as *created*.
- **Dry-run.** Logs `[dry-run] Would create new media directory …` / `[dry-run] Would
  download contents of file from … to …` and records the create without touching the
  filesystem.
- **Error cases.** An empty / non-array `media` node adds the error
  `No "media" node found in the source data.` and aborts. A file item missing `name` or
  `location` throws a `ComponentException` that is logged and skipped per item; the rest of
  the tree still processes.

## Notes / v2 changes

- There is no top-level `media:` key inside the source file itself — the file *is* the tree
  of directories (the `media:` alias lives only in `master.yaml`, pointing at this source).
- File downloads use `file_get_contents` / `file_put_contents` directly, so `location`
  must be reachable from the machine running the configurator.
- Files are never overwritten or removed; to refresh a file you must delete it from
  `pub/media` first.
