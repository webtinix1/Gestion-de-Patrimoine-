<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * E08 · RG-05 : un numéro saisi ou lu par une douchette USB (clavier + Entrée) ouvre la fiche.
 * Un code inconnu affiche « Aucun bien avec ce code » et propose de créer la fiche.
 *
 * @property-read Schema $form
 */
class SearchByCode extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    public const string HISTORY_KEY = 'recherche_par_code.historique';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Patrimoine';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Recherche par code';

    protected static ?string $title = 'Recherche par code';

    protected static ?string $slug = 'recherche-par-code';

    protected string $view = 'filament.pages.search-by-code';

    /** @var array<string, mixed> */
    public array $data = [];

    public ?string $unknownCode = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Asset::class) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('code')
                    ->label('Placez le curseur ici puis scannez le QR code ou le code-barres de l\'étiquette.')
                    ->placeholder('PAT-000001')
                    ->autofocus()
                    ->autocomplete(false)
                    ->required()
                    ->extraInputAttributes(['class' => 'pat-mono text-2xl font-extrabold tracking-wide']),
            ]);
    }

    public function search(): void
    {
        $code = mb_strtoupper(trim((string) ($this->form->getState()['code'] ?? '')));
        $asset = Asset::query()->where('number', $code)->first();

        $this->remember($code, $asset);
        $this->form->fill();

        if ($asset === null) {
            $this->unknownCode = $code;

            return;
        }

        $this->unknownCode = null;
        $this->redirect(AssetResource::getUrl('view', ['record' => $asset]));
    }

    /**
     * Derniers codes lus sur ce poste (session du navigateur).
     *
     * @return list<array{time: string, code: string, found: bool, label: string|null, disposed: bool}>
     */
    public function getHistory(): array
    {
        /** @var list<array{time: string, code: string, found: bool, label: string|null, disposed: bool}> $history */
        $history = session(self::HISTORY_KEY, []);

        return $history;
    }

    public function canCreateAsset(): bool
    {
        return auth()->user()?->can('create', Asset::class) ?? false;
    }

    private function remember(string $code, ?Asset $asset): void
    {
        $history = $this->getHistory();

        array_unshift($history, [
            'time' => now()->format('H:i'),
            'code' => $code,
            'found' => $asset !== null,
            'label' => $asset?->label(),
            'disposed' => $asset?->isDisposed() ?? false,
        ]);

        session([self::HISTORY_KEY => array_slice($history, 0, 10)]);
    }
}
