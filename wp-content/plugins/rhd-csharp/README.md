# RHD C. Sharp

A WordPress plugin which adds custom functionality to C. Sharp Music, including a custom bulk import tool for migrating C. Sharp music products into WooCommerce with automatic Product Bundle creation.

## Features

- **CSV Import**: Upload and import products from CSV files
- **Automatic Product Bundles**: Creates bundles for product families based on SKU prefixes
- **WooCommerce Integration**: Full integration with WooCommerce products and categories
- **Product Family Grouping**: Groups related products (e.g., CB-1001-SC, CB-1001-FL1) into bundles
- **Custom Meta Fields**: Imports custom product data like difficulty, instrumentation, etc.
- **Error Handling**: Comprehensive error reporting during import process

## Requirements

- WordPress 5.0+
- PHP 8.1+
- WooCommerce plugin (active)
- WooCommerce Product Bundles plugin (active)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce and WooCommerce Product Bundles are installed and activated
4. Navigate to WooCommerce > C. Sharp Importer

## CSV Format

The CSV file should contain the following columns:

- **Product Title**: Name of the product
- **Product ID**: SKU (e.g., CB-1001, CB-1001-SC, CB-1001-FL1)
- **Single Instrument**: Type of product/instrument
- **Product File Name**: File name reference
- **Price**: Product price
- **Original URL**: Source URL
- **Category**: Product category
- **Image File Name**: Image reference
- **Byline**: Author/composer information
- **Ensemble Type**: Target audience
- **Difficulty**: Difficulty difficulty
- **Description**: Product description
- **Soundcloud Link**: Audio sample URL
- **Soundcloud Link 2**: Additional audio sample URL
- **Instrumentation**: Instrumentation details
- **Sound Filenames**: Audio file references
- **Grouped Products**: Related product SKUs (auto-generated)

## How It Works

### Product Import Process

1. **Individual Products**: All products with SKUs are imported as WooCommerce Simple Products
2. **Product Families**: Products are grouped by base SKU (e.g., CB-1001)
3. **Bundle Creation**: For each product family, a Product Bundle is created
4. **Bundle Configuration**: Bundles include all related products as optional items

### SKU Family Logic

Products are grouped into families based on their SKU prefix:

- `CB-1001` (Full Set)
- `CB-1001-SC` (Score)
- `CB-1001-FL1` (Flute 1)
- `CB-1001-FL2` (Flute 2)
- etc.

All products with the same base SKU (`CB-1001`) are grouped into one bundle titled "Tonality Shifting Warm-ups" (without "- Full Set").

### Bundle Product Features

- **Bundle Title**: Derived from Full Set product title (minus "- Full Set")
- **Optional Items**: All bundled products are optional with quantity 0-1
- **Custom Pricing**: Uses WooCommerce Product Bundles pricing logic
- **Hide Thumbnails**: Set to false for all bundled items

## Usage

1. Go to **WooCommerce > C. Sharp Importer**
2. Upload your CSV file
3. Choose import options:
   - **Create Product Bundles**: Automatically create bundles for product families
   - **Update Existing Products**: Update products with matching SKUs
4. Click **Import Products**
5. Monitor the progress and review results

## Custom Meta Fields

The following custom meta fields are added to products:

- `_single_instrument`: Instrument type
- `_difficulty`: Difficulty difficulty
- `_for_whom`: Target audience
- `_byline`: Author/composer
- `_instrumentation`: Instrumentation details
- `_created_via_import`: Import tracking flag
- `_bundle_base_sku`: Base SKU for bundles

## Error Handling

The plugin provides detailed error reporting for:

- CSV parsing issues
- Product creation failures
- Bundle creation problems
- Missing required data
- Plugin dependency issues

## Development

### Plugin Structure

```
rhd-csharp/
├── rhd-csharp.php  # Main plugin file
├── assets/
│   └── admin.js                     # Admin interface JavaScript
├── README.md                        # Documentation
└── languages/                       # Translation files (future)
```

### Key Functions

- `import_products_from_csv()`: Main import logic
- `import_single_product()`: Individual product creation
- `create_product_bundle()`: Bundle creation
- `get_base_sku()`: SKU family grouping logic

## Support

For issues or questions, contact [Roundhouse Designs](https://roundhouse-designs.com).

## License

GPL-2.0+ License
