$ErrorActionPreference = "Stop"

$Source = "C:\Proyectos Antigravity\Vacaciones App - copia\tools\php"
$Target = "C:\Program Files\PHP\8.4.21"
$Log = "C:\tmp\php-move.log"

Start-Transcript -Path $Log -Force | Out-Null

try {
    $ResolvedSource = (Resolve-Path -LiteralPath $Source).Path
    if ($ResolvedSource -ne $Source) {
        throw "Ruta fuente inesperada: $ResolvedSource"
    }

    if (!(Test-Path -LiteralPath "$Source\php.exe")) {
        throw "No se encontro php.exe en $Source"
    }

    Write-Host "Fuente: $Source"
    Write-Host "Destino: $Target"

    if (Test-Path -LiteralPath $Target) {
        $ExistingItems = @(Get-ChildItem -LiteralPath $Target -Force)
        if ($ExistingItems.Count -gt 0) {
            throw "El destino ya existe y no esta vacio: $Target. No se sobreescribe automaticamente."
        }
        Write-Host "El destino existe pero esta vacio; se reutiliza."
    }

    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Target) | Out-Null
    New-Item -ItemType Directory -Force -Path $Target | Out-Null

    Write-Host "Copiando archivos..."
    Get-ChildItem -LiteralPath $Source -Force | Copy-Item -Destination $Target -Recurse -Force

    if (!(Test-Path -LiteralPath "$Target\php.exe")) {
        throw "La copia termino pero no se encontro php.exe en $Target"
    }

    & "$Target\php.exe" -v
    & "$Target\php.exe" -m | Select-String -Pattern "pdo_mysql|mysqli|openssl"

    Write-Host "PHP copiado correctamente a $Target"
}
finally {
    Stop-Transcript | Out-Null
}
