<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\TaxClass;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    /**
     * Category tree: parent => children.
     *
     * @var array<string, array<int, string>>
     */
    protected array $tree = [
        'Fashion' => ['Men’s Clothing', 'Women’s Clothing', 'Footwear', 'Bags & Wallets'],
        'Electronics' => ['Mobiles & Tablets', 'Audio', 'Laptops', 'Wearables'],
        'Home & Kitchen' => ['Cookware', 'Home Decor', 'Furniture', 'Storage'],
        'Beauty & Personal Care' => ['Skincare', 'Haircare', 'Fragrances'],
        'Sports & Fitness' => ['Gym Equipment', 'Sportswear', 'Outdoor'],
    ];

    /**
     * Product blueprints keyed by category name.
     *
     * @var array<string, array<int, array{0: string, 1: int, 2: string}>>
     */
    protected array $catalog = [
        'Men’s Clothing' => [
            ['Classic Cotton Crew T-Shirt', 799, 'Urban Thread'],
            ['Slim Fit Denim Jeans', 2199, 'Urban Thread'],
            ['Oxford Button-Down Shirt', 1899, 'Nordwear'],
            ['Lightweight Bomber Jacket', 3499, 'Nordwear'],
        ],
        'Women’s Clothing' => [
            ['Floral Wrap Midi Dress', 2499, 'Bloom & Co'],
            ['High-Waist Yoga Leggings', 1299, 'FlexFit'],
            ['Linen Blend Kurta', 1699, 'Rangoli'],
        ],
        'Footwear' => [
            ['Everyday Running Shoes', 3999, 'Stride'],
            ['Leather Chelsea Boots', 5499, 'Stride'],
            ['Canvas Slip-On Sneakers', 1799, 'Stride'],
        ],
        'Bags & Wallets' => [
            ['Water-Resistant Laptop Backpack', 2799, 'Trailhead'],
            ['Slim RFID Leather Wallet', 1199, 'Trailhead'],
        ],
        'Mobiles & Tablets' => [
            ['65W GaN Fast Charger', 2299, 'Voltix'],
            ['Tempered Glass Screen Guard', 399, 'Voltix'],
            ['Magnetic Car Phone Mount', 899, 'Voltix'],
        ],
        'Audio' => [
            ['Noise Cancelling Over-Ear Headphones', 8999, 'Sonata'],
            ['True Wireless Earbuds Pro', 4499, 'Sonata'],
            ['Portable Bluetooth Speaker', 3299, 'Sonata'],
        ],
        'Laptops' => [
            ['Aluminium Laptop Stand', 1999, 'DeskCraft'],
            ['USB-C 7-in-1 Hub', 2699, 'DeskCraft'],
        ],
        'Wearables' => [
            ['AMOLED Fitness Smartwatch', 6999, 'PulseOne'],
            ['Silicone Watch Strap', 599, 'PulseOne'],
        ],
        'Cookware' => [
            ['Triply Stainless Steel Kadai', 2899, 'HearthMade'],
            ['Non-Stick Dosa Tawa', 1399, 'HearthMade'],
            ['Cast Iron Skillet 10"', 2199, 'HearthMade'],
        ],
        'Home Decor' => [
            ['Ceramic Table Lamp', 2499, 'Casa Luz'],
            ['Handwoven Jute Rug', 3999, 'Casa Luz'],
            ['Framed Botanical Print Set', 1799, 'Casa Luz'],
        ],
        'Furniture' => [
            ['Solid Sheesham Study Table', 11999, 'Woodline'],
            ['Ergonomic Mesh Office Chair', 8999, 'Woodline'],
        ],
        'Storage' => [
            ['Stackable Storage Bins (Set of 3)', 1299, 'NeatNest'],
            ['Under-Bed Fabric Organiser', 899, 'NeatNest'],
        ],
        'Skincare' => [
            ['Vitamin C Brightening Serum', 1299, 'Glow Lab'],
            ['Ceramide Barrier Moisturiser', 1099, 'Glow Lab'],
            ['SPF 50 Mineral Sunscreen', 899, 'Glow Lab'],
        ],
        'Haircare' => [
            ['Argan Oil Repair Shampoo', 749, 'Glow Lab'],
            ['Scalp Detox Serum', 999, 'Glow Lab'],
        ],
        'Fragrances' => [
            ['Oud & Amber Eau de Parfum', 3499, 'Maison Nine'],
            ['Citrus Neroli Body Mist', 899, 'Maison Nine'],
        ],
        'Gym Equipment' => [
            ['Adjustable Dumbbell 20kg', 5999, 'IronCore'],
            ['Resistance Band Set', 899, 'IronCore'],
            ['Anti-Slip Yoga Mat 6mm', 1499, 'IronCore'],
        ],
        'Sportswear' => [
            ['Dri-Fit Training Tee', 999, 'FlexFit'],
            ['Compression Shorts', 1199, 'FlexFit'],
        ],
        'Outdoor' => [
            ['Insulated Steel Water Bottle 1L', 1299, 'Trailhead'],
            ['4-Person Camping Tent', 7999, 'Trailhead'],
        ],
    ];

    public function run(): void
    {
        $taxClasses = $this->seedTaxClasses();
        $attributes = $this->seedAttributes();
        $categories = $this->seedCategories();
        $vendors = Vendor::approved()->get();

        $standardTax = $taxClasses['standard'];
        $reducedTax = $taxClasses['reduced'];

        $sizeAttribute = $attributes['size'];
        $colourAttribute = $attributes['colour'];

        foreach ($this->catalog as $categoryName => $items) {
            $category = $categories[$categoryName] ?? null;

            if (! $category) {
                continue;
            }

            foreach ($items as [$name, $price, $brand]) {
                $vendor = $vendors->isNotEmpty() && random_int(1, 100) <= 78 ? $vendors->random() : null;
                $isVariable = random_int(1, 100) <= 40;
                $cost = (int) round($price * (random_int(45, 68) / 100));
                $status = match (true) {
                    random_int(1, 100) <= 8 => 'draft',
                    random_int(1, 100) <= 4 => 'archived',
                    default => 'active',
                };

                $product = Product::create([
                    'vendor_id' => $vendor?->id,
                    'category_id' => $category->id,
                    'tax_class_id' => $price < 1000 ? $reducedTax->id : $standardTax->id,
                    'name' => $name,
                    'slug' => Product::uniqueSlug($name),
                    'sku' => strtoupper(Str::substr(Str::slug($brand), 0, 3)).'-'.strtoupper(Str::random(6)),
                    'type' => $isVariable ? 'variable' : 'simple',
                    'short_description' => "{$name} by {$brand}. Built for everyday use with a focus on quality and value.",
                    'description' => $this->description($name, $brand),
                    'price' => $price,
                    'compare_at_price' => random_int(1, 100) <= 45 ? (int) round($price * 1.25) : null,
                    'cost_price' => $cost,
                    'track_inventory' => true,
                    'stock_quantity' => random_int(0, 140),
                    'low_stock_threshold' => 8,
                    'weight' => round(random_int(120, 4200) / 1000, 3),
                    'requires_shipping' => true,
                    'status' => $status,
                    'is_featured' => random_int(1, 100) <= 15,
                    'brand' => $brand,
                    'tags' => collect([$brand, explode(' ', $categoryName)[0], 'bestseller', 'new-arrival'])
                        ->shuffle()
                        ->take(random_int(1, 3))
                        ->map(fn ($tag) => Str::slug($tag))
                        ->values()
                        ->all(),
                    'seo_title' => "{$name} – Buy Online",
                    'seo_description' => "Shop the {$name} from {$brand}. Fast delivery, easy returns.",
                    'rating' => round(random_int(35, 50) / 10, 2),
                    'views_count' => random_int(20, 4000),
                    'published_at' => $status === 'active' ? now()->subDays(random_int(1, 240)) : null,
                ]);

                $product->categories()->sync([$category->id, $category->parent_id]);

                foreach (range(1, random_int(1, 3)) as $index) {
                    $product->images()->create([
                        'path' => 'https://picsum.photos/seed/'.Str::slug($name).'-'.$index.'/600/600',
                        'alt' => $name,
                        'position' => $index - 1,
                    ]);
                }

                if ($isVariable) {
                    $this->seedVariants($product, $sizeAttribute, $colourAttribute);
                }
            }
        }
    }

    /**
     * @return array<string, TaxClass>
     */
    protected function seedTaxClasses(): array
    {
        $standard = TaxClass::create([
            'name' => 'Standard GST 18%',
            'slug' => 'standard-gst-18',
            'description' => 'Applies to most goods sold on the marketplace.',
            'is_default' => true,
            'is_active' => true,
        ]);

        $standard->rates()->createMany([
            ['name' => 'CGST 9%', 'country' => 'IN', 'state' => 'Uttar Pradesh', 'rate' => 9, 'priority' => 1, 'is_active' => true],
            ['name' => 'SGST 9%', 'country' => 'IN', 'state' => 'Uttar Pradesh', 'rate' => 9, 'priority' => 2, 'is_active' => true],
            ['name' => 'IGST 18%', 'country' => 'IN', 'state' => null, 'rate' => 18, 'priority' => 1, 'applies_to_shipping' => true, 'is_active' => true],
        ]);

        $reduced = TaxClass::create([
            'name' => 'Reduced GST 5%',
            'slug' => 'reduced-gst-5',
            'description' => 'Essentials and low-value items.',
            'is_active' => true,
        ]);

        $reduced->rates()->createMany([
            ['name' => 'CGST 2.5%', 'country' => 'IN', 'state' => 'Uttar Pradesh', 'rate' => 2.5, 'priority' => 1, 'is_active' => true],
            ['name' => 'SGST 2.5%', 'country' => 'IN', 'state' => 'Uttar Pradesh', 'rate' => 2.5, 'priority' => 2, 'is_active' => true],
            ['name' => 'IGST 5%', 'country' => 'IN', 'state' => null, 'rate' => 5, 'priority' => 1, 'is_active' => true],
        ]);

        $zero = TaxClass::create([
            'name' => 'Zero rated',
            'slug' => 'zero-rated',
            'description' => 'Exempt goods.',
            'is_active' => true,
        ]);

        return ['standard' => $standard, 'reduced' => $reduced, 'zero' => $zero];
    }

    /**
     * @return array<string, Attribute>
     */
    protected function seedAttributes(): array
    {
        $size = Attribute::create(['name' => 'Size', 'slug' => 'size', 'type' => 'button', 'position' => 1]);
        $size->values()->createMany(
            collect(['XS', 'S', 'M', 'L', 'XL', 'XXL'])
                ->map(fn ($value, $index) => ['value' => $value, 'slug' => Str::slug($value), 'position' => $index])
                ->all(),
        );

        $colour = Attribute::create(['name' => 'Colour', 'slug' => 'colour', 'type' => 'swatch', 'position' => 2]);
        $colour->values()->createMany(
            collect([
                ['Black', '#111111'],
                ['White', '#f5f5f5'],
                ['Navy', '#1f2f57'],
                ['Olive', '#606c38'],
                ['Maroon', '#7b2d26'],
                ['Beige', '#d9c8b4'],
            ])->map(fn ($pair, $index) => [
                'value' => $pair[0],
                'slug' => Str::slug($pair[0]),
                'color_hex' => $pair[1],
                'position' => $index,
            ])->all(),
        );

        $material = Attribute::create(['name' => 'Material', 'slug' => 'material', 'type' => 'dropdown', 'position' => 3]);
        $material->values()->createMany(
            collect(['Cotton', 'Polyester', 'Leather', 'Stainless Steel', 'Bamboo'])
                ->map(fn ($value, $index) => ['value' => $value, 'slug' => Str::slug($value), 'position' => $index])
                ->all(),
        );

        $storage = Attribute::create(['name' => 'Storage', 'slug' => 'storage', 'type' => 'dropdown', 'position' => 4]);
        $storage->values()->createMany(
            collect(['64GB', '128GB', '256GB', '512GB'])
                ->map(fn ($value, $index) => ['value' => $value, 'slug' => Str::slug($value), 'position' => $index])
                ->all(),
        );

        return ['size' => $size, 'colour' => $colour, 'material' => $material, 'storage' => $storage];
    }

    /**
     * @return array<string, Category>
     */
    protected function seedCategories(): array
    {
        $categories = [];
        $position = 0;

        foreach ($this->tree as $parentName => $children) {
            $parent = Category::create([
                'name' => $parentName,
                'slug' => Category::uniqueSlug($parentName),
                'description' => "Everything under {$parentName}, curated across our marketplace vendors.",
                'image_path' => 'https://picsum.photos/seed/'.Str::slug($parentName).'/400/300',
                'position' => $position++,
                'is_active' => true,
                'is_featured' => true,
                'seo_title' => "{$parentName} Online",
                'seo_description' => "Shop {$parentName} at the best prices.",
            ]);

            $categories[$parentName] = $parent;
            $childPosition = 0;

            foreach ($children as $childName) {
                $categories[$childName] = Category::create([
                    'parent_id' => $parent->id,
                    'name' => $childName,
                    'slug' => Category::uniqueSlug($childName),
                    'description' => "Browse our {$childName} range.",
                    'image_path' => 'https://picsum.photos/seed/'.Str::slug($childName).'/400/300',
                    'position' => $childPosition++,
                    'is_active' => true,
                ]);
            }
        }

        return $categories;
    }

    protected function seedVariants(Product $product, Attribute $size, Attribute $colour): void
    {
        $product->attributes()->sync([$size->id, $colour->id]);

        $sizes = $size->values()->inRandomOrder()->limit(random_int(2, 4))->get();
        $colours = $colour->values()->inRandomOrder()->limit(random_int(2, 3))->get();
        $position = 0;
        $total = 0;

        foreach ($sizes as $sizeValue) {
            foreach ($colours as $colourValue) {
                $stock = random_int(0, 35);
                $total += $stock;

                $variant = $product->variants()->create([
                    'name' => "{$sizeValue->value} / {$colourValue->value}",
                    'sku' => $product->sku.'-'.Str::upper(Str::substr($sizeValue->value, 0, 2)).Str::upper(Str::substr($colourValue->value, 0, 2)),
                    'price' => (float) $product->price + random_int(0, 4) * 50,
                    'compare_at_price' => $product->compare_at_price,
                    'cost_price' => $product->cost_price,
                    'stock_quantity' => $stock,
                    'weight' => $product->weight,
                    'position' => $position++,
                    'is_active' => true,
                ]);

                $variant->values()->createMany([
                    ['attribute_id' => $size->id, 'attribute_value_id' => $sizeValue->id],
                    ['attribute_id' => $colour->id, 'attribute_value_id' => $colourValue->id],
                ]);
            }
        }

        $product->update(['stock_quantity' => $total]);
    }

    protected function description(string $name, string $brand): string
    {
        return <<<HTML
        <p>The <strong>{$name}</strong> from {$brand} is built around the things people actually notice — how it feels in daily use, how it holds up after a few months, and whether it still looks good.</p>
        <ul>
          <li>Quality-checked before dispatch</li>
          <li>7-day easy returns</li>
          <li>1-year manufacturer warranty where applicable</li>
        </ul>
        <p>Ships from our partner warehouse. Delivery estimates are shown at checkout based on your PIN code.</p>
        HTML;
    }
}
