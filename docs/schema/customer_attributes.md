# Customer Attributes (`customer_attributes`)

Creates or updates EAV attributes on the `customer` entity, including their dropdown options
and the storefront/admin forms they appear in. Extends the generic `attributes` component.

## Source format

```yaml
customer_attributes:
  text_attribute:
    label: Text attribute
    input: text
    visible: 1
    position: 10
    used_in_forms: customer_account_create,customer_account_edit
  dropdown_attribute:
    label: Dropdown attribute
    input: select
    visible: 1
    position: 20
    used_in_forms: customer_account_create,customer_account_edit
    option:
      values:
        - Option 1
        - Option 2
        - Option 3
        - etc
  boolean_attribute:
    label: Boolean attribute
    input: boolean
    visible: 1
    position: 30
    used_in_forms: checkout_register,customer_account_create,customer_account_edit,adminhtml_checkout
```

Each key under `customer_attributes` is the **attribute code**.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `customer_attributes` | yes | map | One entry per attribute. A missing / non-array node adds the error `No "customer_attributes" node found in the source data.` and aborts. |
| `customer_attributes.<code>` | yes | map | Map key is the attribute code. |
| `customer_attributes.<code>.label` | yes | string | Frontend label (mapped to `frontend_label`). |
| `customer_attributes.<code>.input` | yes | string | Frontend input type (`frontend_input`), e.g. `text`, `select`, `boolean`. |
| `customer_attributes.<code>.visible` | no | int (0/1) | Whether shown in forms (`is_visible`). Customer-specific mapping. |
| `customer_attributes.<code>.position` | no | int | Sort order (`sort_order`). Customer-specific mapping. |
| `customer_attributes.<code>.system` | no | int (0/1) | Whether a system attribute (`is_system`). Customer-specific mapping. |
| `customer_attributes.<code>.used_in_forms` | no | string | Comma-separated form codes (e.g. `customer_account_create`, `customer_account_edit`, `checkout_register`, `adminhtml_checkout`, `adminhtml_customer`). Only applied for **new** attributes; if omitted, the defaults `customer_account_create, customer_account_edit, adminhtml_checkout, adminhtml_customer` are used. |
| `customer_attributes.<code>.option` | no | map | For option inputs (`select`, etc.). |
| `customer_attributes.<code>.option.values` | no | list of string | Option labels. On update, only labels not already present are added (no removal). |

Inherited generic attribute keys (mapped in the parent `attributes` component) are also
accepted, e.g. `type`→`backend_type`, `required`→`is_required`, `source`→`source_model`,
`backend`→`backend_model`, `default`→`default_value`, `unique`→`is_unique`,
`searchable`→`is_searchable`, `user_defined`→`is_user_defined`. `user_defined` defaults to
`1` when not supplied.

## Behaviour

- **Create or update, keyed on attribute code.** If the attribute already exists, each
  mapped field is compared to the stored value; the attribute is re-saved only if something
  actually differs (counted as *updated*), otherwise it is *skipped*
  (`No update required…`). A brand-new attribute is added and counted as *created*.
- **Options.** For an existing attribute, `option.values` not already present are appended;
  existing options are never removed.
- **`used_in_forms` / additional values.** Applied **only when the attribute is newly
  created** (`addAdditionalValues` returns early if the attribute already existed). It binds
  the attribute to attribute set `1` / group `1` and the listed forms.
- **Dry-run.** Logs `[dry-run] Would add/update attribute <code>.` and
  `[dry-run] Would apply additional values to <code>.`, recording the change without
  persisting.
- **Error cases.** A `ComponentException` during the loop is logged and added to the result.
  Errors while applying additional values are logged per attribute and do not abort the run.

## Notes / v2 changes

- `entityTypeId` is forced to `customer`; this is what distinguishes it from the
  product-facing `attributes` component despite sharing the same processing code.
- `visible`, `position`, `system` are the customer-specific aliases layered on top of the
  shared attribute config map.
- `used_in_forms` is intentionally **create-time only** — changing the forms of an existing
  customer attribute via this component has no effect.
