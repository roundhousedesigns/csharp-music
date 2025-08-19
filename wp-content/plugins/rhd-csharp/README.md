# RHD C. Sharp

A WordPress plugin which adds custom functionality to C. Sharp Music, including a custom bulk import tool for migrating C. Sharp music products into WooCommerce with automatic Product Bundle creation.

## Features

- **CSV Import**: Upload and import products from CSV files
- **Automatic Product Bundles**: Creates bundles for product families based on SKU prefixes
- **Grouped Product Creation**: Creates WooCommerce Grouped Products containing Digital and Hardcopy variants
- **WooCommerce Integration**: Full integration with WooCommerce products, categories, bundles, and grouped products
- **Product Family Grouping**: Groups related products (e.g., CB-1001-SC, CB-1001-FL1) into bundles and grouped products
- **Digital/Hardcopy Support**: Handles both digital and hardcopy variants of Full Set products
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
- **Digital or Hardcopy**: Indicates whether the product is Digital or Hardcopy (for Full Set products)
- **Single Instrument**: Type of product/instrument
- **Product File Name**: File name reference
- **Price**: Product price
- **Original URL**: Source URL
- **Category**: Product category
- **Image File Name**: Image reference
- **Byline**: Author/composer information
- **Ensemble Type**: Target audience
- **Difficulty**: Difficulty rating
- **Description**: Product description
- **Soundcloud Link**: Audio sample URL
- **Soundcloud Link 2**: Additional audio sample URL
- **Instrumentation**: Instrumentation details
- **Sound Filenames**: Audio file references

## How It Works

### Product Import Process

1. **Individual Products**: All products with SKUs (except Full Set products) are imported as WooCommerce Simple Products
2. **Product Families**: Products are grouped by base SKU (e.g., CB-1001)
3. **Bundle Creation**: For each product family with Digital Full Set data, a Product Bundle is created
4. **Hardcopy Product Creation**: For product families with Hardcopy Full Set data, a standalone hardcopy product is created
5. **Grouped Product Creation**: For each product family, a WooCommerce Grouped Product is created containing both Digital Bundle and Hardcopy variants

### SKU Family Logic

Products are grouped into families based on their SKU prefix:

- `CB-1001-FS-D` (Full Set Digital)
- `CB-1001-FS-H` (Full Set Hardcopy) 
- `CB-1001-SC` (Score)
- `CB-1001-FL1` (Flute 1)
- `CB-1001-FL2` (Flute 2)
- etc.

All products with the same base SKU (`CB-1001`) are grouped together.

### Product Hierarchy

1. **Grouped Product** (`CB-1001`): Main product using base SKU and base title
   - Contains two child products:
   - **Digital Bundle** (`CB-1001-FS-D`): Contains all individual instruments as bundle items
   - **Hardcopy Product** (`CB-1001-FS-H`): Standalone physical product

### Bundle Product Features

- **Bundle Title**: Derived from Full Set Digital product title (minus "- Full Set Digital")
- **Bundle Items**: All individual instrument products are included as bundle items
- **Custom Pricing**: Uses WooCommerce Product Bundles pricing logic
- **Downloadable**: Digital bundles are marked as downloadable and virtual

### Grouped Product Features

- **Base Title**: Derived from Full Set product title (minus suffixes like "- Full Set", "Digital", etc.)
- **Base SKU**: Uses the family base SKU (e.g., CB-1001)
- **Child Products**: Contains Digital Bundle and/or Hardcopy product
- **Attributes**: Inherits attributes from Full Set product data

## Usage

1. Go to **WooCommerce > C. Sharp Importer**
2. Upload your CSV file
3. Choose import options:
   - **Create Product Bundles**: Automatically create bundles for product families
   - **Create Grouped Products**: Automatically create grouped products containing Digital and Hardcopy variants
   - **Update Existing Products**: Update products with matching SKUs
4. Click **Import Products**
5. Monitor the progress and review results

## Custom Meta Fields

The following custom meta fields are added to products:

- `_single_instrument`: Instrument type
- `_difficulty`: Difficulty rating
- `_for_whom`: Target audience
- `_byline`: Author/composer
- `_instrumentation`: Instrumentation details
- `_created_via_import`: Import tracking flag
- `_bundle_base_sku`: Base SKU for bundles
- `_grouped_base_sku`: Base SKU for grouped products

## Error Handling

The plugin provides detailed error reporting for:

- CSV parsing issues
- Product creation failures
- Bundle creation problems
- Grouped product creation problems
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
- `create_grouped_product()`: Grouped product creation
- `get_base_sku()`: SKU family grouping logic

## Support

For issues or questions, contact [Roundhouse Designs](https://roundhouse-designs.com).

## License

GPL-2.0+ License
