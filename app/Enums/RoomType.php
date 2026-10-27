<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RoomType: string implements HasColor, HasLabel
{
    case Office = 'office';
    case WorshipHall = 'worship_hall';
    case MeetingRoom = 'meeting_room';
    case ComputerRoom = 'computer_room';
    case Storeroom = 'storeroom';
    case TrainingRoom = 'training_room';
    case TechnicalRoom = 'technical_room';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Office => 'Bureau',
            self::WorshipHall => 'Salle de culte',
            self::MeetingRoom => 'Salle de réunion',
            self::ComputerRoom => 'Salle informatique',
            self::Storeroom => 'Magasin',
            self::TrainingRoom => 'Salle de formation',
            self::TechnicalRoom => 'Local technique',
            self::Other => 'Autre',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Office => 'gray',
            self::WorshipHall => 'gray',
            self::MeetingRoom => 'gray',
            self::ComputerRoom => 'gray',
            self::Storeroom => 'gray',
            self::TrainingRoom => 'gray',
            self::TechnicalRoom => 'gray',
            self::Other => 'gray',
        };
    }
}
