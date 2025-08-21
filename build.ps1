<#
    Empaquetador PowerShell para SII Boleta DTE

    Uso:
        Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope Process
        ./build.ps1

    Este script lee la versión del plugin desde la cabecera de "sii-boleta-dte.php"
    y crea un archivo ZIP dentro de la carpeta "dist".
#>

$ErrorActionPreference = 'Stop'

# Función auxiliar para leer la versión del plugin
function Get-PluginVersion {
    param(
        [string]$FilePath
    )
    $content = Get-Content -Path $FilePath -Raw
    $matches = [regex]::Matches($content, 'Version:\s*(?<ver>[0-9\.]+)')
    if ($matches.Count -gt 0) {
        return $matches[0].Groups['ver'].Value
    }
    return $null
}

$pluginFile = 'sii-boleta-dte/sii-boleta-dte.php'
$version = Get-PluginVersion -FilePath $pluginFile
if (-not $version) {
    Write-Error 'No se pudo obtener la versión del plugin. Verifica sii-boleta-dte.php.'
    exit 1
}

$distDir = 'dist'
$zipName = "sii-boleta-dte-$version.zip"

if (Test-Path $distDir) { Remove-Item -Recurse -Force $distDir }
New-Item -ItemType Directory -Path $distDir | Out-Null

Write-Host "Generando $zipName..."
Add-Type -AssemblyName 'System.IO.Compression.FileSystem'
[System.IO.Compression.ZipFile]::CreateFromDirectory('sii-boleta-dte', "${distDir}\${zipName}")
Write-Host "Archivo creado en ${distDir}\${zipName}"