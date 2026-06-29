# WooCommerce Prescription Lens Options

A standalone WooCommerce plugin for selling prescription and non-prescription lens packages through a modern responsive modal flow.

## Admin

The plugin creates a dedicated WordPress sidebar menu:

- Lens Options
- Lens Packages
- Prescription Methods
- Add-ons
- Display Rules
- Text & Translation
- Orders / Prescriptions
- Settings

## Frontend Flow

Eligible products show a **Select Lens** button near the WooCommerce add-to-cart button.

The customer flow is intentionally simple:

1. Choose usage: prescription, non-prescription, or frame only.
2. Choose a lens type and package from grouped accordion sections.
3. Add prescription by upload, manual typing, scan beta, or WhatsApp later.
4. Choose optional add-ons.
5. Review and add to cart.

## Product Eligibility

Display rules can be managed in **Lens Options > Display Rules**. The plugin supports three behaviors:

- Show on all products except when a product attribute or meta key matches a value.
- Only show when a product attribute or meta key matches a value.
- Show on all products.

For example, to hide lens options for frames that are not prescription-ready, use:

```text
Display behavior: Show on all products except when this field matches
Product attribute/meta key: prescription-ready
Match type: Equals one of these values
Match value: No
```

Multiple match values can be separated with commas.

Each product also has a **Lens options** override and **Frame type** field in the WooCommerce product editor.

## Lens Pricing

Lens Packages act as primary lens categories. A package can either be selected directly, or it can expose child lens options from the Lens Package editor.

Child lens options are managed with an inline admin repeater. Each child option has fields for name, regular price, sale price, feature bullets, details text, active status, sort order, and optional color choices.

If a sale price is present and lower than the regular price, the sale price is charged and the regular price is shown struck through in the modal.

The selected lens price is added to the frame cart line. Important lens fields are saved as readable order item meta, including category, option, regular price, sale price, final lens price, prescription method, WhatsApp number, prescription file URL, and file name.

Standard WooCommerce coupons do not automatically target this custom lens price delta in a reliable way. Coupon compatibility should be handled later as a dedicated lens-discount system or deeper WooCommerce coupon integration.

## Add-ons

Use **Lens Options > Add-ons** to configure WooCommerce product add-ons.

Linked product add-ons are managed with an inline repeater and WooCommerce product search by product name, SKU, or ID. Linked add-ons are added as separate WooCommerce cart/order line items and tagged with lens add-on metadata for cleaner REST API reporting.

Legacy add-on rows still work, but their price is added to the frame line item.

## Styling

Use **Lens Options > Settings** to adjust the modal accent color, primary button colors, corner radius, and max width.

The same page also controls the card description font size and whether the lens flow uses the wider full-screen style.

## Lens Colors

Each lens package can define optional color choices from the Lens Package editor. Use comma-separated values, for example:

```text
Gray, Brown, Green
```

When a package has color choices, customers must choose one before continuing.

## Prescription Files

Uploaded prescriptions are stored in:

```text
wp-content/uploads/woocommerce-prescriptions/YYYY/MM
```

The order item stores the prescription file URL and file name. Logged-in customers can also view their prescription orders from **My Account > Prescriptions**.

Old unattached prescription files can be cleaned up from **Lens Options > Prescription Methods**. Cleanup is disabled by default and only deletes files that are not referenced by saved order prescription metadata.

## OCR Scanning

Use **Lens Options > Prescription Methods** to enable the scan option and configure OCR.

Supported provider:

```text
OCR.space
```

Add your OCR.space API key, keep the language code as `eng` for English prescription forms, and enable **Scan prescription upload beta**. The scan attempts to pre-fill SPH, CYL, AXIS, and PD fields, but customers must still review and edit the values before continuing.

Bengali or handwritten prescriptions may not parse reliably, so uploaded files are still attached to the order for admin review.

## Local Development

This repository is the plugin root. For local WordPress testing, copy or symlink this repository folder into:

```text
wp-content/plugins/woocommerce-prescription-lenses
```

Keep changes synced to GitHub with:

```bash
git add .
git commit -m "Describe the update"
git push
```
