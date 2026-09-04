<?php

namespace App\Models;

use Database\Factories\LegalPageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Terms, privacy, returns, licences, grievance officer.
 *
 * The five slugs are fixed because the app links to them by name; the words,
 * and when they last changed, belong to the marketplace.
 */
class LegalPage extends Model
{
    /** @use HasFactory<LegalPageFactory> */
    use HasFactory;

    /**
     * The pages the app expects to find, and what each is called until an
     * admin renames it.
     *
     * @var array<string, string>
     */
    public const PAGES = [
        'terms' => 'Terms of use',
        'privacy' => 'Privacy policy',
        'returns' => 'Returns and refunds',
        'licenses' => 'Open-source licences',
        'grievance-officer' => 'Grievance officer',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    /**
     * Published, and with something on it — a page whose body nobody has
     * written yet is not a page, it is a blank screen with a title.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeReadable(Builder $query): Builder
    {
        return $query->where('is_published', true)->whereNotNull('body');
    }
}
