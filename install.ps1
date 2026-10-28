<#
.SYNOPSIS
    Installation de l'application Patrimoine sur le serveur local Windows (WAMP). Relançable sans dégât.

.DESCRIPTION
    - cherche PHP (8.3 ou plus) et contrôle les extensions, la base et les dossiers ;
    - crée le fichier .env à partir de .env.example s'il n'existe pas (un .env existant n'est jamais modifié),
      avec une clé d'application ;
    - compile l'interface si public\build est absent (Node 20.19+ ou 22+ requis ; le paquet livré la contient) ;
    - lance les migrations, charge les référentiels de base et crée le premier administrateur
      (mot de passe saisi sans écho, jamais écrit ni affiché) ;
    - met en cache la configuration (php artisan optimize).
    Avec -Demo, charge aussi la démonstration (comptes à mot de passe connu : à réserver aux essais).

.EXAMPLE
    .\install.ps1
    .\install.ps1 -Url http://patrimoine.local -DbNom patrimoine -DbUtilisateur patrimoine
    .\install.ps1 -Demo -Base sqlite        # essai rapide, sans MySQL
    .\install.ps1 -TachePlanifiee           # enregistre aussi la tâche Windows schedule:run (droits administrateur)
#>
[CmdletBinding()]
param(
    [switch]$Demo,
    [string]$Php,
    [ValidateSet('mysql', 'mariadb', 'sqlite')]
    [string]$Base = 'mysql',
    [string]$DbHote = '127.0.0.1',
    [int]$DbPort = 3306,
    [string]$DbNom = 'patrimoine',
    [string]$DbUtilisateur = 'root',
    [string]$Url = 'http://patrimoine.local',
    [switch]$SansFront,
    [switch]$TachePlanifiee,
    [switch]$MotDePasseStdin,
    [string]$Identifiant,
    [string]$Prenom,
    [string]$Nom
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot

function Fail([string]$Message) {
    Write-Host "ERREUR : $Message" -ForegroundColor Red
    exit 1
}

function Step([string]$Message) {
    Write-Host ""
    Write-Host "== $Message" -ForegroundColor Cyan
}

function Set-EnvValue([string]$Path, [string]$Key, [string]$Value) {
    $lines = [System.IO.File]::ReadAllLines($Path)
    $found = $false
    for ($i = 0; $i -lt $lines.Length; $i++) {
        if ($lines[$i] -match "^\s*#?\s*$([regex]::Escape($Key))=") {
            $lines[$i] = "$Key=$Value"
            $found = $true
            break
        }
    }
    if (-not $found) { $lines += "$Key=$Value" }
    [System.IO.File]::WriteAllLines($Path, $lines, (New-Object System.Text.UTF8Encoding($false)))
}

function Get-EnvValue([string]$Path, [string]$Key) {
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -match "^\s*$([regex]::Escape($Key))=(.*)$") { return $Matches[1].Trim().Trim('"') }
    }
    return ''
}

function Quote-EnvValue([string]$Value) {
    return '"' + ($Value -replace '\\', '\\' -replace '"', '\"' -replace '\$', '\$') + '"'
}

# 1. PHP -----------------------------------------------------------------------------------------------
Step 'PHP'
$phpExe = $null
if ($Php) {
    if (-not (Test-Path -LiteralPath $Php)) { Fail "PHP introuvable : $Php" }
    $phpExe = (Resolve-Path -LiteralPath $Php).Path
} else {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $phpExe = $cmd.Source }
    if (-not $phpExe) {
        $wamp = Get-ChildItem 'C:\wamp64\bin\php\php*\php.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1
        if ($wamp) { $phpExe = $wamp.FullName }
    }
}
if (-not $phpExe) { Fail "PHP introuvable. Installez WAMP (PHP 8.3 ou plus) ou passez -Php C:\wamp64\bin\php\php8.3.x\php.exe" }

$phpVersion = (& $phpExe -r 'echo PHP_VERSION;')
if ([version]($phpVersion -replace '^(\d+\.\d+\.\d+).*$', '$1') -lt [version]'8.3.0') {
    Fail "PHP $phpVersion est trop ancien : la version 8.3 ou plus est exigée (WAMP : Menu PHP > Version)."
}
Write-Host "PHP $phpVersion : $phpExe"

# 2. Paquet ---------------------------------------------------------------------------------------------
Step 'Paquet'
if (-not (Test-Path -LiteralPath 'vendor\autoload.php')) {
    $composer = Get-Command composer -ErrorAction SilentlyContinue
    if (-not $composer) { Fail "Le dossier vendor est absent : le paquet livré est incomplet (ou installez Composer sur une machine connectée)." }
    & $phpExe $composer.Source install --no-dev --optimize-autoloader -n --no-scripts
    if ($LASTEXITCODE -ne 0) { Fail 'composer install a échoué.' }
    & $phpExe artisan package:discover
}
Write-Host 'vendor présent.'

# 3. .env -----------------------------------------------------------------------------------------------
Step 'Fichier .env'
if (Test-Path -LiteralPath '.env') {
    Write-Host '.env existe : conservé tel quel (supprimez-le seulement si vous voulez repartir de zéro).'
} else {
    if (-not (Test-Path -LiteralPath '.env.example')) { Fail '.env.example est introuvable.' }
    Copy-Item -LiteralPath '.env.example' -Destination '.env'
    Set-EnvValue '.env' 'APP_ENV' 'production'
    Set-EnvValue '.env' 'APP_DEBUG' 'false'
    Set-EnvValue '.env' 'APP_URL' (Quote-EnvValue $Url)
    Set-EnvValue '.env' 'LOG_LEVEL' 'warning'
    if ($Base -eq 'sqlite') {
        Set-EnvValue '.env' 'DB_CONNECTION' 'sqlite'
        Set-EnvValue '.env' 'DB_DATABASE' (Quote-EnvValue (Join-Path $PSScriptRoot 'database\database.sqlite'))
    } else {
        Set-EnvValue '.env' 'DB_CONNECTION' $Base
        Set-EnvValue '.env' 'DB_HOST' $DbHote
        Set-EnvValue '.env' 'DB_PORT' "$DbPort"
        Set-EnvValue '.env' 'DB_DATABASE' $DbNom
        Set-EnvValue '.env' 'DB_USERNAME' (Quote-EnvValue $DbUtilisateur)
        $secure = Read-Host "Mot de passe MySQL de l'utilisateur '$DbUtilisateur' (vide si aucun ; non affiché)" -AsSecureString
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        try { $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
        Set-EnvValue '.env' 'DB_PASSWORD' (Quote-EnvValue $plain)
        $plain = $null
    }
    Write-Host '.env créé à partir de .env.example (production, débogage désactivé).'
}

if (-not (Get-EnvValue '.env' 'APP_KEY')) {
    & $phpExe artisan key:generate --force
    if ($LASTEXITCODE -ne 0) { Fail 'key:generate a échoué.' }
}

# 4. Interface ------------------------------------------------------------------------------------------
Step 'Interface (public\build)'
if (Test-Path -LiteralPath 'public\build\manifest.json') {
    Write-Host 'Interface déjà compilée.'
} elseif ($SansFront) {
    Write-Host 'Compilation ignorée (-SansFront) : public\build reste absent.'
} else {
    $npm = Get-Command npm -ErrorAction SilentlyContinue
    if (-not $npm) { Fail "public\build est absent et Node.js n'est pas installé. Utilisez le paquet livré (qui contient public\build) ou installez Node 20.19+ / 22+ puis relancez." }
    & npm ci
    if ($LASTEXITCODE -ne 0) { Fail 'npm ci a échoué (Internet est nécessaire pour cette étape sur une machine sans paquet compilé).' }
    & npm run build
    if ($LASTEXITCODE -ne 0) { Fail "npm run build a échoué (Node 20.19+ ou 22+ requis ; npm 10 de Node 20 est connu pour être instable : préférez Node 22 ou 24)." }
}

# 5. Application ----------------------------------------------------------------------------------------
Step 'Installation de l''application'
$arguments = @('artisan', 'patrimoine:installer')
if ($Demo) { $arguments += '--demo' }
if ($MotDePasseStdin) { $arguments += '--mot-de-passe-stdin' }
if ($Identifiant) { $arguments += "--identifiant=$Identifiant" }
if ($Prenom) { $arguments += "--prenom=$Prenom" }
if ($Nom) { $arguments += "--nom=$Nom" }
& $phpExe @arguments
if ($LASTEXITCODE -ne 0) { Fail "patrimoine:installer a échoué (code $LASTEXITCODE). Corrigez le problème indiqué puis relancez install.ps1." }

Step 'Cache de configuration'
& $phpExe artisan optimize
if ($LASTEXITCODE -ne 0) { Fail 'php artisan optimize a échoué.' }

# 6. Tâche planifiée ------------------------------------------------------------------------------------
$taskCommand = "`"$phpExe`" `"$PSScriptRoot\artisan`" schedule:run"
if ($TachePlanifiee) {
    Step 'Tâche planifiée Windows'
    schtasks /Create /TN 'Patrimoine - planificateur' /TR $taskCommand /SC MINUTE /MO 1 /RU SYSTEM /F | Out-Null
    if ($LASTEXITCODE -ne 0) { Fail "Création de la tâche refusée : relancez PowerShell en administrateur, ou créez-la à la main (docs\07-deploiement-local.md)." }
    Write-Host 'Tâche « Patrimoine - planificateur » enregistrée (chaque minute).'
}

Step 'Terminé'
Write-Host "Application installée. Adresse : $(Get-EnvValue '.env' 'APP_URL')"
if (-not $TachePlanifiee) {
    Write-Host "Planificateur Windows (à créer une fois) : relancez avec -TachePlanifiee, ou voir docs\07-deploiement-local.md."
}
Write-Host 'Contrôle à tout moment : php artisan patrimoine:verifier'
