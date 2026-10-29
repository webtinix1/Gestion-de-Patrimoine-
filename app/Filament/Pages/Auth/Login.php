<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $username = $this->data['username'] ?? null;

        // RG-85 : un compte bloqué après 5 échecs refuse toute tentative pendant 15 minutes. Même message
        // qu'un échec ordinaire : la réponse ne révèle ni l'existence du compte ni son blocage.
        $user = is_string($username) ? User::query()->where('username', $username)->first() : null;

        if ($user?->isLocked()) {
            $this->throwFailureValidationException();
        }

        return parent::authenticate();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                // RG-85 : pas de « rester connecté », qui contournerait la déconnexion après inactivité.
                Text::make('Mot de passe oublié ou compte bloqué : contactez l\'administrateur de l\'application, qui peut réinitialiser votre accès.')
                    ->color('gray'),
            ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('username')
            ->label(__('Identifiant'))
            ->required()
            ->maxLength(255)
            ->autocomplete('username')
            ->autofocus();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => __('auth.failed'),
        ]);
    }
}
