# Licences des bibliothèques tierces

Ce fichier liste les bibliothèques livrées avec l'application dont la licence n'est pas MIT. Les autres (Laravel, Filament, Livewire, DomPDF sous LGPL est signalé ci-dessous, etc.) gardent leur licence d'origine dans leur dossier `vendor/<paquet>/`.

## picqer/php-barcode-generator (génération des codes-barres Code 128)

- Version : 3.3.0
- Licence : GNU Lesser General Public License, version 3 ou ultérieure (LGPL-3.0-or-later)
- Copyright : Casper Bakker (Picqer) ; code de base de Nicola Asuni (Tecnick.com)
- Texte de la licence : `vendor/picqer/php-barcode-generator/LICENSE.md`
- Source : https://github.com/picqer/php-barcode-generator

La bibliothèque est utilisée telle quelle, sans modification, et reste un paquet distinct que l'on peut remplacer par une autre version dans `vendor/` (`composer require picqer/php-barcode-generator:<version>`). Cette possibilité de remplacement doit être conservée pour respecter la LGPL.

## bacon/bacon-qr-code et dasprid/enum (dépendances du générateur de QR codes)

- Licence : BSD 2-Clause. Textes dans `vendor/bacon/bacon-qr-code/LICENSE` et `vendor/dasprid/enum/LICENSE`.

## dompdf/dompdf (PDF)

- Licence : LGPL-2.1. Texte dans `vendor/dompdf/dompdf/LICENSE.LGPL`. Mêmes conditions que ci-dessus (paquet non modifié, remplaçable).

## Polices

- DejaVu Sans et DejaVu Sans Mono (fournies avec DomPDF) : licence Bitstream Vera / domaine public pour les modifications DejaVu.
