# TopTex for WooCommerce

Imports the TopTex garment wholesale catalog (from the TopTex API) into WooCommerce as products.

## Language

**Style**:
A distinct TopTex product family, identified by a `catalogReference` (e.g. `B610`). A style has one or more colors, each of which comes in multiple sizes.
_Avoid_: Product, item (ambiguous — a "style" is < a color < a size).

**Color**:
A named colourway of a Style, identified by a `colorCode`. Each color has its own packshot images.
_Avoid_: Variant, option.

**Size**:
A sellable size of a Color (e.g. "One Size", "M", "4XL"). A Size is the atomic sellable unit, carrying a `sku`, `ean`, price and stock.
_Avoid_: Variation.

**Catalog Reference**:
The TopTex style identifier (`catalogReference`), e.g. `B610`. The unique key that maps a TopTex Style back to a WooCommerce product.

**SKU**:
A stock-keeping unit. TopTex SKUs are compound: `{catalogReference}_{colorCode}_{sizeCode}` (e.g. `B610_70609_70608`). One SKU = one Size.

**Usage Right**:
A partner-level licensing flag controlling which catalog subset a partner may resell: `b2b_uniquement`, `b2c_uniquement`, or `b2b_b2c`. Required on every catalog/price/inventory request.
_Avoid_: License, tier.

## Relationships

- A **Style** has one or more **Colors**.
- A **Color** has one or more **Sizes**.
- Each **Size** has exactly one **SKU**.
- A **SKU** maps to one WooCommerce product **Variation**.
- A **Style** maps to one WooCommerce variable **Product**.

## Example dialogue

> **Dev:** "When we import a Style, do we create one WooCommerce product per Color or per Size?"
> **Domain expert:** "Neither — a Style is one WooCommerce variable product, its Colors are the `Color` attribute terms, and each Color×Size pair is a Variation. The SKU lives on the Variation, not the parent."

## Flagged ambiguities

- "product" was used to mean both the TopTex Style and the WooCommerce parent product. Resolved: use **Style** for the TopTex concept, **Product** for the WooCommerce parent product.
- The previous public-index integration used `reference_catalogue` where the v3 API uses `catalogReference`. Resolved: `catalogReference` is canonical; `reference_catalogue`/`supplierReference` are aliases.
