<?php

namespace App\Services;

/**
 * A glyph for anything that has no photograph yet.
 *
 * The shopper app draws a tile for every product, category, basket line and
 * order line. Where a real image exists that is what it draws; where one does
 * not, it still needs *something*, and the prototype answered that with a
 * hand-written emoji per row. Copying that table into the app would have meant
 * the app inventing content — so it lives here, keyed off the words the
 * marketplace already stores.
 *
 * Order matters: the map is walked top to bottom and the first needle found in
 * the haystack wins, so the specific spellings ("bedsheet") sit above the
 * general ones ("bed").
 *
 * Matching starts at a word boundary but does not end at one — "shawls" and
 * "sarees" are still a shawl and a saree, while a "laptop" is not a top and a
 * "smartphone" is not art.
 */
class Emoji
{
    public const FALLBACK = '🛍️';

    /**
     * @var array<string, string>
     */
    private const MAP = [
        'saree' => '🥻',
        'sari' => '🥻',
        'lehenga' => '🥻',
        'ethnic' => '🥻',
        'dupatta' => '🧣',
        'stole' => '🧣',
        'scarf' => '🧣',
        'shawl' => '🧶',
        'wool' => '🧶',
        'winter' => '🧶',
        'sweater' => '🧶',
        'jacket' => '🧥',
        'coat' => '🧥',
        'kurta' => '👕',
        'shirt' => '👕',
        'tee' => '👕',
        't-shirt' => '👕',
        'dress' => '👗',
        'frock' => '👗',
        'gown' => '👗',
        'jeans' => '👖',
        'trouser' => '👖',
        'pyjama' => '👖',
        'pant' => '👖',
        'bedsheet' => '🛏️',
        'bed' => '🛏️',
        'linen' => '🛏️',
        'pillow' => '🛏️',
        'quilt' => '🛏️',
        'blanket' => '🛏️',
        'curtain' => '🪟',
        'towel' => '🧻',
        'sneaker' => '👟',
        'shoe' => '👟',
        'footwear' => '👟',
        'sandal' => '🥿',
        'slipper' => '🥿',
        'flat' => '🥿',
        'heel' => '👠',
        // After the footwear, so "high-top sneakers" is a shoe.
        'top' => '👕',
        'bag' => '👜',
        'purse' => '👜',
        'wallet' => '👛',
        'watch' => '⌚',
        'jewel' => '💍',
        'ring' => '💍',
        'necklace' => '📿',
        'earring' => '📿',
        'bangle' => '📿',
        'kitchen' => '🍳',
        'cookware' => '🍳',
        'pan' => '🍳',
        'crockery' => '🍽️',
        'plate' => '🍽️',
        'cup' => '☕',
        'mug' => '☕',
        'tea' => '🍵',
        'coffee' => '☕',
        'spice' => '🌶️',
        'masala' => '🌶️',
        'lamp' => '💡',
        'light' => '💡',
        'candle' => '🕯️',
        'pottery' => '🏺',
        'vase' => '🏺',
        'painting' => '🖼️',
        'art' => '🖼️',
        'decor' => '🖼️',
        'rug' => '🧵',
        'carpet' => '🧵',
        'fabric' => '🧵',
        'cotton' => '🧵',
        'silk' => '🧵',
        'plant' => '🪴',
        'book' => '📚',
        'toy' => '🧸',
        'baby' => '🧸',
        'smartphone' => '📱',
        'phone' => '📱',
        'mobile' => '📱',
        'laptop' => '💻',
        'headphone' => '🎧',
        'earbud' => '🎧',
        'speaker' => '🔊',
        'beauty' => '💄',
        'lipstick' => '💄',
        'makeup' => '💄',
        'cream' => '🧴',
        'perfume' => '🧴',
        'oil' => '🧴',
        'soap' => '🧼',
        'sport' => '🏏',
        'yoga' => '🧘',
        'grocery' => '🛒',
        'snack' => '🍪',
    ];

    /**
     * The glyph for a category, given whatever the admin chose and its name.
     */
    public static function forCategory(?string $icon, ?string $name = null): string
    {
        return self::clean($icon) ?? self::match($name) ?? self::FALLBACK;
    }

    /**
     * The glyph for a product.
     *
     * Its own name is tried first — "Kashmiri wool shawl" is a shawl whatever
     * shelf it sits on — and the category is the fallback, which is what makes
     * a sparsely-named product still land somewhere sensible.
     */
    public static function forProduct(?string $name, ?string $categoryIcon = null, ?string $categoryName = null): string
    {
        return self::match($name)
            ?? self::clean($categoryIcon)
            ?? self::match($categoryName)
            ?? self::FALLBACK;
    }

    /**
     * First needle found in the haystack, or null when nothing matches.
     */
    private static function match(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $haystack = mb_strtolower($text);

        foreach (self::MAP as $needle => $emoji) {
            if (preg_match('/\b'.preg_quote($needle, '/').'/u', $haystack) === 1) {
                return $emoji;
            }
        }

        return null;
    }

    /**
     * An admin-set glyph, or null when the column is empty. Whitespace counts
     * as empty: a stray space is not an icon.
     */
    private static function clean(?string $icon): ?string
    {
        $icon = trim((string) $icon);

        return $icon !== '' ? $icon : null;
    }
}
