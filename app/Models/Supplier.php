<?php

namespace App\Models;

use App\Models\Concerns\IsReferential;
use App\Models\Concerns\RecordsAuthors;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $address
 * @property bool $is_active
 */
#[Fillable(['name', 'phone', 'address', 'is_active'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory, IsReferential, RecordsAuthors;

    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function usageRelations(): array
    {
        return [];
    }
}
