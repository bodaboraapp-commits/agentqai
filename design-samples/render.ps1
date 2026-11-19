# Renders every design sample to PNG using headless Chrome.
# Run with:  pwsh -File design-samples/render.ps1
$ErrorActionPreference = 'Continue'
$chrome = "C:\Program Files\Google\Chrome\Application\chrome.exe"
$root   = "C:\xampp\htdocs\www\agentqai.cx"
$out    = Join-Path $root "design-samples\screenshots"
$profile = Join-Path $env:TEMP "dsh-chrome-render"
New-Item -ItemType Directory -Force -Path $out | Out-Null
New-Item -ItemType Directory -Force -Path $profile | Out-Null

$samples = @(
  @{ n = '01-console'; f = '01-console.html' },
  @{ n = '02-ledger';  f = '02-ledger.html'  },
  @{ n = '03-orbit';   f = '03-orbit.html'   },
  @{ n = '04-handoff'; f = '04-handoff.html' },
  @{ n = '05-north';   f = '05-north.html'   }
)

$common = @(
  '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
  '--no-first-run', '--no-default-browser-check', '--disable-breakpad',
  '--disable-crash-reporter', '--disable-background-networking',
  '--disable-dev-shm-usage', '--force-device-scale-factor=1',
  '--virtual-time-budget=2500', "--user-data-dir=$profile"
)

function Shot([string]$url, [string]$size, [string]$file) {
  $args = $common + @("--window-size=$size", "--screenshot=$file", $url)
  & $chrome @args 2>$null | Out-Null
  if (Test-Path $file) {
    $kb = [math]::Round((Get-Item $file).Length / 1KB)
    Write-Host "  ok  $([System.IO.Path]::GetFileName($file))  ${kb}KB"
  } else {
    Write-Host "  FAIL $([System.IO.Path]::GetFileName($file))"
  }
}

foreach ($s in $samples) {
  $url = 'file:///' + (Join-Path $root ("design-samples\landing\" + $s.f)).Replace('\', '/')
  Write-Host "== $($s.n)"
  Shot $url '1440,1000' (Join-Path $out "$($s.n)-desktop.png")
  Shot $url '390,844'   (Join-Path $out "$($s.n)-mobile.png")
  Shot $url '1440,6600' (Join-Path $out "$($s.n)-full.png")
}

Write-Host "== current live site"
Shot 'https://agentqai.cx/' '1440,1400' (Join-Path $out '00-current-live.png')

Write-Host "done"
