<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique l'enregistrement seulement : le fichier n'est pas écrit sur le disque.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_type_id' => DocumentType::factory(),
            'original_name' => fake()->word().'.pdf',
            'path' => 'documents/'.fake()->unique()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(10, 900) * 1024,
        ];
    }
}
