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
    - met en cache la configuration (php artisan optimize). Après toute modification ultérieure de .env,
      relancer : php artisan optimize
    Avec -Demo, charge aussi la démonstration (comptes à mot de passe connu : à réserver aux essais, base vide) ;
    un .env neuf est alors écrit en APP_ENV=local.

    Entrée redirigée (-MotDePasseStdin) : le script lit tout l'entrée standard, une valeur par ligne, dans cet ordre :
      1. le mot de passe de la base (seulement si .env est créé et que la base n'est pas SQLite) ;
      2. le mot de passe du premier administrateur (seulement si aucun administrateur n'existe encore).
    Les caractères accentués d'un mot de passe peuvent être altérés par le tube de Windows PowerShell 5.1 : les éviter.

    Sécurité (hors -Demo) : un mot de passe MySQL vide est refusé ; le compte root déclenche un avertissement (créer un
    utilisateur dédié, voir docs-deploiement-local.md). Quand la tâche planifiée est installée, les droits NTFS du dossier
    sont durcis (SYSTEM et Administrateurs : contrôle total ; Utilisateurs : lecture ; storage, bootstrap\cache et .env réservés
    à SYSTEM, aux Administrateurs et au compte -CompteApache). Une règle de pare-feu entrante (profil Privé) limitée à
    -ReseauAutorise est créée. Une URL en https écrit SESSION_SECURE_COOKIE=true.

.EXAMPLE
    .\install.ps1
    .\install.ps1 -Url http://patrimoine.local -DbNom patrimoine -DbUtilisateur patrimoine
    .\install.ps1 -Demo -Base sqlite        # essai rapide, sans MySQL
    .\install.ps1 -SansTachePlanifiee       # n'enregistre pas la tâche Windows schedule:run (installée d'office, sauf avec -Demo)
    .\install.ps1 -ReseauAutorise 192.168.1.0/24 -CompteApache 'NT AUTHORITY\LOCAL SERVICE'
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
    [switch]$SansTachePlanifiee,
    [switch]$MotDePasseStdin,
    # Réseau autorisé par la règle de pare-feu (LocalSubnet, ou une plage comme 192.168.1.0/24).
    [string]$ReseauAutorise = 'LocalSubnet',
    # Compte qui fait tourner Apache (le service WAMP tourne en SYSTEM par défaut) : Modifier sur storage et bootstrap\cache.
    [string]$CompteApache = 'SYSTEM',
    [switch]$PareFeu,
    [switch]$SansPareFeu,
    [switch]$SansDurcissement,
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

# Version de PHP d'un exécutable, ou $null s'il ne répond pas.
function Get-PhpVersion([string]$Exe) {
    try {
        $v = (& $Exe -r 'echo PHP_VERSION;') -replace '^(\d+\.\d+\.\d+).*$', '$1'
        return [version]$v
    } catch { return $null }
}

# 1. PHP -----------------------------------------------------------------------------------------------
Step 'PHP'
$minimum = [version]'8.3.0'
$candidates = @()
if ($Php) {
    if (-not (Test-Path -LiteralPath $Php)) { Fail "PHP introuvable : $Php" }
    $candidates += (Resolve-Path -LiteralPath $Php).Path
} else {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $candidates += $cmd.Source }
    # WAMP : tri par numéro de version (et non par ordre alphabétique : 8.10 > 8.4).
    $wamp = Get-ChildItem 'C:\wamp64\bin\php\php*\php.exe' -ErrorAction SilentlyContinue |
        Sort-Object { try { [version]($_.Directory.Name -replace '^php', '') } catch { [version]'0.0' } } -Descending
    foreach ($w in $wamp) { if ($candidates -notcontains $w.FullName) { $candidates += $w.FullName } }
}

$phpExe = $null
$phpVersion = $null
foreach ($candidate in $candidates) {
    $version = Get-PhpVersion $candidate
    if ($version -and $version -ge $minimum) { $phpExe = $candidate; $phpVersion = $version; break }
    if ($version) { Write-Host "Ignoré : PHP $version ($candidate) est inférieur à 8.3." -ForegroundColor Yellow }
}
if (-not $phpExe) {
    Fail "Aucun PHP 8.3 ou plus trouvé. Installez-le dans WAMP, ou indiquez-le : .\install.ps1 -Php C:\wamp64\bin\php\php8.3.x\php.exe"
}
Write-Host "PHP $phpVersion : $phpExe"

# Entrée redirigée : toutes les lignes sont lues une fois (une valeur par ligne), voir l'aide du script.
$stdinLines = @()
if ($MotDePasseStdin -or [Console]::IsInputRedirected) {
    $stdinLines = @([Console]::In.ReadToEnd() -split "`r?`n")
}
$stdinIndex = 0
function Read-StdinLine {
    if ($script:stdinIndex -lt $stdinLines.Count) { $line = $stdinLines[$script:stdinIndex]; $script:stdinIndex++; return $line }
    return ''
}

# 2. Paquet ---------------------------------------------------------------------------------------------
Step 'Paquet'
if (-not (Test-Path -LiteralPath 'vendor\autoload.php')) {
    $composer = Get-Command composer -ErrorAction SilentlyContinue
    if (-not $composer) { Fail "Le dossier vendor est absent : le paquet livré est incomplet (ou installez Composer sur une machine connectée)." }
    if ($composer.Source -match '\.(bat|cmd|exe)$') {
        & $composer.Source install --no-dev --optimize-autoloader -n --no-scripts
    } else {
        & $phpExe $composer.Source install --no-dev --optimize-autoloader -n --no-scripts
    }
    if ($LASTEXITCODE -ne 0) { Fail 'composer install a échoué.' }
    & $phpExe artisan package:discover
    if ($LASTEXITCODE -ne 0) { Fail 'php artisan package:discover a échoué.' }
}
Write-Host 'vendor présent.'

# 3. .env -----------------------------------------------------------------------------------------------
Step 'Fichier .env'
if (Test-Path -LiteralPath '.env') {
    Write-Host '.env existe : conservé tel quel (supprimez-le seulement si vous voulez repartir de zéro).'
} else {
    if (-not (Test-Path -LiteralPath '.env.example')) { Fail '.env.example est introuvable.' }
    Copy-Item -LiteralPath '.env.example' -Destination '.env'
    # -Demo : la démonstration est refusée en production ; un .env neuf d'essai est donc en local.
    $envName = if ($Demo) { 'local' } else { 'production' }
    Set-EnvValue '.env' 'APP_ENV' $envName
    Set-EnvValue '.env' 'APP_DEBUG' 'false'
    Set-EnvValue '.env' 'APP_URL' (Quote-EnvValue $Url)
    Set-EnvValue '.env' 'LOG_LEVEL' 'warning'
    if ($Url -match '^https://') { Set-EnvValue '.env' 'SESSION_SECURE_COOKIE' 'true' }
    if ($Base -eq 'sqlite') {
        Set-EnvValue '.env' 'DB_CONNECTION' 'sqlite'
        Set-EnvValue '.env' 'DB_DATABASE' (Quote-EnvValue (Join-Path $PSScriptRoot 'database\database.sqlite'))
    } else {
        Set-EnvValue '.env' 'DB_CONNECTION' $Base
        Set-EnvValue '.env' 'DB_HOST' $DbHote
        Set-EnvValue '.env' 'DB_PORT' "$DbPort"
        Set-EnvValue '.env' 'DB_DATABASE' $DbNom
        Set-EnvValue '.env' 'DB_USERNAME' (Quote-EnvValue $DbUtilisateur)
        if (-not $Demo -and $DbUtilisateur -eq 'root') {
            Write-Warning "Le compte MySQL 'root' est déconseillé en service réel : créez un utilisateur dédié limité à la base (docs-deploiement-local.md), puis relancez avec -DbUtilisateur."
        }
        if ([Console]::IsInputRedirected) {
            $plain = Read-StdinLine
        } else {
            $secure = Read-Host "Mot de passe MySQL de l'utilisateur '$DbUtilisateur' (non affiché ; vide refusé hors -Demo)" -AsSecureString
            $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
            try { $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
        }
        if (-not $Demo -and [string]::IsNullOrEmpty($plain)) {
            # Le .env partiel est retiré pour qu'une relance reparte de zéro.
            Remove-Item -LiteralPath '.env' -Force
            Fail "Un mot de passe MySQL vide est refusé hors -Demo. Définissez un mot de passe sur le compte '$DbUtilisateur' (voir docs-deploiement-local.md), puis relancez."
        }
        Set-EnvValue '.env' 'DB_PASSWORD' (Quote-EnvValue $plain)
        $plain = $null
    }
    Write-Host ".env créé à partir de .env.example (APP_ENV=$envName, débogage désactivé)."
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
if ($MotDePasseStdin) {
    # Le mot de passe de l'administrateur est transmis à PHP sur son entrée standard (jamais en argument).
    Read-StdinLine | & $phpExe @arguments
} else {
    & $phpExe @arguments
}
if ($LASTEXITCODE -ne 0) { Fail "patrimoine:installer a échoué (code $LASTEXITCODE). Corrigez le problème indiqué puis relancez install.ps1." }

Step 'Cache de configuration'
& $phpExe artisan optimize
if ($LASTEXITCODE -ne 0) { Fail 'php artisan optimize a échoué.' }

# 6. Tâche planifiée ------------------------------------------------------------------------------------
# Installée d'office (alertes recalculées chaque heure, QL12-6), sauf -SansTachePlanifiee ; avec -Demo,
# seulement si -TachePlanifiee est demandé.
$installerTache = -not $SansTachePlanifiee -and ($TachePlanifiee -or -not $Demo)
$tacheEnregistree = $false
if ($installerTache) {
    Step 'Tâche planifiée Windows'
    # Exécutable et arguments séparés : un chemin contenant un espace reste correctement cité.
    $action = New-ScheduledTaskAction -Execute $phpExe -Argument "`"$PSScriptRoot\artisan`" schedule:run" -WorkingDirectory $PSScriptRoot
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
    try {
        Register-ScheduledTask -TaskName 'Patrimoine - planificateur' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
        $tacheEnregistree = $true
        Write-Host 'Tâche « Patrimoine - planificateur » enregistrée (chaque minute).'
    } catch {
        $message = "Création de la tâche refusée ($($_.Exception.Message)) : relancez PowerShell en administrateur, ou créez-la à la main (docs\07-deploiement-local.md)."
        # Demandée explicitement : échec ; installée d'office : avertissement, l'application reste installée.
        if ($TachePlanifiee) { Fail $message }
        Write-Warning $message
    }
}

# 7. Droits NTFS ----------------------------------------------------------------------------------------
# La tâche planifiée tourne en SYSTEM sur ce dossier : sans ACL, tout compte pouvant y écrire obtiendrait SYSTEM.
# SID au lieu des noms : indépendant de la langue de Windows.
if ($tacheEnregistree -and -not $SansDurcissement) {
    Step 'Droits du dossier d''installation'
    $racine = $PSScriptRoot
    $commandes = @(
        @($racine, '/grant:r', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-32-545:(OI)(CI)RX'),
        @($racine, '/inheritance:r')
    )
    # Données sensibles et zones d'écriture : fermées aux autres comptes (le compte Apache y écrit, ou y lit pour .env).
    $zones = @(@('storage', 'M'), @('bootstrap\cache', 'M'), @('.env', 'R'))
    foreach ($zone in $zones) {
        $chemin = Join-Path $racine $zone[0]
        if (-not (Test-Path -LiteralPath $chemin)) { continue }
        $droits = @($chemin, '/inheritance:r', '/grant:r', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F')
        if ($CompteApache -notin @('SYSTEM', 'LocalSystem', 'NT AUTHORITY\SYSTEM')) { $droits += "${CompteApache}:(OI)(CI)$($zone[1])" }
        $commandes += , $droits
    }
    $echec = $null
    foreach ($commande in $commandes) {
        & icacls @commande | Out-Null
        if ($LASTEXITCODE -ne 0) { $echec = "icacls $($commande -join ' ')"; break }
    }
    if ($echec) {
        Write-Warning "Durcissement des droits NTFS impossible (relancez en administrateur) : lancez à la main les commandes icacls décrites dans docs\07-deploiement-local.md (échec sur : $echec)."
    } else {
        Write-Host "Droits NTFS appliqués (SYSTEM et Administrateurs : contrôle total ; Utilisateurs : lecture ; storage, bootstrap\cache et .env fermés ; compte Apache : $CompteApache)."
    }
}

# 8. Pare-feu -------------------------------------------------------------------------------------------
# Règle entrante TCP, profil Privé, limitée à -ReseauAutorise ; recréée à chaque passage (idempotent).
if (-not $SansPareFeu -and ($PareFeu -or -not $Demo)) {
    Step 'Pare-feu Windows'
    $port = ([uri]$Url).Port
    try {
        Get-NetFirewallRule -DisplayName 'Patrimoine - HTTP' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
        New-NetFirewallRule -DisplayName 'Patrimoine - HTTP' -Direction Inbound -Protocol TCP -LocalPort $port -Action Allow -Profile Private -RemoteAddress $ReseauAutorise | Out-Null
        Write-Host "Règle « Patrimoine - HTTP » : TCP $port en entrée, profil Privé, depuis $ReseauAutorise."
    } catch {
        Write-Warning "Règle de pare-feu non créée ($($_.Exception.Message)). En administrateur : New-NetFirewallRule -DisplayName 'Patrimoine - HTTP' -Direction Inbound -Protocol TCP -LocalPort $port -Action Allow -Profile Private -RemoteAddress $ReseauAutorise"
    }
}

Step 'Terminé'
Write-Host "Application installée. Adresse : $(Get-EnvValue '.env' 'APP_URL')"
if (-not $tacheEnregistree) {
    Write-Host "Planificateur Windows non enregistré : relancez en administrateur (avec -TachePlanifiee pour une démonstration), ou voir docs\07-deploiement-local.md."
}
Write-Host 'Contrôle à tout moment : php artisan patrimoine:verifier'
