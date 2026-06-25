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
2. Add prescription by upload, manual typing, or WhatsApp later.
3. Choose a lens package.
4. Choose optional add-ons.
5. Review and add to cart.

## Product Eligibility

By default, the plugin checks for a product attribute or meta key:

```text
prescription_lens_available = yes
```

You can change this in **Lens Options > Display Rules**.

Each product also has a **Lens options** override and **Frame type** field in the WooCommerce product editor.

## Lens Pricing

The selected lens package and add-ons are added to the WooCommerce cart item price. Lens details are saved into cart item data and order item meta.

## GitHub Sync

Initialize git in this workspace, commit the plugin folder, add your GitHub repository as `origin`, then push:

```bash
git add woocommerce-prescription-lens-options
git commit -m "Add prescription lens options plugin"
git remote add origin https://github.com/YOUR-USERNAME/YOUR-REPO.git
git push -u origin master
```
