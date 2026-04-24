# Topdata Product Flags SW6

![Plugin Icon](src/Resources/config/plugin.png)

## Overview

This plugin provides backend automation for managing product flags via custom fields. It introduces custom fields for identifying products as "Topseller" or "Fresh Product" and provides CLI commands to calculate and update these flags automatically based on your product data.

## Custom Fields Added

- `topdata_is_topseller` (Boolean)
- `topdata_is_fresh_product` (Boolean)

## CLI Commands

You can run these commands manually or configure them as periodic cronjobs.

### Update Topsellers

Identifies the best-selling products and sets their `topdata_is_topseller` flag to true, while resetting the flag for products that no longer qualify.

```bash
bin/console topdata:product-flags:update-topseller --max-products 50 --min-sales 10
```

Options:

- `--max-products` (default `50`): Maximum number of top products to flag.
- `--min-sales` (default `10`): The minimum sales a product needs to be considered.

### Update Fresh Products

Evaluates the `releaseDate` (or `createdAt` if `releaseDate` is empty) and sets the `topdata_is_fresh_product` flag to true if the product was released within the defined days.

```bash
bin/console topdata:product-flags:update-fresh --max-age 30
```

Options:

- `--max-age` (default `30`): The maximum age of the product in days to still be considered fresh.

## Frontend Integration (Theme Example)

The display logic is separated and should be handled by your custom storefront theme. Use the following Twig example in your product box template (for example in `storefront/component/product/card/badges.html.twig`) to display the flags:

```twig
{% sw_extends '@Storefront/storefront/component/product/card/badges.html.twig' %}

{% block component_product_badges %}
	{{ parent() }}

	{% if product.translated.customFields.topdata_is_topseller %}
		<div class="badge bg-warning badge-topseller">
			{{ "topseller.badgeLabel"|trans|sw_sanitize }}
		</div>
	{% endif %}

	{% if product.translated.customFields.topdata_is_fresh_product %}
		<div class="badge bg-success badge-new-product">
			{{ "freshProduct.badgeLabel"|trans|sw_sanitize }}
		</div>
	{% endif %}
{% endblock %}
```

## Installation

1. Download the plugin
2. Upload to your Shopware 6 installation
3. Install and activate the plugin
4. The custom fields will be automatically created upon installation.

## Requirements

- Shopware 6.7.*

## License

MIT