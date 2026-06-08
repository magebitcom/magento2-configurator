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
    - ../configurator/<Component>/<file>.yaml
  env:                       # optional, per-environment overrides
    <environment>:
      mode: create | maintain
      sources:
        - ...
```

The per-component pages below document the structure of the referenced source files.

| Component | Alias | Source format | Page |
|-----------|-------|---------------|------|
| Customer Groups | `customergroups` | YAML | [customergroups.md](customergroups.md) |

_(Pages are added as each component is documented during the v2 modernization.)_
